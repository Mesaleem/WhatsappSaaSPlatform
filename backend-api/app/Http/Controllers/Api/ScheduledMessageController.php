<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\ContactGroup;
use App\Models\MessageTemplate;
use App\Models\ScheduledMessage;
use App\Models\WhatsAppNumber;
use App\Services\Scheduling\ScheduledMessageException;
use App\Services\Scheduling\ScheduledMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Session-authenticated list/cancel for a scheduled message -- the internal counterpart of the
 * Developer API's DELETE /api/v1/scheduled-messages/{id} (TemplateMessageController::cancelScheduled()),
 * which is the only place a scheduled send could previously be seen or cancelled. Covers every kind a
 * message can be scheduled as: a template or a free-text ("No template") send, to one number or to a
 * contact group -- the same list, whichever screen created the row.
 */
class ScheduledMessageController extends Controller
{
    use ResolvesTenantAccount;

    private const PER_PAGE = 20;

    public function __construct(private readonly ScheduledMessageService $scheduler)
    {
    }

    /** GET /api/alerts/scheduled-messages */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account first (pass ?account_id=).');

        $data = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in([
                ScheduledMessage::PENDING, ScheduledMessage::SENT, ScheduledMessage::FAILED, ScheduledMessage::CANCELLED,
            ])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = ScheduledMessage::query()->where('account_id', $account->id);

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        // Soonest-due pending message first; once sent/failed/cancelled, most recently decided first.
        $paginator = $query
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [ScheduledMessage::PENDING])
            ->orderBy('send_at', 'asc')
            ->paginate(self::PER_PAGE, ['*'], 'page', $data['page'] ?? 1);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (ScheduledMessage $row) => $this->present($row))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** POST /api/alerts/scheduled-messages/{id}/cancel */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account first (pass ?account_id=).');

        try {
            $row = $this->scheduler->cancel($account, $id);
        } catch (ScheduledMessageException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
        }

        return response()->json(['message' => 'Scheduled message cancelled.', 'data' => $this->present($row)]);
    }

    /** One row of the list: who it goes to, what it says, which number it goes from, and whether it can still be cancelled. */
    private function present(ScheduledMessage $row): array
    {
        $p = $row->payload ?? [];
        $isGroup = in_array($row->kind, [ScheduledMessage::KIND_TEMPLATE_GROUP, ScheduledMessage::KIND_GROUP_DIRECT_TEXT], true);
        $isTemplate = in_array($row->kind, [ScheduledMessage::KIND_TEMPLATE_INDIVIDUAL, ScheduledMessage::KIND_TEMPLATE_GROUP], true);

        $recipient = $isGroup
            ? (ContactGroup::query()->whereKey($p['group_id'] ?? null)->value('name') ?? 'Deleted group')
            : (string) ($p['recipient_phone'] ?? '');

        $preview = $isTemplate
            ? (MessageTemplate::query()->whereKey($p['template_id'] ?? null)->value('title') ?? 'Deleted template')
            : mb_substr((string) ($p['content']['body'] ?? $p['content']['caption'] ?? ''), 0, 160);

        $senderNumberId = $p['sender_number_id'] ?? null;
        $senderPhone = $senderNumberId ? WhatsAppNumber::query()->whereKey($senderNumberId)->value('phone_number') : null;

        return [
            'id' => $row->id,
            'kind' => $row->kind,
            'is_template' => $isTemplate,
            'recipient_type' => $isGroup ? 'group' : 'individual',
            'recipient' => $recipient,
            'preview' => $preview,
            'sender_number' => $senderPhone,
            'source' => $row->source,
            'status' => $row->status,
            'send_at' => $row->send_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'sent_at' => $row->sent_at?->toIso8601String(),
            'last_error' => $row->last_error,
            'can_cancel' => $row->status === ScheduledMessage::PENDING,
        ];
    }
}
