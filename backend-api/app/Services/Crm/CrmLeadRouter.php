<?php

namespace App\Services\Crm;

use App\Models\Account;
use App\Models\CrmLead;
use App\Models\CrmLeadSetting;
use App\Models\InAppNotification;
use App\Models\User;
use App\Services\Mail\SystemMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * QA fixes (2026-10-14) — a new lead reaches a person.
 *
 * Every captured lead (Meta lead ad, click-to-WhatsApp ad, journey, website,
 * Developer API, manual) used to land unassigned with nobody told. After a
 * lead is created this assigns it by the account's routing setting (owner, or
 * round robin among active members with CRM access) when it has no assignee
 * yet, and notifies the assignee (in-app + email). Without an assignee the
 * account's admins get an in-app notice instead. Never throws.
 */
class CrmLeadRouter
{
    public function __construct(private readonly SystemMailer $mailer) {}

    public function leadCreated(CrmLead $lead): void
    {
        try {
            $settings = CrmLeadSetting::for((int) $lead->account_id);

            if ($lead->assigned_user_id === null && $settings->assign_mode !== 'none') {
                $assignee = $this->pickAssignee($lead, $settings);
                if ($assignee !== null) {
                    $lead->forceFill(['assigned_user_id' => $assignee->id])->saveQuietly();
                }
            }

            $this->notify($lead, $settings);

            \App\Services\Webhooks\WebhookDispatcher::fire((int) $lead->account_id, 'crm.lead.created', self::webhookPayload($lead));

            // QA fixes (2026-10-14): journeys with the "New CRM lead" trigger start for it.
            $this->startLeadJourneys($lead);
        } catch (Throwable $e) {
            Log::warning('CrmLeadRouter: lead routing failed.', ['crm_lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    public static function webhookPayload(CrmLead $lead): array
    {
        return [
            'id' => $lead->id,
            'account_id' => $lead->account_id,
            'status' => $lead->status,
            'source' => $lead->source,
            'name' => $lead->contact?->name,
            'phone' => $lead->contact?->phone_number,
            'email' => $lead->contact?->email,
            'assigned_user_id' => $lead->assigned_user_id,
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }

    private function startLeadJourneys(CrmLead $lead): void
    {
        $phone = (string) ($lead->contact?->phone_number ?? '');
        if ($phone === '' || $lead->source === CrmLead::SOURCE_JOURNEY) {
            return; // a journey's own save_lead never re-triggers a journey
        }
        $flows = \App\Models\WhatsAppFlow::query()->where('account_id', $lead->account_id)
            ->where('trigger_type', 'lead_created')->where('is_active', true)->orderBy('id')->get();
        if ($flows->isEmpty()) {
            return;
        }
        $account = Account::query()->with('currentSubscription')->find($lead->account_id);
        if ($account === null) {
            return;
        }
        $engine = app(\App\Services\WhatsApp\WhatsAppJourneyEngine::class);
        foreach ($flows as $flow) {
            if ($engine->startForContact($account, $flow, $phone, [
                'name' => $lead->contact?->name,
                'lead_id' => $lead->id,
                'lead_source' => $lead->source,
            ], 'lead_created')) {
                break; // one journey per new lead
            }
        }
    }

    private function pickAssignee(CrmLead $lead, CrmLeadSetting $settings): ?User
    {
        $accountId = (int) $lead->account_id;

        if ($settings->assign_mode === 'owner') {
            $owner = Account::query()->find($accountId)?->owner;

            return CrmLead::assigneeIsEligible($owner, $accountId) ? $owner : null;
        }

        if (! $settings->exists) {
            return null;
        }

        return DB::transaction(function () use ($accountId) {
            $locked = CrmLeadSetting::query()->where('account_id', $accountId)->lockForUpdate()->first();
            $candidates = User::query()->where('account_id', $accountId)->where('is_active', true)->orderBy('id')->get()
                ->filter(fn (User $u) => CrmLead::assigneeIsEligible($u, $accountId))
                ->values();
            if ($locked === null || $candidates->isEmpty()) {
                return null;
            }
            $next = $candidates->first(fn (User $u) => $u->id > (int) $locked->last_assigned_user_id) ?? $candidates->first();
            $locked->forceFill(['last_assigned_user_id' => $next->id])->save();

            return $next;
        });
    }

    private function notify(CrmLead $lead, CrmLeadSetting $settings): void
    {
        $name = trim((string) ($lead->contact?->name ?? '')) ?: 'A new lead';
        $source = str_replace('_', ' ', (string) ($lead->source ?: 'manual'));
        $body = "{$name} came in from {$source}.";

        if ($lead->assigned_user_id !== null) {
            if (! $settings->notify_assignee) {
                return;
            }
            $user = User::query()->find($lead->assigned_user_id);
            // Someone who assigns a lead to themselves does not need telling.
            if ($user === null || $user->id === auth()->id()) {
                return;
            }
            InAppNotification::query()->create([
                'user_id' => $user->id, 'title' => 'New lead assigned to you', 'body' => $body,
                'category' => 'crm', 'link' => '/crm/leads', 'is_read' => false,
            ]);
            $this->mailer->sendLater('account.alert', $user->email, [
                'name' => $user->name,
                'company_name' => (string) ($user->account?->company_name ?? ''),
                'alert_title' => 'New lead assigned to you',
                'alert_message' => $body,
                'action_label' => 'Open CRM leads',
                'action_url' => $this->mailer->url('/crm/leads'),
            ], [], $user->account_id, $user->name);

            return;
        }

        // A lead someone typed in by hand is not news to the account.
        if (($lead->source ?: 'manual') === 'manual') {
            return;
        }

        // Unassigned capture: the admins see it in the app (no email for every single lead).
        $admins = User::query()->where('account_id', $lead->account_id)->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->get();
        foreach ($admins as $admin) {
            InAppNotification::query()->create([
                'user_id' => $admin->id, 'title' => 'New lead', 'body' => $body.' It is not assigned to anyone yet.',
                'category' => 'crm', 'link' => '/crm/leads', 'is_read' => false,
            ]);
        }
    }
}
