<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\DirectMessageDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QA fixes (2026-10-14) — the WhatsApp inbox: a client sees its customers'
 * WhatsApp conversations and answers them.
 *
 *   GET  /api/whatsapp/inbox/threads                 conversations, newest first (?search=, ?unread=1)
 *   GET  /api/whatsapp/inbox/threads/{id}/messages   a conversation (marks it read)
 *   POST /api/whatsapp/inbox/threads/{id}/reply      { text } — sent like any other message
 *
 * A reply goes out through DirectMessageDispatcher (same quota, logs and
 * disconnected checks as Send Notification). On the Meta Cloud API free text
 * is allowed only within 24 hours of the customer's last message; outside it
 * the reply is refused with OUTSIDE_SERVICE_WINDOW (send an approved template
 * from Send Notification instead).
 */
class WhatsAppInboxController extends Controller
{
    use ResolvesTenantAccount;

    public function threads(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'unread' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        $threads = WaConversation::query()
            ->where('account_id', $account->id)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('contact_phone', 'like', '%'.preg_replace('/\D+/', '', $search).'%')->orWhere('contact_name', 'like', "%{$search}%")))
            ->when(! empty($data['unread']), fn ($q) => $q->where('unread_count', '>', 0))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 30));

        $engine = $account->currentSubscription?->engine_type;
        $threads->getCollection()->transform(fn (WaConversation $c) => $this->present($c, $engine));

        return response()->json([
            ...$threads->toArray(),
            'unread_total' => (int) WaConversation::query()->where('account_id', $account->id)->sum('unread_count'),
        ]);
    }

    public function messages(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');
        $conversation = WaConversation::query()->where('account_id', $account->id)->findOrFail($id);

        $messages = WaMessage::query()
            ->where('wa_conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->reverse()
            ->values();

        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }

        return response()->json([
            'thread' => $this->present($conversation, $account->currentSubscription?->engine_type),
            'data' => $messages,
        ]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');
        $conversation = WaConversation::query()->where('account_id', $account->id)->findOrFail($id);
        $data = $request->validate(['text' => ['required', 'string', 'max:4096']]);

        if ($account->currentSubscription?->engine_type === 'meta' && ! $conversation->withinServiceWindow()) {
            return response()->json([
                'message' => 'More than 24 hours have passed since this customer last wrote, so WhatsApp only allows an approved template now. Send one from Send Notification.',
                'error_code' => 'OUTSIDE_SERVICE_WINDOW',
            ], 422);
        }

        $result = DirectMessageDispatcher::dispatch(
            $account->id,
            $conversation->contact_phone,
            'text',
            ['body' => $data['text']],
            'inbox',
            null,
            $conversation->whatsapp_number_id,
        );

        if (($result['status'] ?? null) !== 'sent') {
            return response()->json(['message' => $result['message'] ?? 'The reply could not be sent.', 'error_code' => strtoupper((string) ($result['status'] ?? 'failed'))], 422);
        }

        // MessageDispatchLog recorded the message in the inbox; mark who wrote it.
        WaMessage::query()->where('wa_conversation_id', $conversation->id)->where('direction', 'out')
            ->whereNull('sent_by_user_id')->latest('id')->limit(1)->update(['sent_by_user_id' => $request->user()->id]);

        return response()->json(['message' => 'Reply sent.', 'data' => WaMessage::query()->where('wa_conversation_id', $conversation->id)->latest('id')->first()], 201);
    }

    /** @return array<string, mixed> */
    private function present(WaConversation $c, ?string $engine): array
    {
        return [
            'id' => $c->id,
            'contact_phone' => $c->contact_phone,
            'contact_name' => $c->contact_name,
            'last_message_preview' => $c->last_message_preview,
            'last_direction' => $c->last_direction,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_inbound_at' => $c->last_inbound_at?->toIso8601String(),
            'unread_count' => (int) $c->unread_count,
            // Meta only: whether a free-text reply is allowed right now.
            'can_reply_freely' => $engine !== 'meta' || $c->withinServiceWindow(),
        ];
    }
}
