<?php

namespace App\Services\Ai\Agents\Tools;

use App\Models\Account;
use App\Models\AiAgent;
use App\Models\AiAgentVersion;
use App\Models\User;
use App\Models\WhatsAppFlowSession;
use App\Support\PhoneNumberNormalizer;

/**
 * Phase 8 Task 11 — who and what a tool call runs for. Built by the
 * executing service from TRUSTED state only (the Journey session row, the
 * resolved agent version) — never from model output or request input.
 *
 *   account       the target account (the session's own account)
 *   agent/version the agent executing, already resolved inside that account
 *   actor         the acting user, or null on an automated path (Journey)
 *   session       the Journey session, when there is one: the ONLY
 *                 conversation a tool may act on (its customer's phone)
 */
final class ToolContext
{
    public function __construct(
        public readonly Account $account,
        public readonly AiAgent $agent,
        public readonly AiAgentVersion $version,
        public readonly ?User $actor = null,
        public readonly ?WhatsAppFlowSession $session = null,
        public readonly string $source = 'journey',
    ) {
    }

    /** The current conversation's customer phone (digits), or null when there is no conversation. */
    public function contactPhone(): ?string
    {
        $phone = $this->session?->phone_number;
        $normalized = is_string($phone) ? PhoneNumberNormalizer::normalize($phone) : '';

        return $normalized === '' ? null : $normalized;
    }

    /**
     * The session's variables (Journey `{{ }}` values) — '@' keys are
     * internal state and never exposed.
     *
     * @return array<string, mixed>
     */
    public function variables(): array
    {
        $context = is_array($this->session?->context_data) ? $this->session->context_data : [];

        return array_filter($context, fn ($key) => is_string($key) && ! str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY);
    }
}
