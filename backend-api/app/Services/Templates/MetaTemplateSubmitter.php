<?php

namespace App\Services\Templates;

use App\Models\Account;
use App\Models\MessageTemplate;
use App\Models\WhatsAppSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * QA fixes (2026-10-14) — a client's template becomes a real Meta template.
 *
 * On a Meta (Cloud API) account a business-initiated message must be an
 * APPROVED Meta template; free text is refused outside the 24-hour window.
 * Templates approved on this platform were never sent to Meta, so they went
 * out as free text and failed. Now, when the final reviewer approves a
 * template for a Meta account, it is submitted to the account's WhatsApp
 * Business Account (POST /{waba-id}/message_templates) and waits for Meta's
 * verdict (meta_template_status PENDING → APPROVED / REJECTED), which arrives
 * by webhook (message_template_status_update) or the templates:sync-meta poll.
 *
 * Body {{name}} tokens become Meta's positional {{1}}, {{2}} … in the
 * template's own variable order — the same order TemplateComponentTranslator
 * uses when sending, so the values line up. A media header needs a file
 * upload Meta does not accept by URL: such a template is refused here with a
 * clear message (create it in WhatsApp Manager and enter its name instead).
 */
class MetaTemplateSubmitter
{
    private const GRAPH_API_VERSION = 'v19.0';

    /** @return array{name: string, meta_id: ?string, status: string} */
    public function submit(MessageTemplate $template, Account $account): array
    {
        $session = WhatsAppSession::query()->where('account_id', $account->id)->first();
        $wabaId = (string) ($session?->meta_waba_id ?? '');
        $token = (string) ($session?->meta_access_token ?? '');
        if ($wabaId === '' || $token === '') {
            throw new RuntimeException('This client has not connected its Meta WhatsApp Business Account yet, so the template cannot be sent to Meta for approval. Connect Meta in WhatsApp Setup first.');
        }

        if (in_array($template->header_type, ['image', 'document'], true)) {
            throw new RuntimeException('Templates with an image or document header must be created in WhatsApp Manager (Meta needs the file uploaded there). Create it there, then enter its Meta template name here.');
        }

        $category = strtoupper((string) ($template->category ?: 'UTILITY'));
        if (! in_array($category, MessageTemplate::META_CATEGORIES, true)) {
            $category = 'UTILITY';
        }
        $language = (string) ($template->language ?: 'en_US');
        $name = $template->meta_template_name ?: self::metaNameFor($template);

        $payload = [
            'name' => $name,
            'category' => $category,
            'language' => $language,
            'components' => $this->components($template),
        ];

        try {
            $response = Http::withToken($token)->timeout(20)
                ->post('https://graph.facebook.com/'.self::GRAPH_API_VERSION."/{$wabaId}/message_templates", $payload);
        } catch (Throwable $e) {
            throw new RuntimeException('Meta could not be reached. Try approving again in a minute.');
        }

        if ($response->failed()) {
            $message = (string) ($response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'Meta refused the template.');
            throw new RuntimeException('Meta refused this template: '.$message);
        }

        $status = strtoupper((string) ($response->json('status') ?? 'PENDING'));

        return [
            'name' => $name,
            'meta_id' => $response->json('id') !== null ? (string) $response->json('id') : null,
            'status' => in_array($status, MessageTemplate::META_STATUSES, true) ? $status : 'PENDING',
        ];
    }

    /**
     * Meta's current verdict for this template, read from the WABA (used by the
     * templates:sync-meta poll when no webhook arrives). Null when unknown.
     */
    public function fetchStatus(MessageTemplate $template, Account $account): ?string
    {
        $session = WhatsAppSession::query()->where('account_id', $account->id)->first();
        if (! $session?->meta_waba_id || ! $session->meta_access_token || ! $template->meta_template_name) {
            return null;
        }

        try {
            $response = Http::withToken((string) $session->meta_access_token)->timeout(15)
                ->get('https://graph.facebook.com/'.self::GRAPH_API_VERSION."/{$session->meta_waba_id}/message_templates", [
                    'name' => $template->meta_template_name,
                    'fields' => 'name,status,language',
                ]);
        } catch (Throwable) {
            return null;
        }
        if ($response->failed()) {
            return null;
        }

        foreach ((array) $response->json('data', []) as $row) {
            if (($row['name'] ?? null) === $template->meta_template_name && (! $template->language || ($row['language'] ?? null) === $template->language)) {
                $status = strtoupper((string) ($row['status'] ?? ''));

                return in_array($status, MessageTemplate::META_STATUSES, true) ? $status : null;
            }
        }

        return null;
    }

    /** Meta names: lowercase letters, digits and underscores, unique per WABA. */
    public static function metaNameFor(MessageTemplate $template): string
    {
        $base = Str::of((string) $template->title)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(400, '')->value();

        return ($base !== '' ? $base : 'template').'_'.$template->id;
    }

    /** @return list<array<string, mixed>> */
    private function components(MessageTemplate $template): array
    {
        $bodyFields = TemplateComponentTranslator::fieldsFor($template, 'body');
        $body = (string) $template->template_body;
        $examples = [];
        foreach (array_values($bodyFields) as $index => $field) {
            $key = (string) $field['key'];
            $body = (string) preg_replace('/\{\{\s*'.preg_quote($key, '/').'\s*\}\}/', '{{'.($index + 1).'}}', $body);
            $examples[] = (string) ($field['example'] ?? $field['label'] ?? $key);
        }

        $components = [];
        if ($template->header_type === 'text' && filled($template->header_text ?? null)) {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => (string) $template->header_text];
        }
        $bodyComponent = ['type' => 'BODY', 'text' => $body];
        if ($examples !== []) {
            $bodyComponent['example'] = ['body_text' => [$examples]];
        }
        $components[] = $bodyComponent;

        return $components;
    }
}
