<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Lead;
use App\Models\SocialAccount;
use App\Services\SocialAuth\Exceptions\ProviderRequestFailed;
use App\Services\SocialAuth\Exceptions\SocialConnectionException;
use App\Services\SocialAuth\SocialConnectionService;
use App\Services\WhatsApp\DirectMessageDispatcher;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 4.
 * Unified Social Inbox: merges live Facebook Page / Instagram Messenger
 * conversations with this tenant's Lead Ad submissions (App\Models\Lead,
 * Phase 2's Instant Lead Bridge) into one thread list.
 *
 * DISCLOSED — NOT INTEGRATION-TESTED, same disclaimer as MetaAdsService/
 * CommentAutomationService: no live Meta Page/IG account is reachable
 * from this environment. Request shapes are [Hypothesis] unless marked
 * [Fact] (verified directly against this repo's own code).
 *
 * DISCLOSED SCOPE GAP: reading/sending Page Messenger conversations
 * requires the `pages_messaging` OAuth scope (Instagram equivalent:
 * `instagram_manage_messages`); neither was in MetaOAuthProvider::SCOPES
 * before this phase. Both are added now — same re-auth consequence as
 * Phase 3's `ads_management` addition: a tenant who connected their
 * Page/Instagram BEFORE this change must disconnect and reconnect before
 * this controller's Graph API calls will succeed for them.
 *
 * DISCLOSED DEVIATION: the spec's send() endpoint is written as
 * `/{thread_id}/messages`, but Meta's real Send API has no such
 * endpoint — an outbound Messenger/Instagram DM is sent via
 * `POST /{page_id}/messages` (the Send API, addressed to a recipient
 * PSID/IGSID, not a conversation id) — see send()'s docblock for how
 * this controller resolves that PSID and reaches the real endpoint.
 *
 * THREAD ID SHAPE (not a spec requirement — a necessary design decision,
 * disclosed here): since a raw Meta conversation id alone doesn't say
 * which of a tenant's connected Pages/Instagram accounts (and therefore
 * which access token) it belongs to, and building a persisted "threads"
 * table was not asked for, thread ids returned by index() are composite:
 *   "facebook:{social_account_id}:{conversation_id}"
 *   "instagram:{social_account_id}:{conversation_id}"
 *   "lead:{lead_id}"
 * messages()/send() parse this back apart — see parseThreadId(). Every
 * parse re-verifies the embedded social_account_id/lead_id belongs to
 * the CURRENTLY authenticated tenant (never trusts the id's account
 * ownership at face value), so a thread id from one tenant can never be
 * replayed to read or send as a different tenant.
 */
class SocialInboxController extends Controller
{
    use ResolvesTenantAccount;

    private const API_VERSION = 'v19.0';

    /** message_dispatch_logs.source for a WhatsApp reply to a lead thread (P5-2). */
    public const DISPATCH_SOURCE = 'social_inbox';

    /** GET /api/social/inbox/threads */
    public function threads(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        $threads = [];

        $socialAccounts = SocialAccount::query()
            ->forAccount($account->id)
            ->whereIn('asset_type', ['facebook_page', 'instagram'])
            ->get();

        $connectionIssues = [];
        $connections = app(SocialConnectionService::class);

        foreach ($socialAccounts as $socialAccount) {
            $platform = $socialAccount->asset_type === 'facebook_page' ? 'facebook' : 'instagram';

            try {
                // Phase 9 Task 2 — an expired/revoked connection is not asked
                // again; it is listed so the inbox can offer Reconnect.
                $connections->assertUsable($socialAccount, 'inbox.threads');
                $conversations = $this->fetchConversations($socialAccount);
            } catch (SocialConnectionException $e) {
                $connectionIssues[] = $this->connectionIssue($e);

                continue;
            } catch (Throwable $e) {
                if ($e instanceof ProviderRequestFailed && ($result = $connections->observe($socialAccount, $e, 'inbox.threads'))) {
                    $connectionIssues[] = $this->connectionIssue(SocialConnectionException::for($socialAccount, $result->status, $result->reason));

                    continue;
                }

                Log::warning("SocialInboxController: could not fetch {$platform} conversations for SocialAccount#{$socialAccount->id}.", [
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($conversations as $conversation) {
                $participant = $this->otherParticipant($conversation['participants']['data'] ?? [], $socialAccount->provider_id);

                $threads[] = [
                    'id' => "{$platform}:{$socialAccount->id}:{$conversation['id']}",
                    'platform' => $platform,
                    'participant_name' => $participant['name'] ?? 'Unknown',
                    'snippet' => $conversation['snippet'] ?? '',
                    'updated_at' => $conversation['updated_time'] ?? null,
                    'unread' => (int) ($conversation['unread_count'] ?? 0) > 0,
                ];
            }
        }

        foreach (Lead::query()->forAccount($account->id)->latest()->limit(50)->get() as $lead) {
            $threads[] = [
                'id' => "lead:{$lead->id}",
                'platform' => 'lead',
                'participant_name' => $lead->lead_name ?? $lead->lead_phone ?? 'Unknown lead',
                'snippet' => 'New lead form submission',
                'updated_at' => $lead->created_at?->toIso8601String(),
                'unread' => false,
            ];
        }

        usort($threads, fn (array $a, array $b) => strcmp((string) $b['updated_at'], (string) $a['updated_at']));

        return response()->json(['data' => $threads, 'connection_issues' => $connectionIssues]);
    }

    /** GET /api/social/inbox/threads/{id}/messages */
    public function messages(Request $request, string $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $parsed = $this->parseThreadId($id, $account);

        if ($parsed['type'] === 'lead') {
            return response()->json(['data' => $this->presentLeadAsMessages($parsed['lead'])]);
        }

        $socialAccount = $parsed['social_account'];

        try {
            app(SocialConnectionService::class)->assertUsable($socialAccount, 'inbox.messages');
        } catch (SocialConnectionException $e) {
            return $e->render();
        }

        try {
            $response = Http::withToken($socialAccount->access_token)->timeout(15)->get(
                'https://graph.facebook.com/'.self::API_VERSION."/{$parsed['conversation_id']}/messages",
                ['fields' => 'id,message,from,created_time']
            );
        } catch (Throwable $e) {
            return response()->json(['message' => 'Could not reach Meta to fetch this conversation.'], 502);
        }

        if ($response->failed()) {
            return $this->providerFailure($socialAccount, $response, 'Meta rejected the conversation request.', 'inbox.messages');
        }

        $messages = collect($response->json('data', []))
            ->map(fn (array $m) => [
                'id' => $m['id'],
                'from_me' => ($m['from']['id'] ?? null) === $socialAccount->provider_id,
                'text' => $m['message'] ?? '',
                'created_at' => $m['created_time'] ?? null,
            ])
            ->sortBy('created_at')
            ->values();

        return response()->json(['data' => $messages]);
    }

    /**
     * POST /api/social/inbox/send
     *
     * A `lead:` thread has no Meta conversation at all (a Lead Ad
     * submission is a form response, not a chat) — replying to one goes
     * out over WhatsApp to the lead's own submitted phone number, the
     * exact channel Phase 2's Instant Lead Bridge already established
     * for this same Lead row (welcomeLead()/notifyTenant()). A real
     * `facebook:`/`instagram:` thread sends through Meta's actual Send
     * API (POST /{page_id}/messages with a resolved recipient PSID) —
     * NOT `/{thread_id}/messages` as the literal spec names it, since
     * Meta's API has no such endpoint (see class docblock).
     */
    public function send(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        $data = $request->validate([
            'thread_id' => ['required', 'string'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $parsed = $this->parseThreadId($data['thread_id'], $account);

        if ($parsed['type'] === 'lead') {
            return $this->sendToLead($account, $parsed['lead'], $data['message']);
        }

        return $this->sendToConversation($parsed['social_account'], $parsed['conversation_id'], $data['message']);
    }

    private function sendToLead(Account $account, Lead $lead, string $message): JsonResponse
    {
        if (! $lead->lead_phone) {
            return response()->json(['message' => 'This lead has no phone number on file.'], 422);
        }

        // Phase 5 P5-2 -- this used to resolve the engine driver and send
        // through it directly: no quota was consumed and no dispatch-log
        // row was written. It now goes through the unified
        // individual-recipient path, which owns the subscription/quota
        // gate, the disconnected-QR check, phone normalization, provider
        // resolution, the single quota consumption on a confirmed send,
        // and the dispatch log on every terminal branch. No provider,
        // quota or logging logic lives here. $account is the tenant
        // resolved by requireAccount() and $lead was already scoped to it
        // by parseThreadId(); nothing from the request body chooses the
        // sending account.
        $result = DirectMessageDispatcher::dispatch(
            $account->id,
            $lead->lead_phone,
            'text',
            ['body' => $message],
            source: self::DISPATCH_SOURCE,
        );

        if ($result['status'] !== 'sent') {
            return response()->json(['message' => $result['message'] ?? 'Failed to send the WhatsApp message.'], 422);
        }

        return response()->json(['message' => 'Message sent.']);
    }

    private function sendToConversation(SocialAccount $socialAccount, string $conversationId, string $message): JsonResponse
    {
        try {
            app(SocialConnectionService::class)->assertUsable($socialAccount, 'inbox.send');
        } catch (SocialConnectionException $e) {
            return $e->render();
        }

        try {
            $conversation = Http::withToken($socialAccount->access_token)->timeout(15)->get(
                'https://graph.facebook.com/'.self::API_VERSION."/{$conversationId}",
                ['fields' => 'participants']
            );
        } catch (Throwable $e) {
            return response()->json(['message' => 'Could not reach Meta to resolve this conversation.'], 502);
        }

        if ($conversation->failed()) {
            return $this->providerFailure($socialAccount, $conversation, 'Meta rejected the conversation lookup.', 'inbox.send');
        }

        $participant = $this->otherParticipant($conversation->json('participants.data', []), $socialAccount->provider_id);

        if (! $participant) {
            return response()->json(['message' => 'Could not resolve the recipient for this conversation.'], 422);
        }

        try {
            $response = Http::asForm()->withToken($socialAccount->access_token)->timeout(15)->post(
                'https://graph.facebook.com/'.self::API_VERSION."/{$socialAccount->provider_id}/messages",
                [
                    'recipient' => json_encode(['id' => $participant['id']]),
                    'messaging_type' => 'RESPONSE',
                    'message' => json_encode(['text' => $message]),
                ]
            );
        } catch (Throwable $e) {
            return response()->json(['message' => 'Could not reach Meta to send the message.'], 502);
        }

        if ($response->failed()) {
            return $this->providerFailure($socialAccount, $response, 'Meta rejected the message.', 'inbox.send');
        }

        return response()->json(['message' => 'Message sent.']);
    }

    /**
     * Phase 9 Task 2 — a failed Meta call: an expired/revoked connection is
     * persisted and answered with the safe 409 (reconnect path); any other
     * rejection keeps its previous 422 response.
     */
    private function providerFailure(SocialAccount $socialAccount, HttpResponse $response, string $fallback, string $operation): JsonResponse
    {
        $failure = ProviderRequestFailed::fromResponse($response, $fallback);

        if ($result = app(SocialConnectionService::class)->observe($socialAccount, $failure, $operation)) {
            return SocialConnectionException::for($socialAccount, $result->status, $result->reason)->render();
        }

        return response()->json(['message' => $failure->getMessage()], 422);
    }

    /** @return array{social_account_id: int, asset_type: string, name: string|null, connection_status: string, message: string, reconnect_path: string} */
    private function connectionIssue(SocialConnectionException $e): array
    {
        return [
            'social_account_id' => $e->socialAccountId,
            'asset_type' => $e->assetType,
            'name' => $e->assetName,
            'connection_status' => $e->connectionStatus,
            'message' => $e->getMessage(),
            'reconnect_path' => SocialConnectionException::RECONNECT_PATH,
        ];
    }

    /**
     * @return array<int, array{id: string, participants: array{data: array<int, array<string, mixed>>}, snippet?: string, updated_time?: string, unread_count?: int}>
     */
    private function fetchConversations(SocialAccount $socialAccount): array
    {
        if (! $socialAccount->access_token) {
            return [];
        }

        $response = Http::withToken($socialAccount->access_token)->timeout(15)->get(
            'https://graph.facebook.com/'.self::API_VERSION."/{$socialAccount->provider_id}/conversations",
            ['fields' => 'participants,snippet,updated_time,unread_count', 'limit' => 25]
        );

        if ($response->failed()) {
            throw ProviderRequestFailed::fromResponse($response, 'Meta rejected the conversations request.');
        }

        return $response->json('data', []);
    }

    /**
     * @param array<int, array<string, mixed>> $participants
     * @return array<string, mixed>|null
     */
    private function otherParticipant(array $participants, string $ownProviderId): ?array
    {
        foreach ($participants as $participant) {
            if (($participant['id'] ?? null) !== $ownProviderId) {
                return $participant;
            }
        }

        return null;
    }

    /**
     * @return array{type: 'lead', lead: Lead}|array{type: 'conversation', social_account: SocialAccount, conversation_id: string}
     */
    private function parseThreadId(string $threadId, Account $account): array
    {
        if (str_starts_with($threadId, 'lead:')) {
            $leadId = (int) substr($threadId, 5);
            $lead = Lead::query()->forAccount($account->id)->find($leadId);

            if (! $lead) {
                throw ValidationException::withMessages(['thread_id' => 'This lead thread does not exist.']);
            }

            return ['type' => 'lead', 'lead' => $lead];
        }

        $parts = explode(':', $threadId, 3);

        if (count($parts) !== 3 || ! in_array($parts[0], ['facebook', 'instagram'], true)) {
            throw ValidationException::withMessages(['thread_id' => 'Invalid thread id.']);
        }

        [$platform, $socialAccountId, $conversationId] = $parts;

        $socialAccount = SocialAccount::query()
            ->where('id', (int) $socialAccountId)
            ->where('account_id', $account->id)
            ->first();

        if (! $socialAccount) {
            // Deliberately the SAME generic message as the "does not
            // exist" branch above — never confirms/denies that a
            // social_account_id belongs to ANOTHER tenant.
            throw ValidationException::withMessages(['thread_id' => 'This conversation thread does not exist.']);
        }

        return ['type' => 'conversation', 'social_account' => $socialAccount, 'conversation_id' => $conversationId];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentLeadAsMessages(Lead $lead): array
    {
        $lines = ["New lead from Meta Ads."];

        if ($lead->lead_name) {
            $lines[] = "Name: {$lead->lead_name}";
        }
        if ($lead->lead_phone) {
            $lines[] = "Phone: {$lead->lead_phone}";
        }
        if ($lead->lead_email) {
            $lines[] = "Email: {$lead->lead_email}";
        }

        return [[
            'id' => "lead:{$lead->id}",
            'from_me' => false,
            'text' => implode("\n", $lines),
            'created_at' => $lead->created_at?->toIso8601String(),
        ]];
    }
}
