<?php

namespace App\Services\Ai;

use App\Models\CrmLead;
use App\Services\Ai\Billing\MeteredAiService;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;

/**
 * Basic AI writing for every client (owner, 2026-10-14):
 *  - a WhatsApp message for Send Notification ("No template"): written from what it is for, or an
 *    existing draft improved;
 *  - a follow-up message for a CRM lead, written from the lead's own details.
 *
 * Both go through MeteredAiService, so they are charged like every AI call (free daily allowance
 * first, then the wallet) and an accidental double-click is one charge. Only plain text comes
 * back; nothing is sent or saved here — the client edits it and sends it as usual.
 */
class MessageCopywriterService
{
    public const OPERATION_MESSAGE = 'alerts.ai_write';

    public const OPERATION_FOLLOW_UP = 'crm.ai_follow_up';

    public const LANGUAGES = ['english', 'hindi', 'hinglish'];

    private const MAX_TOKENS = 500;

    private const MAX_LENGTH = 1500;

    public function __construct(private readonly MeteredAiService $metered)
    {
    }

    /** @return array{provider: string, text: string} */
    public function message(AiAuthorization $authorization, string $operationKey, ?string $purpose, ?string $draft, ?string $tone, string $language): array
    {
        $task = $draft !== null && trim($draft) !== ''
            ? "Improve this WhatsApp message. Keep its meaning, every fact, every number and every {{placeholder}} exactly; make it clearer and friendlier.\nMessage:\n\"\"\"".$draft."\"\"\""
            : 'Write a WhatsApp message for this purpose: "'.$purpose.'".';

        return $this->write($authorization, $operationKey, self::OPERATION_MESSAGE, $task.($purpose !== null && $draft !== null && trim($draft) !== '' ? "\nWhat it is for: \"{$purpose}\"." : ''), $tone, $language);
    }

    /** @return array{provider: string, text: string} */
    public function followUp(AiAuthorization $authorization, string $operationKey, CrmLead $lead, ?string $goal, ?string $tone, string $language, ?string $businessName): array
    {
        $facts = array_filter([
            'Customer name' => $lead->contact?->name,
            'Their company' => $lead->company_name,
            'What they asked for' => $lead->requirement,
            'Budget' => $lead->budget !== null ? (string) $lead->budget : null,
            'Timeline' => $lead->timeline,
            'Lead status' => $lead->status,
            'How hot the lead is' => $lead->temperature,
            'Where the lead came from' => $lead->source,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        $lines = collect($facts)->map(fn ($v, $k) => "- {$k}: ".mb_substr((string) $v, 0, 300))->implode("\n");

        $task = "Write a short WhatsApp follow-up message from {$this->sender($businessName)} to this sales lead.\n".
            ($lines !== '' ? "What we know about the lead:\n{$lines}\n" : '').
            ($goal !== null && trim($goal) !== '' ? "Goal of this message: \"{$goal}\".\n" : "Goal: re-engage them politely and invite a reply or a short call.\n").
            'Address them by first name if known. Do not invent prices, offers or facts that are not given.';

        return $this->write($authorization, $operationKey, self::OPERATION_FOLLOW_UP, $task, $tone, $language);
    }

    /** @return array{provider: string, text: string} */
    private function write(AiAuthorization $authorization, string $operationKey, string $operation, string $task, ?string $tone, string $language): array
    {
        $result = $this->metered->generateText(
            $authorization,
            new AiRequest(
                prompt: $task."\nTone: ".($tone !== null && trim($tone) !== '' ? $tone : 'friendly and professional').".\n".$this->languageRule($language),
                system: 'You write WhatsApp messages for small businesses. Reply with ONLY the message text — no quotes around it, '.
                    'no title, no explanation, no markdown. Keep it short (under about 600 characters), natural and polite, '.
                    'at most one or two emojis, and never invent facts, prices, links or phone numbers.',
                maxTokens: self::MAX_TOKENS,
                temperature: 0.7,
                operation: $operation,
            ),
            $operationKey,
            accept: fn (AiResponse $r) => $this->clean($r->text) !== '',
        );

        return ['provider' => $result->response->provider, 'text' => $this->clean($result->response->text)];
    }

    private function languageRule(string $language): string
    {
        return match ($language) {
            'hindi' => 'Write it in Hindi (Devanagari script).',
            'hinglish' => 'Write it in Hinglish: Hindi words in Latin script, the way people chat on WhatsApp in India.',
            default => 'Write it in English.',
        };
    }

    private function sender(?string $businessName): string
    {
        return $businessName !== null && trim($businessName) !== '' ? '"'.trim($businessName).'"' : 'the business';
    }

    /** The model's text without wrapping quotes / code fences, capped. */
    private function clean(string $text): string
    {
        $text = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($text)) ?? '');
        $text = trim($text, " \t\n\r\"“”");

        return mb_substr($text, 0, self::MAX_LENGTH);
    }
}
