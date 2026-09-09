<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\WebhookSubscription;
use App\Services\Webhooks\WebhookSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Module 9, requirement 3 — tenant Developer Portal webhook subscription
 * management. `secret` is write-only from the client's point of view:
 * returned in full only from store() (once), then masked in every other
 * response — the same "never re-expose a saved secret" posture as this
 * codebase's Meta access token and Module 8's gateway credentials.
 *
 * UI Standardization — Graceful Super Admin Fallback: same treatment as
 * ApiKeyController — see that controller's docblock.
 */
class WebhookSubscriptionController extends Controller
{
    use ResolvesTenantAccount;

    /** GET /api/developer/webhooks */
    public function index(Request $request): JsonResponse
    {
        $account = $this->resolveAccount($request);

        if (! $account) {
            $subscriptions = WebhookSubscription::whereNotNull('account_id')
                ->with('account:id,company_name')
                ->latest('id')
                ->get()
                ->map(fn (WebhookSubscription $s) => $this->present($s, includeAccount: true));

            return response()->json(['data' => $subscriptions, 'scope' => 'global']);
        }

        $subscriptions = WebhookSubscription::forAccount($account->id)
            ->latest('id')
            ->get()
            ->map(fn (WebhookSubscription $s) => $this->present($s));

        return response()->json(['data' => $subscriptions, 'scope' => 'account']);
    }

    /**
     * POST /api/developer/webhooks — `secret` is optional; if omitted, a
     * cryptographically random one is generated so tenants who don't
     * bring their own still get a strong signing secret.
     */
    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to register a webhook for (pass ?account_id=).');

        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'secret' => ['nullable', 'string', 'min:8', 'max:255'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookSubscription::SUPPORTED_EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $plainTextSecret = $data['secret'] ?? Str::random(40);

        $subscription = WebhookSubscription::create([
            'account_id' => $account->id,
            'url' => $data['url'],
            'secret' => $plainTextSecret,
            'events' => array_values(array_unique($data['events'])),
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Webhook registered. Copy the secret now — it will not be shown again.',
            'plain_text_secret' => $plainTextSecret,
            'webhook' => $this->present($subscription),
        ], 201);
    }

    /** DELETE /api/developer/webhooks/{id} — hard delete; deliveries cascade with it. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to manage its webhooks (pass ?account_id=).');

        $subscription = WebhookSubscription::forAccount($account->id)->find($id);
        abort_if(! $subscription, 404, 'Webhook subscription not found.');

        $subscription->delete();

        return response()->json(['message' => 'Webhook subscription deleted.']);
    }

    /**
     * POST /api/developer/webhooks/{id}/test — fires a synthetic
     * 'webhook.test' event SYNCHRONOUSLY (not queued) so the tenant gets
     * an immediate pass/fail in the response, instead of having to poll
     * the delivery log after a queue worker eventually picks it up.
     * Shares WebhookSender with the real, queued DispatchWebhookJob path,
     * so the signature the tenant validates here is produced identically
     * to a real event's.
     */
    public function test(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to manage its webhooks (pass ?account_id=).');

        $subscription = WebhookSubscription::forAccount($account->id)->find($id);
        abort_if(! $subscription, 404, 'Webhook subscription not found.');

        $result = WebhookSender::send($subscription, 'webhook.test', [
            'message' => 'This is a test event from your WhatsApp SaaS Platform Developer Portal.',
            'sent_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'message' => $result['success']
                ? 'Test webhook delivered successfully.'
                : ('Test webhook failed: '.($result['error'] ?? 'unknown error')),
            'success' => $result['success'],
            'status_code' => $result['status_code'],
        ], $result['success'] ? 200 : 502);
    }

    /**
     * GET /api/developer/webhooks/{id}/deliveries — recent delivery log
     * for one subscription, newest first. Not in the spec's literal
     * endpoint list, but required to actually render "view recent
     * delivery logs" (Part 2, frontend spec) — disclosed addition.
     */
    public function deliveries(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to manage its webhooks (pass ?account_id=).');

        $subscription = WebhookSubscription::forAccount($account->id)->find($id);
        abort_if(! $subscription, 404, 'Webhook subscription not found.');

        $deliveries = $subscription->deliveries()->latest('id')->limit(50)->get();

        return response()->json(['data' => $deliveries]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WebhookSubscription $subscription, bool $includeAccount = false): array
    {
        return [
            'id' => $subscription->id,
            'account_id' => $subscription->account_id,
            'url' => $subscription->url,
            'events' => $subscription->events,
            'is_active' => $subscription->is_active,
            'secret_set' => true,
            'created_at' => $subscription->created_at,
            'updated_at' => $subscription->updated_at,
            ...($includeAccount ? ['account' => $subscription->account] : []),
        ];
    }
}
