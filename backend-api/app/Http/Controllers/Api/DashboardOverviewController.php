<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AdCampaignDailyMetric;
use App\Models\AgentCommission;
use App\Models\ContactMessage;
use App\Models\CreditAccount;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\Plan;
use App\Models\QuotaRequest;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Services\Access\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/dashboard/overview — the role- and plan-aware part of the Dashboard
 * in one request (2026-10-12):
 *
 *  - platform (Super Admin, no client selected): what needs attention
 *    (pending invoices, quota requests, website messages, plans expiring in 7
 *    days), clients per plan, and the busiest clients.
 *  - agent (an Agent viewing its own account): its clients, commission, and
 *    its clients' expiring plans / pending invoices.
 *  - tenant (a client, or a client a Super Admin/Agent selected): only the
 *    widgets its PLAN includes — CRM, Journeys, AI credits, Ads — with the
 *    capability map so the page can hide the rest.
 *
 * Runs behind tenant.isolation, which resolves `account_id` (and enforces
 * an Agent's ownership of a selected client). Read-only aggregates.
 */
class DashboardOverviewController extends Controller
{
    public function __construct(private readonly AccessControlService $access)
    {
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $accountId = $request->attributes->get('account_id');
        $isSuperAdmin = (bool) $request->attributes->get('is_super_admin');

        if ($isSuperAdmin && ! $accountId) {
            return response()->json(['scope' => 'platform', 'platform' => $this->platform()]);
        }

        $account = Account::with('currentSubscription')->find($accountId);
        abort_unless($account, 404, 'Account not found.');

        $payload = ['scope' => 'tenant', 'tenant' => $this->tenant($account)];

        // An Agent on its own account also gets its business overview.
        if ($account->account_type === 'agent' && (int) $user->account_id === (int) $account->id) {
            $payload['agent'] = $this->agent($account);
        }

        return response()->json($payload);
    }

    /** @return array<string, mixed> */
    private function platform(): array
    {
        $now = now();

        $plans = Plan::query()->pluck('label', 'slug');
        $latestPaid = Invoice::query()
            ->select('account_id', DB::raw('MAX(id) as id'))
            ->where('status', 'paid')
            ->whereIn('plan_key', $plans->keys())
            ->groupBy('account_id');
        $planSplit = Invoice::query()
            ->joinSub($latestPaid, 'latest', fn ($j) => $j->on('invoices.id', '=', 'latest.id'))
            ->select('invoices.plan_key', DB::raw('COUNT(*) as clients'))
            ->groupBy('invoices.plan_key')
            ->pluck('clients', 'plan_key');
        $clients = Account::where('account_type', 'client')->count();

        $split = $plans->map(fn ($label, $slug) => ['plan_key' => $slug, 'label' => $label, 'clients' => (int) ($planSplit[$slug] ?? 0)])->values()->all();
        $onPlan = array_sum(array_column($split, 'clients'));
        $split[] = ['plan_key' => null, 'label' => 'No plan (custom / trial)', 'clients' => max(0, $clients - $onPlan)];

        $top = MessageDispatchLog::query()
            ->select('account_id', DB::raw('COUNT(*) as messages'))
            ->where('created_at', '>=', $now->copy()->subDays(30))
            ->whereNotNull('account_id')
            ->groupBy('account_id')
            ->orderByDesc('messages')
            ->limit(5)
            ->get();
        $names = Account::whereIn('id', $top->pluck('account_id'))->pluck('company_name', 'id');

        return [
            'attention' => [
                'pending_invoices' => Invoice::where('status', 'pending')->count(),
                'quota_requests' => QuotaRequest::where('status', 'pending')->count(),
                'contact_messages' => ContactMessage::whereNull('handled_at')->count(),
                'expiring_7d' => $this->expiringQuery()->count(),
            ],
            'plan_split' => $split,
            'top_clients' => $top->map(fn ($r) => [
                'account_id' => (int) $r->account_id,
                'company_name' => $names[$r->account_id] ?? '—',
                'messages_30d' => (int) $r->messages,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function agent(Account $agent): array
    {
        $clientIds = Account::where('agent_id', $agent->id)->pluck('id');
        $monthStart = now()->startOfMonth();

        return [
            'clients_total' => $clientIds->count(),
            'clients_active' => Account::whereIn('id', $clientIds)->get()->filter(fn (Account $a) => $a->hasActiveSubscription())->count(),
            'commission_month' => round((float) AgentCommission::where('agent_account_id', $agent->id)->where('status', 'confirmed')->where('created_at', '>=', $monthStart)->sum('amount'), 2),
            'commission_total' => round((float) AgentCommission::where('agent_account_id', $agent->id)->where('status', 'confirmed')->sum('amount'), 2),
            'pending_invoices' => Invoice::whereIn('account_id', $clientIds)->where('status', 'pending')->count(),
            'messages_30d' => MessageDispatchLog::whereIn('account_id', $clientIds)->where('created_at', '>=', now()->subDays(30))->count(),
            'expiring' => $this->expiringQuery()->whereIn('id', $clientIds)->with('currentSubscription')->limit(10)->get()
                ->map(fn (Account $a) => [
                    'account_id' => $a->id,
                    'company_name' => $a->company_name,
                    'expires_at' => $a->currentSubscription?->expires_at?->toIso8601String(),
                ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function tenant(Account $account): array
    {
        $caps = $this->access->capabilityMapForAccount($account);
        $sub = $account->currentSubscription;
        $planKey = Invoice::where('account_id', $account->id)->where('status', 'paid')->orderByDesc('paid_at')->orderByDesc('id')->value('plan_key');
        $plan = $planKey ? Plan::where('slug', $planKey)->first(['slug', 'label']) : null;

        $data = [
            'account_id' => $account->id,
            'plan' => $plan ? ['key' => $plan->slug, 'label' => $plan->label] : null,
            'subscription' => $sub ? [
                'status' => $sub->status,
                'billing_model' => $sub->billing_model,
                'engine_type' => $sub->engine_type,
                'expires_at' => $sub->expires_at?->toIso8601String(),
                'messages_left' => $sub->billing_model === 'unlimited' || $sub->total_allocated_messages === null
                    ? null
                    : max(0, (int) $sub->total_allocated_messages - (int) $sub->used_messages),
            ] : null,
            'capabilities' => $caps,
        ];

        if (! empty($caps['crm'])) {
            $byStatus = CrmLead::where('account_id', $account->id)->select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status');
            $data['crm'] = [
                'total' => (int) $byStatus->sum(),
                'new' => (int) ($byStatus[CrmLead::STATUS_NEW] ?? 0),
                'contacted' => (int) ($byStatus[CrmLead::STATUS_CONTACTED] ?? 0),
                'converted' => (int) ($byStatus[CrmLead::STATUS_CONVERTED] ?? 0),
                'new_7d' => CrmLead::where('account_id', $account->id)->where('created_at', '>=', now()->subDays(7))->count(),
            ];
        }

        if (! empty($caps['journey_automation'])) {
            $data['journeys'] = [
                'active' => WhatsAppFlow::where('account_id', $account->id)->where('is_active', true)->count(),
                'total' => WhatsAppFlow::where('account_id', $account->id)->count(),
                'running' => WhatsAppFlowSession::where('account_id', $account->id)->whereIn('status', [WhatsAppFlowSession::STATUS_ACTIVE, WhatsAppFlowSession::STATUS_WAITING])->count(),
                'completed_30d' => WhatsAppFlowSession::where('account_id', $account->id)->where('status', WhatsAppFlowSession::STATUS_COMPLETED)->where('updated_at', '>=', now()->subDays(30))->count(),
            ];
        }

        if (! empty($caps['ai'])) {
            $credits = CreditAccount::where('account_id', $account->id)->first();
            $data['ai'] = [
                'available' => $credits ? $credits->available() : 0,
                'balance' => $credits ? (int) $credits->balance : 0,
            ];
        }

        if (! empty($caps['ads'])) {
            $metrics = AdCampaignDailyMetric::where('account_id', $account->id)->where('metric_date', '>=', now()->subDays(30)->toDateString());
            $spend = (float) (clone $metrics)->sum('spend');
            $leads = (int) (clone $metrics)->sum('leads');
            $data['ads'] = [
                'spend_30d' => round($spend, 2),
                'leads_30d' => $leads,
                'cpl_30d' => $leads > 0 ? round($spend / $leads, 2) : null,
            ];
        }

        return $data;
    }

    private function expiringQuery()
    {
        $now = now();

        return Account::query()->whereHas('currentSubscription', fn ($q) => $q->where('status', 'active')->whereBetween('expires_at', [$now, $now->copy()->addDays(7)]));
    }
}
