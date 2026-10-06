<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ScheduledMessage;
use App\Services\Scheduling\ScheduledMessageException;
use App\Services\Scheduling\ScheduledMessageService;
use Carbon\Carbon;
use App\Http\Requests\SendMessageRequest;
use App\Http\Requests\SendTemplateByCodeRequest;
use App\Models\ContactGroup;
use App\Models\MessageTemplate;
use App\Services\Groups\GroupDirectMessageDispatcher;
use App\Services\Groups\GroupMessageDispatcher;
use App\Services\Templates\TemplateMessageDispatcher;
use App\Services\WhatsApp\DirectMessageDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dynamic Templates & Variables System — external Developer API.
 * POST /api/v1/messages/send-template, authenticated by AuthenticateApiKey
 * (X-API-KEY or Bearer <client_api_key>), exactly like
 * ExternalAlertController::sendPaymentAlert(). Shares
 * TemplateMessageDispatcher with the internal Send Alert dynamic form
 * (MessageTemplateController::send()) so both entry points enforce the
 * exact same approval/quota/variable rules.
 */
class TemplateMessageController extends Controller
{
    /**
     * POST /api/v1/messages/send-template
     *
     * Developer API: unique `template_code` -- identifies the template
     * by its human-readable template_code instead of the raw DB
     * `template_id`, scoped strictly to this API key's own account_id
     * (or a global/null-account_id system template). A template_code
     * that doesn't resolve under that scope -- wrong code, or a real
     * code that belongs to a different tenant -- gets the exact same
     * generic 404 either way, so this response can never be used to
     * enumerate another tenant's template codes.
     */
    public function send(SendTemplateByCodeRequest $request): JsonResponse
    {
        $accountId = $request->attributes->get('api_account_id');
        abort_if(! $accountId, 401, 'Unauthenticated.');

        $apiKey = $request->attributes->get('api_key');
        $data = $request->validated();

        // Optional send time: store the message and return at once; it is sent when it is due.
        $scheduledAt = $this->scheduledAt($data);

        // "No template" sentinel (owner request 2026-10-05, see MessageTemplate::NO_TEMPLATE_CODE) --
        // sends $data['text'] as plain text (DirectMessageDispatcher), skipping the template lookup
        // and variable substitution entirely. Same response envelope as the template path below.
        if ($data['template_code'] === MessageTemplate::NO_TEMPLATE_CODE) {
            $content = $this->directContent($data);

            if ($scheduledAt !== null) {
                return $this->scheduledResponse(
                    (int) $accountId,
                    ScheduledMessage::KIND_DIRECT_TEXT,
                    ['recipient_phone' => $data['recipient_phone'], 'message_type' => $content['message_type'], 'content' => $content['content']],
                    $scheduledAt,
                    $apiKey?->id,
                );
            }

            $result = DirectMessageDispatcher::dispatch(
                (int) $accountId,
                $data['recipient_phone'],
                $content['message_type'],
                $content['content'],
                source: 'api',
                apiKeyId: $apiKey?->id,
            );

            return match ($result['status']) {
                'sent' => response()->json(['status' => true, 'message' => 'Message sent.']),
                'not_found' => response()->json(['status' => false, 'message' => $result['message']], 404),
                'disconnected' => response()->json(['status' => false, 'message' => $result['message'], 'error_code' => 'WHATSAPP_DISCONNECTED'], 422),
                'quota_exhausted' => response()->json(['status' => false, 'message' => $result['message']], 403),
                default => response()->json(['status' => false, 'message' => $result['message'] ?? 'Could not send this message.'], 422),
            };
        }

        $template = MessageTemplate::query()
            ->where('template_code', $data['template_code'])
            ->where(function ($query) use ($accountId) {
                $query->where('account_id', $accountId)
                    ->orWhereNull('account_id');
            })
            ->first();

        if (! $template) {
            return response()->json(['status' => false, 'message' => 'Invalid template_code for this account'], 404);
        }

        if ($scheduledAt !== null) {
            return $this->scheduledResponse(
                (int) $accountId,
                ScheduledMessage::KIND_TEMPLATE_INDIVIDUAL,
                [
                    'template_id' => $template->id,
                    'recipient_phone' => $data['recipient_phone'],
                    'variables' => $data['variables'] ?? [],
                    'media_url' => $data['media_url'] ?? null,
                ],
                $scheduledAt,
                $apiKey?->id,
            );
        }

        $result = TemplateMessageDispatcher::dispatch(
            (int) $accountId,
            $template->id,
            $data['recipient_phone'],
            $data['variables'] ?? [],
            source: 'api',
            apiKeyId: $apiKey?->id,
            mediaUrl: $data['media_url'] ?? null,
        );

        return match ($result['status']) {
            'sent' => response()->json(['status' => true, 'message' => 'Message sent.']),
            'missing_variables' => response()->json(['status' => false, 'message' => $result['message'], 'missing' => $result['missing']], 422),
            'not_found' => response()->json(['status' => false, 'message' => $result['message']], 404),
            'disconnected' => response()->json(['status' => false, 'message' => $result['message'], 'error_code' => 'WHATSAPP_DISCONNECTED'], 422),
            'quota_exhausted' => response()->json(['status' => false, 'message' => $result['message']], 403),
            default => response()->json(['status' => false, 'message' => $result['message'] ?? 'Could not send this message.'], 422),
        };
    }

    /** DELETE /api/v1/scheduled-messages/{id} — cancels a scheduled message that has not been sent yet. */
    public function cancelScheduled(Request $request, int $id): JsonResponse
    {
        $accountId = $request->attributes->get('api_account_id');
        abort_if(! $accountId, 401, 'Unauthenticated.');

        try {
            $row = app(ScheduledMessageService::class)->cancel(Account::query()->findOrFail((int) $accountId), $id);
        } catch (ScheduledMessageException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
        }

        return response()->json(['status' => true, 'message' => 'Scheduled message cancelled.', 'data' => ['id' => $row->id, 'status' => $row->status]]);
    }

    /**
     * The optional send time as a Carbon. A value without its own zone is Indian Standard Time.
     * Null when the send is immediate.
     */
    private function scheduledAt(array $data): ?Carbon
    {
        return ScheduledMessageService::parseSendAt($data['scheduled_at'] ?? null);
    }

    /** Stores a scheduled message and answers 202 with its id and the time it will be sent. */
    private function scheduledResponse(int $accountId, string $kind, array $payload, Carbon $sendAt, ?int $apiKeyId): JsonResponse
    {
        try {
            $row = app(ScheduledMessageService::class)->schedule(
                Account::query()->findOrFail($accountId),
                $kind,
                $payload,
                $sendAt,
                'api',
                $apiKeyId,
            );
        } catch (ScheduledMessageException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
        }

        return response()->json([
            'status' => true,
            'message' => 'Message scheduled.',
            'data' => ['id' => $row->id, 'status' => $row->status, 'send_at' => $row->send_at->toIso8601String()],
        ], 202);
    }

    /**
     * Group Messaging Phase 4 — POST /api/v1/send-message. The
     * recipient_type-aware sibling of send() above: 'individual' delegates
     * straight to the exact same TemplateMessageDispatcher this
     * controller's send() action already uses (nothing duplicated or
     * forked for a single recipient), 'group' delegates to
     * GroupMessageDispatcher, the new atomic-quota-reservation +
     * ProcessGroupDispatchJob path. This one action owns only request
     * parsing and result-to-HTTP-response mapping; every actual business
     * rule lives in one of those two dispatchers.
     *
     * [Refactor, disclosed]: identifies the template by `template_code`
     * and, for a group send, the group by `group_code` -- the same
     * human-readable-identifier convention send() above already
     * established for templates, now extended to ContactGroup via
     * generateGroupCode()/the add_group_code_to_contact_groups_table
     * migration. Resolved ONCE here (shared by both recipient types)
     * since both already need the template row regardless of
     * recipient_type; group_code is resolved separately inside
     * sendToGroup() only, since it's irrelevant to an individual send.
     * TemplateMessageDispatcher and GroupMessageDispatcher below are
     * UNCHANGED -- both still take the raw integer id internally; only
     * this controller's own request parsing changed.
     */
    public function sendMessage(SendMessageRequest $request): JsonResponse
    {
        $accountId = $request->attributes->get('api_account_id');
        abort_if(! $accountId, 401, 'Unauthenticated.');

        $apiKey = $request->attributes->get('api_key');
        $data = $request->validated();

        // "No template" sentinel -- same idea as send() above, branching on recipient_type the same
        // way the template path below does (one call per request; individual synchronous, group queued).
        if ($data['template_code'] === MessageTemplate::NO_TEMPLATE_CODE) {
            return $data['recipient_type'] === 'group'
                ? $this->sendDirectToGroup((int) $accountId, $apiKey?->id, $data)
                : $this->sendDirectToIndividual((int) $accountId, $apiKey?->id, $data);
        }

        $template = MessageTemplate::query()
            ->where('template_code', $data['template_code'])
            ->where(function ($query) use ($accountId) {
                $query->where('account_id', $accountId)
                    ->orWhereNull('account_id');
            })
            ->first();

        if (! $template) {
            return response()->json(['success' => false, 'error_code' => 'TEMPLATE_NOT_APPROVED', 'message' => 'Invalid template_code for this account.'], 404);
        }

        $scheduledAt = $this->scheduledAt($data);
        if ($scheduledAt !== null) {
            return $this->scheduleTemplateSend((int) $accountId, $apiKey?->id, $template, $data, $scheduledAt);
        }

        return $data['recipient_type'] === 'group'
            ? $this->sendToGroup((int) $accountId, $apiKey?->id, $template, $data)
            : $this->sendToIndividual((int) $accountId, $apiKey?->id, $template, $data);
    }

    /**
     * Stores a template send for later, individual or group. The group is resolved now so a wrong
     * group_code is refused at once, not hours later when the message is due.
     *
     * @param array<string, mixed> $data
     */
    private function scheduleTemplateSend(int $accountId, ?int $apiKeyId, MessageTemplate $template, array $data, Carbon $sendAt): JsonResponse
    {
        if ($data['recipient_type'] === 'group') {
            $group = $this->resolveGroupByCode($accountId, $data['group_code']);

            if (! $group) {
                return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Invalid group_code for this account.'], 404);
            }

            return $this->scheduledResponse(
                $accountId,
                ScheduledMessage::KIND_TEMPLATE_GROUP,
                ['group_id' => $group->id, 'template_id' => $template->id, 'variables' => $data['variables'] ?? []],
                $sendAt,
                $apiKeyId,
            );
        }

        return $this->scheduledResponse(
            $accountId,
            ScheduledMessage::KIND_TEMPLATE_INDIVIDUAL,
            [
                'template_id' => $template->id,
                'recipient_phone' => $data['recipient_phone'],
                'variables' => $data['variables'] ?? [],
                'media_url' => $data['media_url'] ?? null,
            ],
            $sendAt,
            $apiKeyId,
        );
    }

    /**
     * @param array{recipient_phone: string, variables?: array<string, string>, media_url?: string} $data
     */
    private function sendToIndividual(int $accountId, ?int $apiKeyId, MessageTemplate $template, array $data): JsonResponse
    {
        $result = TemplateMessageDispatcher::dispatch(
            $accountId,
            $template->id,
            $data['recipient_phone'],
            $data['variables'] ?? [],
            source: 'api',
            apiKeyId: $apiKeyId,
            mediaUrl: $data['media_url'] ?? null,
        );

        // [Disclosed]: this endpoint's own response contract (per the
        // Phase 4 spec: 401/402/403/422 + a 200 success envelope carrying
        // dispatch_id/queued_recipients_count) is deliberately distinct
        // from send()'s older contract above — e.g. quota_exhausted is
        // 402 here (INSUFFICIENT_QUOTA) vs 403 on the pre-existing
        // /v1/messages/send-template endpoint, and success now includes
        // dispatch_id/queued_recipients_count instead of a bare
        // {status:true}. The older endpoint is left completely
        // unchanged (see send() above) — this is a new, separate
        // endpoint's contract, not a breaking change to an existing one.
        // queued_recipients_count is always 1 here even though an
        // individual send is synchronous, not queued: kept for a
        // consistent success envelope shape with the group path, per the
        // spec's literal field name.
        return match ($result['status']) {
            'sent' => response()->json([
                'success' => true,
                'dispatch_id' => $result['dispatch_log_id'] ?? null,
                'queued_recipients_count' => 1,
            ]),
            'missing_variables' => response()->json(['success' => false, 'error_code' => 'INVALID_VARIABLES', 'message' => $result['message'], 'missing' => $result['missing']], 422),
            'not_found' => response()->json(['success' => false, 'error_code' => 'TEMPLATE_NOT_APPROVED', 'message' => $result['message']], 404),
            'disconnected' => response()->json(['success' => false, 'error_code' => 'WHATSAPP_DISCONNECTED', 'message' => $result['message']], 422),
            'quota_exhausted' => response()->json(['success' => false, 'error_code' => 'INSUFFICIENT_QUOTA', 'message' => $result['message']], 402),
            default => response()->json(['success' => false, 'error_code' => 'SEND_FAILED', 'message' => $result['message'] ?? 'Could not send this message.'], 422),
        };
    }

    /**
     * @param array{group_code: string, variables?: array<string, string>} $data
     */
    private function sendToGroup(int $accountId, ?int $apiKeyId, MessageTemplate $template, array $data): JsonResponse
    {
        $group = ContactGroup::where('account_id', $accountId)
            ->where('group_code', $data['group_code'])
            ->first();

        if (! $group) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Invalid group_code for this account.'], 404);
        }

        $result = GroupMessageDispatcher::dispatch(
            $accountId,
            $group->id,
            $template->id,
            $data['variables'] ?? [],
            source: 'api',
            apiKeyId: $apiKeyId,
        );

        return match ($result['status']) {
            'queued' => response()->json([
                'success' => true,
                'dispatch_id' => $result['dispatch_id'],
                'queued_recipients_count' => $result['queued_recipients_count'],
            ]),
            'group_access_denied' => response()->json(['success' => false, 'error_code' => 'GROUP_ACCESS_DENIED', 'message' => $result['message']], 403),
            'empty_group' => response()->json(['success' => false, 'error_code' => 'EMPTY_GROUP', 'message' => $result['message']], 422),
            'template_not_approved' => response()->json(['success' => false, 'error_code' => 'TEMPLATE_NOT_APPROVED', 'message' => $result['message']], 422),
            'not_found' => response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => $result['message']], 404),
            'disconnected' => response()->json(['success' => false, 'error_code' => 'WHATSAPP_DISCONNECTED', 'message' => $result['message']], 422),
            // [Disclosed]: 'quota_exhausted' (no active subscription at
            // all) and 'insufficient_quota' (has a subscription, but N
            // exceeds what's left) are two different conditions inside
            // GroupMessageDispatcher, but the Phase 4 spec's own
            // enumerated error list has one bucket for both ("402
            // insufficient quota") — folded together here rather than
            // inventing an unrequested third error_code.
            'quota_exhausted' => response()->json(['success' => false, 'error_code' => 'INSUFFICIENT_QUOTA', 'message' => $result['message']], 402),
            'insufficient_quota' => response()->json([
                'success' => false,
                'error_code' => 'INSUFFICIENT_QUOTA',
                'message' => "This group dispatch requires {$result['required']} credits, but your account only has {$result['remaining']} remaining credits.",
            ], 402),
            default => response()->json(['success' => false, 'error_code' => 'SEND_FAILED', 'message' => $result['message'] ?? 'Could not queue this group dispatch.'], 422),
        };
    }

    /** @return array{message_type: 'text'|'media', content: array<string, mixed>} */
    private function directContent(array $data): array
    {
        $mediaUrl = trim((string) ($data['media_url'] ?? ''));

        if ($mediaUrl !== '') {
            return ['message_type' => 'media', 'content' => [
                'media_type' => \App\Support\WhatsAppMediaPayloadBuilder::inferMediaType($mediaUrl),
                'url' => $mediaUrl,
                'caption' => $data['text'],
            ]];
        }

        return ['message_type' => 'text', 'content' => ['body' => $data['text']]];
    }

    private function sendDirectToIndividual(int $accountId, ?int $apiKeyId, array $data): JsonResponse
    {
        $content = $this->directContent($data);

        $result = DirectMessageDispatcher::dispatch(
            $accountId,
            $data['recipient_phone'],
            $content['message_type'],
            $content['content'],
            source: 'api',
            apiKeyId: $apiKeyId,
        );

        return match ($result['status']) {
            'sent' => response()->json([
                'success' => true,
                'dispatch_id' => $result['dispatch_log_id'] ?? null,
                'queued_recipients_count' => 1,
            ]),
            'not_found' => response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => $result['message']], 404),
            'disconnected' => response()->json(['success' => false, 'error_code' => 'WHATSAPP_DISCONNECTED', 'message' => $result['message']], 422),
            'quota_exhausted' => response()->json(['success' => false, 'error_code' => 'INSUFFICIENT_QUOTA', 'message' => $result['message']], 402),
            default => response()->json(['success' => false, 'error_code' => 'SEND_FAILED', 'message' => $result['message'] ?? 'Could not send this message.'], 422),
        };
    }

    private function sendDirectToGroup(int $accountId, ?int $apiKeyId, array $data): JsonResponse
    {
        $group = $this->resolveGroupByCode($accountId, $data['group_code']);

        if (! $group) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Invalid group_code for this account.'], 404);
        }

        $content = $this->directContent($data);

        $result = GroupDirectMessageDispatcher::dispatch(
            $accountId,
            $group->id,
            $content['message_type'],
            $content['content'],
            source: 'api',
            apiKeyId: $apiKeyId,
        );

        return match ($result['status']) {
            'queued' => response()->json([
                'success' => true,
                'dispatch_id' => $result['dispatch_id'],
                'queued_recipients_count' => $result['queued_recipients_count'],
            ]),
            'group_access_denied' => response()->json(['success' => false, 'error_code' => 'GROUP_ACCESS_DENIED', 'message' => $result['message']], 403),
            'empty_group' => response()->json(['success' => false, 'error_code' => 'EMPTY_GROUP', 'message' => $result['message']], 422),
            'not_found' => response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => $result['message']], 404),
            'disconnected' => response()->json(['success' => false, 'error_code' => 'WHATSAPP_DISCONNECTED', 'message' => $result['message']], 422),
            'group_not_synced' => response()->json(['success' => false, 'error_code' => 'GROUP_NOT_SYNCED', 'message' => $result['message']], 422),
            'unsupported_engine' => response()->json(['success' => false, 'error_code' => 'UNSUPPORTED_ENGINE', 'message' => $result['message']], 422),
            'quota_exhausted' => response()->json(['success' => false, 'error_code' => 'INSUFFICIENT_QUOTA', 'message' => $result['message']], 402),
            default => response()->json(['success' => false, 'error_code' => 'SEND_FAILED', 'message' => $result['message'] ?? 'Could not queue this group dispatch.'], 422),
        };
    }

    private function resolveGroupByCode(int $accountId, string $groupCode): ?ContactGroup
    {
        return ContactGroup::where('account_id', $accountId)->where('group_code', $groupCode)->first();
    }
}
