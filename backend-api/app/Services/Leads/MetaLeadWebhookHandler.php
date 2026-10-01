<?php

namespace App\Services\Leads;

use App\Models\Account;
use App\Models\Lead;
use App\Models\SocialAccount;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\SocialAuth\Exceptions\ProviderRequestFailed;
use App\Services\SocialAuth\Exceptions\SocialConnectionException;
use App\Services\SocialAuth\SocialConnectionService;
use App\Services\SocialAuth\WebhookAssetResolver;
use App\Services\WhatsApp\DirectMessageDispatcher;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 2.
 * Instant Lead Bridge: turns one `leadgen` change from a Meta webhook
 * entry into a persisted Lead row, then fires two WhatsApp sends.
 *
 * DELIBERATELY SYNCHRONOUS, not queued — same call-site shape as
 * MetaWebhookController::handle() (this codebase's only other webhook
 * processor), and "Instant" is this feature's own stated design intent.
 * DISCLOSED TRADE-OFF: this does up to three outbound HTTP calls (one
 * Graph API fetch, two WhatsApp sends) inside the webhook request, which
 * risks Meta timing out and retrying the delivery. That retry is made
 * SAFE (not just harmless) by `leads.provider_lead_id` being unique —
 * see handle()'s early-exit below — but a production deployment should
 * still move this behind a queued job (ProcessPaymentAlertJob is the
 * existing template for that) once lead volume matters enough for
 * webhook latency to be a real risk.
 *
 * DISCLOSED INTERPRETATION: "Tenant's configured notification number"
 * (the spec's phrase) is not a concept that exists anywhere else in this
 * codebase yet — there is no dedicated lead-notification-number field.
 * This uses Account::$primary_phone (the account's existing plain
 * contact-number column) as the closest existing fit rather than adding
 * a new schema concept for one Phase 2 feature. If that's the wrong
 * target in practice, the fix is a one-line change to
 * resolveTenantNotificationNumber() below plus a new Account column —
 * flagged in the audit report for confirmation, not guessed silently.
 *
 * DISCLOSED LIMITATION: Meta Lead Ads forms can rename/relabel their
 * fields freely, so mapping Graph API `field_data` entries to
 * name/phone/email is a best-effort match on the field's `name` key
 * (containing "phone"/"name"/"email") rather than a guaranteed schema.
 * `raw_field_data` on the Lead row always keeps everything verbatim so
 * no data is lost even when this heuristic misses a custom field.
 */
class MetaLeadWebhookHandler
{
    private const API_VERSION = 'v18.0';

    /**
     * Phase 5 P5-A -- the message_dispatch_logs.source written for both
     * WhatsApp sends this handler makes (tenant notice + lead welcome).
     */
    public const DISPATCH_SOURCE = 'meta_lead_ads';

    /**
     * @param array<string, mixed> $entry One element of the webhook
     *        payload's top-level `entry` array (see SocialWebhookController).
     */
    public function handle(array $entry): void
    {
        foreach ($entry['changes'] ?? [] as $change) {
            if (($change['field'] ?? null) !== 'leadgen') {
                continue;
            }

            $this->handleLeadgenChange($change['value'] ?? []);
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function handleLeadgenChange(array $value): void
    {
        $leadgenId = $value['leadgen_id'] ?? null;
        $pageId = $value['page_id'] ?? null;

        if (! $leadgenId || ! $pageId) {
            Log::warning('MetaLeadWebhookHandler: leadgen change missing leadgen_id/page_id.', ['value' => $value]);

            return;
        }

        // Technical redelivery guard — see the leads migration's docblock.
        // Cheap, and runs before any external HTTP call.
        if (Lead::where('provider_lead_id', $leadgenId)->exists()) {
            return;
        }

        // Phase 9 Task 2.2 — the Page's owner is resolved without guessing:
        // a Page connected by more than one account is not processed
        // (logged + audited for manual resolution) instead of being given
        // to an arbitrary tenant. See WebhookAssetResolver.
        $resolution = app(WebhookAssetResolver::class)->resolve('meta', 'facebook_page', (string) $pageId, 'leadgen', ['leadgen_id' => (string) $leadgenId]);
        $socialAccount = $resolution['account'];

        if ($resolution['status'] === WebhookAssetResolver::AMBIGUOUS) {
            return;
        }

        if (! $socialAccount) {
            Log::warning("MetaLeadWebhookHandler: no tenant has Page {$pageId} connected — lead dropped.", [
                'leadgen_id' => $leadgenId,
            ]);

            return;
        }

        $account = Account::findCached($socialAccount->account_id);

        if (! $account) {
            Log::warning("MetaLeadWebhookHandler: SocialAccount#{$socialAccount->id} points at a missing Account.");

            return;
        }

        $fieldData = $this->fetchLeadFieldData($leadgenId, $socialAccount);

        if ($fieldData === null) {
            // fetchLeadFieldData() already logged the specific failure.
            return;
        }

        $extracted = $this->extractFields($fieldData);
        $normalizedPhone = $extracted['phone'] !== null ? PhoneNumberNormalizer::normalize($extracted['phone']) : '';

        if ($normalizedPhone !== '' && Lead::isDuplicatePhoneWithin24Hours($account->id, $normalizedPhone)) {
            Log::info("MetaLeadWebhookHandler: duplicate lead phone for account #{$account->id} within 24h — skipped.", [
                'leadgen_id' => $leadgenId,
            ]);

            return;
        }

        $lead = Lead::create([
            'account_id' => $account->id,
            'social_account_id' => $socialAccount->id,
            'provider' => 'meta',
            'provider_lead_id' => (string) $leadgenId,
            'form_id' => $value['form_id'] ?? null,
            'ad_id' => $value['ad_id'] ?? null,
            'lead_name' => $extracted['name'],
            'lead_phone' => $normalizedPhone !== '' ? $normalizedPhone : null,
            'lead_email' => $extracted['email'],
            'raw_field_data' => $fieldData,
        ]);

        /*
         * Phase 6 CRM Hardening (Issue 5) — promote this capture into
         * the CRM as a `meta_ad` lead. Deliberately placed AFTER the
         * capture row is committed and BEFORE the two WhatsApp sends, so
         * the CRM record exists even if a send later fails.
         *
         * linkQuietly, never link: this handler is synchronous inside a
         * Meta webhook and Meta retries any non-2xx response, so an
         * exception escaping here would turn an already-persisted
         * capture into an endless redelivery loop. A CRM failure is
         * logged and the row stays unlinked for the backfill to pick up;
         * every line of the existing capture and notify behaviour below
         * is unchanged.
         */
        app(CaptureLeadLinker::class)->linkQuietly($lead);

        $this->notifyTenant($account, $lead, $extracted);
        $this->welcomeLead($account, $lead, $normalizedPhone, $extracted);
    }

    /**
     * @return array<int, array{name: string, values: list<string>}>|null
     */
    private function fetchLeadFieldData(string $leadgenId, SocialAccount $socialAccount): ?array
    {
        $pageAccessToken = $socialAccount->access_token;

        if (! $pageAccessToken) {
            Log::warning("MetaLeadWebhookHandler: leadgen {$leadgenId} — no Page Access Token stored for this SocialAccount.");

            return null;
        }

        // Phase 9 Task 2.1 — the Page connection that owns this webhook entry
        // (resolved from the signed payload's page_id, never from input) is
        // not asked again once it is known to be expired/revoked. This runs
        // inside Meta's webhook request, which must still answer 200 (a
        // non-2xx would make Meta redeliver forever), so the refusal is
        // logged with its safe code rather than returned as a 409.
        $connections = app(SocialConnectionService::class);

        try {
            $connections->assertUsable($socialAccount, 'leads.fetch');
        } catch (SocialConnectionException $e) {
            Log::warning("MetaLeadWebhookHandler: leadgen {$leadgenId} not fetched — the Page connection needs reconnecting.", [
                'social_account_id' => $socialAccount->id,
                'error_code' => $e->errorCode(),
            ]);

            return null;
        }

        try {
            $response = Http::withToken($pageAccessToken)->timeout(15)->get(
                'https://graph.facebook.com/'.self::API_VERSION."/{$leadgenId}",
                ['fields' => 'field_data']
            );
        } catch (Throwable $e) {
            Log::warning("MetaLeadWebhookHandler: could not reach Meta to fetch leadgen {$leadgenId}.", [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            // Phase 9 Task 2.1 — an expired/revoked Page token is persisted on
            // the connection (the provider driver classifies the error); any
            // other rejection (rate limit, 5xx, validation) changes nothing.
            $result = $connections->observe($socialAccount, ProviderRequestFailed::fromResponse($response, 'Meta rejected the leadgen fetch.'), 'leads.fetch');

            if ($result) {
                Log::warning("MetaLeadWebhookHandler: leadgen {$leadgenId} not fetched — the Page connection is {$result->status}.", [
                    'social_account_id' => $socialAccount->id,
                    'error_code' => SocialConnectionException::for($socialAccount, $result->status)->errorCode(),
                ]);

                return null;
            }

            Log::warning("MetaLeadWebhookHandler: Meta rejected the leadgen {$leadgenId} fetch.", [
                'error' => $response->json('error.message'),
            ]);

            return null;
        }

        return $response->json('field_data', []);
    }

    /**
     * @param array<int, array{name: string, values: list<string>}> $fieldData
     * @return array{name: ?string, phone: ?string, email: ?string}
     */
    private function extractFields(array $fieldData): array
    {
        $result = ['name' => null, 'phone' => null, 'email' => null];

        foreach ($fieldData as $field) {
            $key = strtolower((string) ($field['name'] ?? ''));
            $value = $field['values'][0] ?? null;

            if ($value === null) {
                continue;
            }

            if ($result['phone'] === null && str_contains($key, 'phone')) {
                $result['phone'] = $value;
            } elseif ($result['email'] === null && str_contains($key, 'email')) {
                $result['email'] = $value;
            } elseif ($result['name'] === null && str_contains($key, 'name')) {
                $result['name'] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array{name: ?string, phone: ?string, email: ?string} $extracted
     */
    private function notifyTenant(Account $account, Lead $lead, array $extracted): void
    {
        $notificationNumber = $account->primary_phone;

        if (! $notificationNumber) {
            $lead->forceFill(['tenant_notify_error' => 'No notification number configured for this account.'])->save();

            return;
        }

        $normalized = PhoneNumberNormalizer::normalize($notificationNumber);

        if ($normalized === '') {
            $lead->forceFill(['tenant_notify_error' => "Configured notification number '{$notificationNumber}' is not valid."])->save();

            return;
        }

        $message = sprintf(
            "New lead from your Meta Ads campaign!\nName: %s\nPhone: %s\nEmail: %s",
            $extracted['name'] ?? 'N/A',
            $extracted['phone'] ?? 'N/A',
            $extracted['email'] ?? 'N/A',
        );

        $result = $this->send($account, $normalized, $message);

        if (! empty($result['success'])) {
            $lead->forceFill(['tenant_notified_at' => now()])->save();
        } else {
            $lead->forceFill(['tenant_notify_error' => $result['error'] ?? 'Send failed.'])->save();
        }
    }

    /**
     * @param array{name: ?string, phone: ?string, email: ?string} $extracted
     */
    private function welcomeLead(Account $account, Lead $lead, string $normalizedLeadPhone, array $extracted): void
    {
        if ($normalizedLeadPhone === '') {
            $lead->forceFill(['lead_welcome_error' => 'Lead submitted no usable phone number.'])->save();

            return;
        }

        $message = sprintf(
            "Hi %s, thanks for your interest! We've received your details and someone from our team will reach out shortly.",
            $extracted['name'] ?? 'there',
        );

        $result = $this->send($account, $normalizedLeadPhone, $message);

        if (! empty($result['success'])) {
            $lead->forceFill(['lead_welcomed_at' => now()])->save();
        } else {
            $lead->forceFill(['lead_welcome_error' => $result['error'] ?? 'Send failed.'])->save();
        }
    }

    /**
     * Phase 5 P5-A -- this used to resolve the engine driver and call
     * sendMessage() on it directly: no quota was consumed and no
     * dispatch-log row was written, so every Lead Ads notice/welcome was
     * a free, unaudited send. It now goes through the unified
     * individual-recipient path (the same one P5-2 moved the Social
     * Inbox lead reply onto), which owns the subscription/quota gate,
     * the disconnected-QR check, phone normalization, provider
     * resolution, the single quota consumption on a confirmed send and
     * the dispatch log on every terminal branch. No provider, quota or
     * logging logic lives here. $account is the tenant that owns the
     * connected Page (resolved in handleLeadgenChange()); nothing from
     * the webhook payload chooses the sending account.
     *
     * @return array{success: bool, error?: string}
     */
    private function send(Account $account, string $normalizedPhone, string $message): array
    {
        $result = DirectMessageDispatcher::dispatch(
            $account->id,
            $normalizedPhone,
            'text',
            ['body' => $message],
            source: self::DISPATCH_SOURCE,
        );

        if ($result['status'] === 'sent') {
            return ['success' => true];
        }

        return ['success' => false, 'error' => $result['message'] ?? 'Send failed.'];
    }
}
