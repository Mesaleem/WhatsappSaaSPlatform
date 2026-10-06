<?php

namespace App\Services\Batches;

use App\Models\Account;
use App\Models\MessageBatch;
use App\Models\MessageBatchItem;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Services\Templates\BulkMessageCooldown;
use App\Services\Templates\TemplateMessageDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Batch sends from an uploaded list, for the Send Notification page only (never the Developer API).
 *
 * Senders: a batch sends from one or more of the account's linked numbers, in turn. With numbers A, B, C, D, E chosen,
 * message 1 goes from A, message 2 from B, ..., message 5 from E, message 6 from A again.
 *
 * Spacing: each sending number waits GAP_SECONDS (25) between its own messages, so no single number looks like a bot.
 * Numbers are spaced by GAP_SECONDS / (number of senders), rounded up to whole seconds: one number sends every 25 s,
 * five numbers send every 5 s (each number then waits 25 s again), ten numbers every 3 s (30 s per number).
 * The gap is also checked when a message is actually sent, so a late runner never breaks it.
 *
 * Batches: the list is sent in batches of batch_size numbers, with interval_minutes of rest after each batch.
 * The every-minute runner sends each number at its planned time. A batch scheduled for a time starts at that time.
 * Pausing keeps the unsent numbers (their times are planned again on resume); stopping cancels them. A batch also
 * pauses by itself when the quota runs out or a sending number disconnects.
 *
 * Batch sends do not start the bulk cooldown (BulkMessageCooldown). A new batch is refused while the account is in a
 * bulk cooldown, so the anti-spam rule still applies to large lists.
 */
class MessageBatchService
{
    public const SOURCE = 'web_batch';

    /** Seconds one sending number waits between its own messages. */
    public const GAP_SECONDS = 25;

    /** The runner works once a minute: one run sends what falls in this many seconds from its start. */
    private const WINDOW_SECONDS = 55;

    /** A number stuck in "sending" (the process died mid-send) is put back to pending after this many minutes. */
    private const STUCK_MINUTES = 10;

    /**
     * @param  array{phones: list<string>}  $parsed
     * @param  array<string, mixed>  $data  template_id, variables, media_url, title, batch_size, interval_minutes,
     *                                      scheduled_at, sender_number_ids (list of linked numbers, in turn order)
     */
    public function create(Account $account, ?User $user, array $parsed, string $filename, array $data): MessageBatch
    {
        if (BulkMessageCooldown::isActive($account->id)) {
            throw new BatchException('bulk_cooldown', 'Bulk sending is paused for a cooldown after your last large send. Try again after the cooldown ends.', 429);
        }

        $phones = $parsed['phones'];
        if ($phones === []) {
            throw new BatchException('no_numbers', 'The file has no valid phone numbers.');
        }

        $senders = array_values(array_map('intval', $data['sender_number_ids'] ?? []));
        if ($senders === []) {
            throw new BatchException('no_sender', 'Choose at least one WhatsApp number to send from.');
        }

        $scheduledAt = $data['scheduled_at'] ?? null;
        $this->assertScheduleAllowed($scheduledAt);

        return DB::transaction(function () use ($account, $user, $phones, $filename, $data, $scheduledAt, $senders) {
            $batchSize = (int) $data['batch_size'];
            $interval = (int) $data['interval_minutes'];
            $step = $this->stepSeconds(count($senders));

            $batch = MessageBatch::query()->create([
                'account_id' => $account->id,
                'created_by_user_id' => $user?->id,
                'template_id' => (int) $data['template_id'],
                'sender_number_ids' => $senders,
                'variables' => $data['variables'] ?? [],
                'media_url' => $data['media_url'] ?? null,
                'title' => mb_substr((string) ($data['title'] ?? $filename), 0, 120),
                'kind' => $data['kind'] ?? 'file',
                'source_filename' => $filename === '' ? null : mb_substr($filename, 0, 190),
                'total' => count($phones),
                'batch_size' => $batchSize,
                'interval_minutes' => $interval,
                'status' => $scheduledAt ? MessageBatch::SCHEDULED : MessageBatch::RUNNING,
                'scheduled_at' => $scheduledAt?->copy()->utc(),
                'next_chunk_at' => null,
            ]);

            // Start no earlier than the next time each sending number may send, so the gap holds across batches too.
            $base = $scheduledAt
                ? $scheduledAt->copy()->utc()
                : $this->earliestForAll($account->id, $senders, now()->utc());
            $times = $this->plan($base, count($phones), $batchSize, $interval, $step);

            $stamp = now();
            foreach (array_chunk($phones, 500, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $index => $phone) {
                    $rows[] = [
                        'message_batch_id' => $batch->id,
                        'sequence' => $index + 1,
                        'phone' => $phone,
                        'sender_number_id' => $senders[$index % count($senders)],
                        'status' => MessageBatchItem::PENDING,
                        'send_at' => $times[$index],
                        'created_at' => $stamp,
                        'updated_at' => $stamp,
                    ];
                }
                MessageBatchItem::query()->insert($rows);
            }

            return $batch->refresh();
        });
    }

    /** Seconds between two messages of the whole batch when $senders numbers take turns: 25 s for one, 5 s for five. */
    public function stepSeconds(int $senders): int
    {
        return (int) ceil(self::GAP_SECONDS / max(1, $senders));
    }

    /**
     * The planned send times for $count numbers. Within a batch, messages are $step seconds apart; a batch of $batchSize
     * is followed by $intervalMinutes of rest before the next one starts.
     *
     * @return list<Carbon> UTC times, in order
     */
    public function plan(Carbon $base, int $count, int $batchSize, int $intervalMinutes, int $step): array
    {
        $cycle = $batchSize * $step + $intervalMinutes * 60;
        $times = [];

        for ($n = 0; $n < $count; $n++) {
            $batchIndex = intdiv($n, $batchSize);
            $position = $n % $batchSize;
            $times[] = $base->copy()->utc()->addSeconds($batchIndex * $cycle + $position * $step);
        }

        return $times;
    }

    /**
     * Sends every number whose time is due in this run's window. Called every minute by batches:dispatch-due.
     * A number is claimed before it is sent, so two runs never send the same number twice.
     *
     * @return int how many numbers were attempted in this run
     */
    public function dispatchDue(?Carbon $now = null, int $limit = 200): int
    {
        // Stored times are UTC, so the comparison is made in UTC too.
        $now = ($now ?? now())->copy()->utc();
        $windowEnd = $now->copy()->addSeconds(self::WINDOW_SECONDS);

        // A scheduled batch whose time has come starts; its numbers are already planned from that time.
        MessageBatch::query()
            ->where('status', MessageBatch::SCHEDULED)
            ->where('scheduled_at', '<=', $windowEnd)
            ->update(['status' => MessageBatch::RUNNING, 'updated_at' => now()]);

        $this->releaseStuck($now);

        $due = MessageBatchItem::query()
            ->join('message_batches', 'message_batches.id', '=', 'message_batch_items.message_batch_id')
            ->where('message_batches.status', MessageBatch::RUNNING)
            ->where('message_batch_items.status', MessageBatchItem::PENDING)
            ->where('message_batch_items.send_at', '<=', $windowEnd)
            ->orderBy('message_batch_items.send_at')
            ->orderBy('message_batch_items.id')
            ->limit($limit)
            ->get([
                'message_batch_items.id',
                'message_batch_items.send_at',
                'message_batch_items.sender_number_id',
                'message_batches.account_id',
            ]);

        $attempted = 0;

        // The runner's own clock: waits are measured from it, and it moves forward as numbers are sent.
        $clock = $now->copy();

        foreach ($due as $row) {
            // Never closer than the gap to the same sending number's previous message, even when the runner was late.
            $sendAt = $this->earliestFor(
                $this->senderKey((int) $row->account_id, $row->sender_number_id),
                Carbon::parse($row->send_at, 'UTC'),
            );

            if ($sendAt->gt($windowEnd)) {
                // Not this run: record the moved time, so the next run keeps the same order.
                MessageBatchItem::query()->whereKey($row->id)->update(['send_at' => $sendAt, 'updated_at' => now()]);

                break;
            }

            $clock = $this->waitUntil($sendAt, $clock);

            $claimed = MessageBatchItem::query()
                ->whereKey($row->id)
                ->where('status', MessageBatchItem::PENDING)
                ->update(['status' => MessageBatchItem::SENDING, 'send_at' => $sendAt, 'updated_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            $item = MessageBatchItem::query()->findOrFail($row->id);
            $batch = MessageBatch::query()->find($item->message_batch_id);

            // The batch was paused or stopped after this number was planned: give the number back, unchanged.
            if (! $batch || $batch->status !== MessageBatch::RUNNING) {
                $item->forceFill(['status' => MessageBatchItem::PENDING])->save();

                continue;
            }

            $attempted++;
            $this->sendItem($batch, $item);
        }

        return $attempted;
    }

    /** Sends one claimed number from its own sending number; a quota or connection problem pauses the batch instead. */
    private function sendItem(MessageBatch $batch, MessageBatchItem $item): void
    {
        $result = TemplateMessageDispatcher::dispatch(
            $batch->account_id,
            $batch->template_id,
            $item->phone,
            $batch->variables ?? [],
            source: self::SOURCE,
            mediaUrl: $batch->media_url,
            senderNumberId: $item->sender_number_id,
        );

        // Whatever happened, this sending number's next message waits the gap from this moment.
        $this->rememberSend($this->senderKey($batch->account_id, $item->sender_number_id), now()->utc());

        $status = (string) ($result['status'] ?? '');

        if ($status === 'quota_exhausted' || $status === 'disconnected') {
            // The number goes back to pending and the batch waits for the user to resume it.
            $item->forceFill(['status' => MessageBatchItem::PENDING])->save();
            $batch->forceFill([
                'status' => MessageBatch::PAUSED,
                'stop_reason' => $status === 'quota_exhausted' ? 'Paused: the message quota is used up.' : 'Paused: a sending WhatsApp number is disconnected.',
            ])->save();

            return;
        }

        if ($status === 'sent') {
            $item->forceFill(['status' => MessageBatchItem::SENT, 'error' => null, 'attempted_at' => now()->utc()])->save();
            $batch->increment('sent_count');
        } else {
            $item->forceFill([
                'status' => MessageBatchItem::FAILED,
                'error' => mb_substr((string) ($result['message'] ?? 'Could not send this message.'), 0, 255),
                'attempted_at' => now()->utc(),
            ])->save();
            $batch->increment('failed_count');
        }

        $open = $batch->items()->whereIn('status', [MessageBatchItem::PENDING, MessageBatchItem::SENDING])->exists();
        if (! $open) {
            $batch->forceFill(['status' => MessageBatch::COMPLETED, 'completed_at' => now()->utc()])->save();
        }
    }

    /** The cache key of one sending number's last send. A null sender means the account's default number. */
    private function senderKey(int $accountId, ?int $senderNumberId): string
    {
        return 'message_batch_last_send:'.$accountId.':'.($senderNumberId ?? 'default');
    }

    /** The earliest time the sending number may send: the planned time, or later when its last message was too recent. */
    private function earliestFor(string $key, Carbon $planned): Carbon
    {
        $last = Cache::get($key);

        if (! $last instanceof Carbon) {
            return $planned->copy()->utc();
        }

        $allowed = $last->copy()->addSeconds(self::GAP_SECONDS)->utc();

        return $allowed->gt($planned) ? $allowed : $planned->copy()->utc();
    }

    /** The earliest time a batch may start, given every sending number it uses. */
    private function earliestForAll(int $accountId, array $senderIds, Carbon $planned): Carbon
    {
        $earliest = $planned->copy()->utc();

        foreach ($senderIds as $senderId) {
            $candidate = $this->earliestFor($this->senderKey($accountId, (int) $senderId), $planned);
            if ($candidate->gt($earliest)) {
                $earliest = $candidate;
            }
        }

        return $earliest;
    }

    private function rememberSend(string $key, Carbon $at): void
    {
        Cache::put($key, $at, now()->addDay());
    }

    /** Sleeps from the runner's clock until $at (no sleep when $at has passed), and returns the clock at $at. */
    private function waitUntil(Carbon $at, Carbon $clock): Carbon
    {
        $milliseconds = $at->getTimestampMs() - $clock->getTimestampMs();

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);

            return $at->copy();
        }

        return $clock;
    }

    /** Puts numbers left in "sending" by a process that died back to pending. */
    private function releaseStuck(Carbon $now): void
    {
        MessageBatchItem::query()
            ->where('status', MessageBatchItem::SENDING)
            ->where('updated_at', '<', $now->copy()->subMinutes(self::STUCK_MINUTES))
            ->update(['status' => MessageBatchItem::PENDING, 'updated_at' => now()]);
    }

    public function pause(Account $account, int $batchId): MessageBatch
    {
        $batch = $this->owned($account, $batchId);

        if (! $batch->isActive()) {
            throw new BatchException('not_active', 'Only a scheduled or running batch can be paused.');
        }

        $batch->forceFill(['status' => MessageBatch::PAUSED, 'stop_reason' => 'Paused by you.'])->save();

        return $batch->refresh();
    }

    public function resume(Account $account, int $batchId): MessageBatch
    {
        $batch = $this->owned($account, $batchId);

        if ($batch->status !== MessageBatch::PAUSED) {
            throw new BatchException('not_paused', 'Only a paused batch can be resumed.');
        }

        $now = now()->utc();
        $waiting = $batch->scheduled_at && $batch->scheduled_at->gt($now);
        $senders = array_values(array_map('intval', $batch->sender_number_ids ?? []));

        // The numbers still waiting are planned again from now (or from the scheduled time), keeping every sender's gap.
        $start = $waiting
            ? $batch->scheduled_at->copy()->utc()
            : $this->earliestForAll($batch->account_id, $senders, $now);
        $pending = $batch->items()->where('status', MessageBatchItem::PENDING)->orderBy('sequence')->get(['id']);
        $times = $this->plan($start, $pending->count(), $batch->batch_size, $batch->interval_minutes, $this->stepSeconds(max(1, count($senders))));

        foreach ($pending as $index => $item) {
            MessageBatchItem::query()->whereKey($item->id)->update(['send_at' => $times[$index], 'updated_at' => $now]);
        }

        $batch->forceFill([
            'status' => $waiting ? MessageBatch::SCHEDULED : MessageBatch::RUNNING,
            'stop_reason' => null,
        ])->save();

        return $batch->refresh();
    }

    /** Stops a batch for good: its unsent numbers are cancelled, and the sent history is kept. */
    public function stop(Account $account, int $batchId): MessageBatch
    {
        $batch = $this->owned($account, $batchId);

        if (! in_array($batch->status, [MessageBatch::SCHEDULED, MessageBatch::RUNNING, MessageBatch::PAUSED], true)) {
            throw new BatchException('not_active', 'This batch has already finished.');
        }

        return DB::transaction(function () use ($batch) {
            MessageBatchItem::query()
                ->where('message_batch_id', $batch->id)
                ->where('status', MessageBatchItem::PENDING)
                ->update(['status' => MessageBatchItem::CANCELLED, 'updated_at' => now()]);

            $batch->forceFill(['status' => MessageBatch::STOPPED, 'stopped_at' => now()->utc(), 'stop_reason' => 'Stopped by you.'])->save();

            return $batch->refresh();
        });
    }

    private function owned(Account $account, int $batchId): MessageBatch
    {
        $batch = MessageBatch::query()->where('account_id', $account->id)->whereKey($batchId)->first();

        if (! $batch) {
            throw new BatchException('not_found', 'Batch not found.', 404);
        }

        return $batch;
    }

    /** A schedule must be at least a minute ahead (the runner works every minute) and at most 90 days ahead. */
    private function assertScheduleAllowed(?Carbon $scheduledAt): void
    {
        if ($scheduledAt === null) {
            return;
        }

        if ($scheduledAt->lt(now()->addMinute())) {
            throw new BatchException('send_at_too_soon', 'Choose a time at least one minute from now.');
        }

        if ($scheduledAt->gt(now()->addDays(ScheduledMessage::MAX_DAYS_AHEAD))) {
            throw new BatchException('send_at_too_far', 'You can schedule a batch up to '.ScheduledMessage::MAX_DAYS_AHEAD.' days ahead.');
        }
    }
}
