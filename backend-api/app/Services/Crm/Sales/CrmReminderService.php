<?php

namespace App\Services\Crm\Sales;

use App\Models\CrmDocument;
use App\Models\CrmPaymentReminder;
use App\Models\CrmReminderSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 5 — payment reminders, run every hour by `crm:send-payment-reminders`.
 *
 * 1. PLAN: for every open invoice of a tenant with reminders on, one row per rule
 *    occurrence (before:N / due / after:N) whose day has come. unique(invoice, rule) means
 *    the same reminder is never planned — or sent — twice, even by two schedulers at once.
 * 2. SEND each due row, under a lock on the row, after RE-CHECKING the invoice: paid,
 *    void or cancelled -> skipped; the balance in the message is the CURRENT one
 *    (partial payments counted). Quiet hours postpone. auto_send off -> awaiting_approval
 *    (staff approve or skip it). WhatsApp failure -> optional email fallback (each attempt
 *    its own delivery row). A transient failure retries (next_attempt_at, up to
 *    max_attempts); then it is failed for good.
 * A payment needs no special handling: the re-check at send time skips anything paid.
 * sendNow() is the manual "Send reminder" for one invoice, even with automation off.
 */
class CrmReminderService
{
    public function __construct(private readonly CrmDeliveryService $delivery)
    {
    }

    public function settingsFor(int $accountId): CrmReminderSetting
    {
        return CrmReminderSetting::query()->firstOrNew(['account_id' => $accountId], ['days_before' => [3], 'days_after' => [3, 7]]);
    }

    /** @return array{planned: int, sent: int, skipped: int, failed: int, awaiting: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $stats = ['planned' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'awaiting' => 0];

        CrmReminderSetting::query()->where('enabled', true)->each(function (CrmReminderSetting $settings) use ($now, &$stats): void {
            $stats['planned'] += $this->plan($settings, $now);
        });

        CrmPaymentReminder::query()
            ->where('status', CrmPaymentReminder::STATUS_PENDING)
            ->whereDate('scheduled_for', '<=', $now->toDateString())
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->each(function (CrmPaymentReminder $reminder) use ($now, &$stats): void {
                $outcome = $this->process($reminder, $now);
                if ($outcome !== null) {
                    $stats[$outcome]++;
                }
            });

        return $stats;
    }

    /** Create the reminder rows whose day has come for one tenant. */
    public function plan(CrmReminderSetting $settings, Carbon $now): int
    {
        $rules = $settings->rules();
        if ($rules === []) {
            return 0;
        }
        $created = 0;

        CrmDocument::query()
            ->forAccount($settings->account_id)
            ->where('type', CrmDocument::TYPE_INVOICE)
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereNotNull('due_date')
            ->each(function (CrmDocument $invoice) use ($rules, $now, &$created): void {
                foreach ($rules as $key => $offset) {
                    $day = $invoice->due_date->copy()->addDays($offset);
                    if ($day->isAfter($now->copy()->endOfDay()) || $day->isBefore($invoice->issue_date ?? $day)) {
                        continue;
                    }
                    try {
                        CrmPaymentReminder::create([
                            'account_id' => $invoice->account_id, 'crm_document_id' => $invoice->id, 'rule_key' => $key,
                            'scheduled_for' => $day->toDateString(), 'status' => CrmPaymentReminder::STATUS_PENDING,
                        ]);
                        $created++;
                    } catch (QueryException) {
                        // Already planned (unique invoice + rule).
                    }
                }
            });

        return $created;
    }

    /** @return 'sent'|'skipped'|'failed'|'awaiting'|null */
    public function process(CrmPaymentReminder $reminder, Carbon $now): ?string
    {
        return DB::transaction(function () use ($reminder, $now): ?string {
            $reminder = CrmPaymentReminder::query()->whereKey($reminder->id)->lockForUpdate()->first();
            if (! $reminder || $reminder->status !== CrmPaymentReminder::STATUS_PENDING) {
                return null;
            }
            $invoice = CrmDocument::query()->with('contact')->find($reminder->crm_document_id);
            $settings = $this->settingsFor($reminder->account_id);

            if (! $invoice || ! $invoice->isPayable()) {
                $reminder->forceFill(['status' => CrmPaymentReminder::STATUS_SKIPPED, 'last_error' => 'Invoice is paid, void or cancelled.'])->save();

                return 'skipped';
            }
            if (! $settings->enabled) {
                $reminder->forceFill(['status' => CrmPaymentReminder::STATUS_SKIPPED, 'last_error' => 'Reminders were switched off.'])->save();

                return 'skipped';
            }
            if ($this->inQuietHours($settings, $now)) {
                $reminder->forceFill(['next_attempt_at' => $this->quietEnd($settings, $now)])->save();

                return null;
            }
            if (! $settings->auto_send) {
                $reminder->forceFill(['status' => CrmPaymentReminder::STATUS_AWAITING_APPROVAL])->save();

                return 'awaiting';
            }

            return $this->deliver($reminder, $invoice, $settings, null);
        });
    }

    /** Staff approve a reminder that waits for approval. */
    public function approve(CrmPaymentReminder $reminder, ?int $actorUserId): string
    {
        return DB::transaction(function () use ($reminder, $actorUserId): string {
            $reminder = CrmPaymentReminder::query()->whereKey($reminder->id)->lockForUpdate()->firstOrFail();
            if ($reminder->status !== CrmPaymentReminder::STATUS_AWAITING_APPROVAL) {
                throw ValidationException::withMessages(['reminder' => ['This reminder is not waiting for approval.']]);
            }
            $invoice = CrmDocument::query()->with('contact')->findOrFail($reminder->crm_document_id);
            if (! $invoice->isPayable()) {
                $reminder->forceFill(['status' => CrmPaymentReminder::STATUS_SKIPPED, 'last_error' => 'Invoice is paid, void or cancelled.'])->save();

                return 'skipped';
            }

            return $this->deliver($reminder, $invoice, $this->settingsFor($reminder->account_id), $actorUserId);
        });
    }

    public function skip(CrmPaymentReminder $reminder): void
    {
        if (! in_array($reminder->status, [CrmPaymentReminder::STATUS_PENDING, CrmPaymentReminder::STATUS_AWAITING_APPROVAL], true)) {
            throw ValidationException::withMessages(['reminder' => ['Only a pending reminder can be skipped.']]);
        }
        $reminder->forceFill(['status' => CrmPaymentReminder::STATUS_SKIPPED, 'last_error' => 'Skipped by staff.'])->save();
    }

    /**
     * Manual "Send reminder" for one invoice, even when automation is off. Uses the
     * tenant's channel setting (WhatsApp by default).
     *
     * @return list<\App\Models\CrmDocumentDelivery>
     */
    public function sendNow(CrmDocument $invoice, ?string $channel, ?int $actorUserId): array
    {
        if (! $invoice->isPayable()) {
            throw ValidationException::withMessages(['invoice' => ['This invoice has nothing left to pay.']]);
        }
        $channel ??= $this->settingsFor($invoice->account_id)->channel;
        // QA fixes (2026-10-14): without WhatsApp in the plan, reminders go by email.
        if (! \App\Services\Crm\Sales\CrmDeliveryService::canUseWhatsApp((int) $invoice->account_id)) {
            $channel = 'email';
        }
        $message = $this->delivery->defaultMessage($invoice, true);
        $out = [];
        if (in_array($channel, ['whatsapp', 'both'], true)) {
            $out[] = $this->delivery->sendWhatsApp($invoice, null, $message, $actorUserId, 'reminder');
        }
        if (in_array($channel, ['email', 'both'], true)) {
            $out[] = $this->delivery->sendEmail($invoice, null, 'Payment reminder: '.$invoice->number, $message, $actorUserId, 'reminder');
        }

        return $out;
    }

    private function deliver(CrmPaymentReminder $reminder, CrmDocument $invoice, CrmReminderSetting $settings, ?int $actorUserId): string
    {
        $message = $this->delivery->defaultMessage($invoice, true);
        $sent = false;
        $errors = [];

        $channels = match ($settings->channel) {
            'email' => ['email'],
            'both' => ['whatsapp', 'email'],
            default => $settings->email_fallback ? ['whatsapp', 'email_fallback'] : ['whatsapp'],
        };
        // QA fixes (2026-10-14): without WhatsApp in the plan (CRM plan), reminders go by email
        // — they used to fail every attempt with the engine's internal error.
        if (! \App\Services\Crm\Sales\CrmDeliveryService::canUseWhatsApp((int) $invoice->account_id)) {
            $channels = ['email'];
        }

        foreach ($channels as $channel) {
            if ($channel === 'email_fallback' && $sent) {
                break;
            }
            try {
                $delivery = str_starts_with($channel, 'email')
                    ? $this->delivery->sendEmail($invoice, null, 'Payment reminder: '.$invoice->number, $message, $actorUserId, 'reminder', $reminder->id)
                    : $this->delivery->sendWhatsApp($invoice, null, $message, $actorUserId, 'reminder', $reminder->id);
                $sent = $sent || $delivery->status === 'sent';
                if ($delivery->status !== 'sent') {
                    $errors[] = $delivery->error;
                }
            } catch (ValidationException $e) {
                // Permanent for this channel (no number / email, opted out).
                $errors[] = collect($e->errors())->flatten()->first();
            }
        }

        $attempts = $reminder->attempts + 1;
        if ($sent) {
            $reminder->forceFill(['status' => CrmPaymentReminder::STATUS_SENT, 'attempts' => $attempts, 'sent_at' => now(), 'balance_at_send' => $invoice->balance_due, 'last_error' => null])->save();

            return 'sent';
        }

        $final = $attempts >= max(1, (int) $settings->max_attempts);
        $reminder->forceFill([
            'status' => $final ? CrmPaymentReminder::STATUS_FAILED : CrmPaymentReminder::STATUS_PENDING,
            'attempts' => $attempts,
            'last_error' => mb_substr(implode(' | ', array_filter($errors)) ?: 'Not sent.', 0, 500),
            'next_attempt_at' => $final ? null : now()->addHours(2 ** $attempts),
        ])->save();
        if ($final) {
            Log::info('CrmReminderService: reminder failed for good.', ['reminder_id' => $reminder->id]);
        }

        return 'failed';
    }

    private function inQuietHours(CrmReminderSetting $settings, Carbon $now): bool
    {
        $start = $settings->quiet_start_hour;
        $end = $settings->quiet_end_hour;
        if ($start === null || $end === null || $start === $end) {
            return false;
        }
        $hour = (int) $now->copy()->setTimezone((string) config('ai.credits.daily_timezone', 'Asia/Kolkata'))->format('G');

        return $start < $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);
    }

    private function quietEnd(CrmReminderSetting $settings, Carbon $now): Carbon
    {
        $tz = (string) config('ai.credits.daily_timezone', 'Asia/Kolkata');
        $local = $now->copy()->setTimezone($tz)->setTime((int) $settings->quiet_end_hour, 0);
        if ($local->lessThanOrEqualTo($now->copy()->setTimezone($tz))) {
            $local->addDay();
        }

        return $local->setTimezone(config('app.timezone'));
    }
}
