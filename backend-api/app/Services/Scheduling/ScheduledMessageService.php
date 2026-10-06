<?php

namespace App\Services\Scheduling;

use App\Models\Account;
use App\Models\ScheduledMessage;
use App\Services\Groups\GroupMessageDispatcher;
use App\Services\Templates\TemplateMessageDispatcher;
use App\Services\WhatsApp\DirectMessageDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stores a message to be sent later and sends it when it is due. The send goes through the same
 * dispatchers as an immediate send, so quota, plan and WhatsApp connection rules are unchanged.
 * Quota is used when the message is actually sent, not when it is scheduled.
 */
class ScheduledMessageService
{
    /** A message must be scheduled at least this far ahead, so the minute-by-minute runner can pick it up. */
    private const MIN_MINUTES_AHEAD = 1;

    /** Seconds between the recipients of one scheduled bulk send, so they do not go out as a burst. */
    public const BULK_STAGGER_SECONDS = 3;

    /** The optional send time from a request: null for an immediate send, else the parsed time. */
    public static function parseSendAt(mixed $value): ?Carbon
    {
        // A value without its own zone is Indian Standard Time (the Send later field's zone). Stored in UTC, because the
        // database keeps wall-clock values and reads them back as UTC: an IST value kept as-is would fire 5.5 hours late.
        return $value === null || $value === '' ? null : Carbon::parse((string) $value, 'Asia/Kolkata')->utc();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function schedule(Account $account, string $kind, array $payload, Carbon $sendAt, string $source = 'api', ?int $apiKeyId = null): ScheduledMessage
    {
        if (! in_array($kind, [ScheduledMessage::KIND_TEMPLATE_INDIVIDUAL, ScheduledMessage::KIND_TEMPLATE_GROUP, ScheduledMessage::KIND_DIRECT_TEXT], true)) {
            throw new ScheduledMessageException('This message type cannot be scheduled.', 'unsupported_kind');
        }

        if ($sendAt->lt(now()->addMinutes(self::MIN_MINUTES_AHEAD))) {
            throw new ScheduledMessageException('The time to send must be at least one minute from now.', 'send_at_in_past');
        }

        if ($sendAt->gt(now()->addDays(ScheduledMessage::MAX_DAYS_AHEAD))) {
            throw new ScheduledMessageException(
                'A message can be scheduled up to '.ScheduledMessage::MAX_DAYS_AHEAD.' days ahead.',
                'send_at_too_far',
            );
        }

        return ScheduledMessage::query()->create([
            'account_id' => $account->id,
            'api_key_id' => $apiKeyId,
            'source' => $source,
            'kind' => $kind,
            'payload' => $payload,
            'send_at' => $sendAt,
            'status' => ScheduledMessage::PENDING,
            'attempts' => 0,
        ]);
    }

    /** Cancels a message that has not been sent yet. Only the owning account can cancel it. */
    public function cancel(Account $account, int $id): ScheduledMessage
    {
        $row = ScheduledMessage::query()->where('account_id', $account->id)->whereKey($id)->first();
        if ($row === null) {
            throw new ScheduledMessageException('Scheduled message not found.', 'not_found', 404);
        }

        if ($row->status !== ScheduledMessage::PENDING) {
            throw new ScheduledMessageException('Only a message that has not been sent yet can be cancelled.', 'not_pending', 409);
        }

        $row->forceFill(['status' => ScheduledMessage::CANCELLED])->save();

        return $row->refresh();
    }

    /** Sends the messages whose time has come. Returns how many were attempted. */
    public function dispatchDue(int $limit = 100): int
    {
        $ids = ScheduledMessage::query()
            ->where('status', ScheduledMessage::PENDING)
            ->where('send_at', '<=', now())
            ->orderBy('send_at')
            ->limit($limit)
            ->pluck('id');

        $attempted = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$attempted): void {
                // Re-read under a lock: a cancel or another runner may have changed it since the list was made.
                $row = ScheduledMessage::query()->whereKey($id)->lockForUpdate()->first();
                if ($row === null || $row->status !== ScheduledMessage::PENDING || $row->send_at->isFuture()) {
                    return;
                }

                $attempted++;
                $row->attempts = $row->attempts + 1;

                try {
                    [$ok, $message] = $this->send($row);
                } catch (\Throwable $e) {
                    Log::error('Scheduled message failed', ['id' => $row->id, 'error' => $e->getMessage()]);
                    [$ok, $message] = [false, 'The message could not be sent.'];
                }

                $row->forceFill([
                    'status' => $ok ? ScheduledMessage::SENT : ScheduledMessage::FAILED,
                    'last_error' => $ok ? null : $message,
                    'sent_at' => $ok ? now() : null,
                ])->save();
            });
        }

        return $attempted;
    }

    /**
     * @return array{0: bool, 1: string} [success, message]
     */
    private function send(ScheduledMessage $row): array
    {
        $p = $row->payload;

        $result = match ($row->kind) {
            ScheduledMessage::KIND_TEMPLATE_INDIVIDUAL => TemplateMessageDispatcher::dispatch(
                (int) $row->account_id,
                (int) $p['template_id'],
                (string) $p['recipient_phone'],
                (array) ($p['variables'] ?? []),
                source: $row->source,
                apiKeyId: $row->api_key_id,
                mediaUrl: $p['media_url'] ?? null,
                senderNumberId: isset($p['sender_number_id']) ? (int) $p['sender_number_id'] : null,
            ),
            ScheduledMessage::KIND_TEMPLATE_GROUP => GroupMessageDispatcher::dispatch(
                (int) $row->account_id,
                (int) $p['group_id'],
                (int) $p['template_id'],
                (array) ($p['variables'] ?? []),
                source: $row->source,
                apiKeyId: $row->api_key_id,
                senderNumberId: isset($p['sender_number_id']) ? (int) $p['sender_number_id'] : null,
                mediaUrl: $p['media_url'] ?? null,
            ),
            ScheduledMessage::KIND_DIRECT_TEXT => DirectMessageDispatcher::dispatch(
                (int) $row->account_id,
                (string) $p['recipient_phone'],
                (string) $p['message_type'],
                $p['content'],
                source: $row->source,
                apiKeyId: $row->api_key_id,
            ),
            default => ['status' => 'unsupported'],
        };

        // A group send is accepted into the queue ("queued"); an individual send is "sent".
        $ok = in_array($result['status'] ?? null, ['sent', 'queued'], true);

        return [$ok, $ok ? 'Sent.' : (string) ($result['message'] ?? 'Could not send this message.')];
    }
}
