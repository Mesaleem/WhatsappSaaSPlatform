<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccountService;
use App\Services\QuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $accountService,
        private readonly QuotaService $quotaService,
    ) {
    }

    private const ENGINE_TYPES = ['qr', 'meta'];
    private const BILLING_MODELS = ['flat_quota', 'per_message', 'unlimited'];
    // 'stripe' added in Module 8 alongside the automated checkout flow —
    // an Admin manually recording a Stripe-settled payment here should be
    // able to select it, same as 'razorpay' already could.
    private const PAYMENT_MODES = ['cash', 'razorpay', 'stripe'];
    private const ACCOUNT_STATUSES = ['active', 'suspended', 'expired'];

    /**
     * 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 1) — non-null
     * only when the caller's OWN account is an Agent (Reseller) and the
     * caller is not Super Admin: the agent_id every cross-tenant Account
     * query/lookup in this controller must then be constrained to, so an
     * Agent can only ever see/manage its own sub-clients. Always null
     * for Super Admin (unrestricted) and for a plain client (who has no
     * `manage-accounts` reach on this controller at all today — see
     * TenantIsolationMiddleware's docblock for the disclosed permission
     * gap this phase does not close).
     */
    private function callerAgentScopeId(Request $request): ?int
    {
        $user = $request->user();

        if (! $user || $user->isSuperAdmin()) {
            return null;
        }

        return $user->account?->account_type === 'agent' ? $user->account_id : null;
    }

    /**
     * 3-Tier Hierarchy (Phase 1) — guards every {id}-addressed action
     * (show/update/updateSubscription/updatePermissions) so an Agent
     * caller gets the SAME 404 for "doesn't exist" and "exists but isn't
     * yours" — deliberate, matching the non-disclosure precedent
     * TenantIsolationMiddleware already applies to a Super Admin's own
     * bad ?account_id=.
     */
    private function assertCallerCanAccessAccount(Request $request, Account $account): void
    {
        $agentScopeId = $this->callerAgentScopeId($request);

        abort_if($agentScopeId !== null && $account->agent_id !== $agentScopeId, 404);
    }

    /**
     * GET /api/admin/accounts — paginated accounts with current subscription,
     * usage, rate/engine, and owner (primary Admin) details.
     *
     * UI Standardization — Data Table Standardization: ?search filters by
     * company_name/primary_phone (the frontend's real-time search bar,
     * debounced client-side); ?status filters by the account's own status
     * (the status filter dropdown), independent of its subscription status.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 15), 100);

        // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 1).
        $isSuperAdmin = (bool) $request->user()?->isSuperAdmin();
        $agentScopeId = $this->callerAgentScopeId($request);

        $accounts = Account::query()
            ->with(['currentSubscription', 'owner:id,name,email,account_id', 'agent:id,company_name,account_type'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(function ($q) use ($search) {
                    $q->where('company_name', 'like', "%{$search}%")
                        ->orWhere('primary_phone', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->string('status')->toString());
            })
            // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 1) —
            // ?agent_id= is honored only for Super Admin, per this
            // phase's spec; an Agent caller's own forced scope below
            // always wins instead (a client-supplied ?agent_id= from an
            // Agent is silently ignored rather than trusted, mirroring
            // TenantIsolationMiddleware's own "a regular user's
            // ?account_id= override is never honored" precedent).
            ->when($isSuperAdmin && $request->filled('agent_id'), function ($query) use ($request) {
                $query->ownedByAgent($request->integer('agent_id'));
            })
            ->when($agentScopeId, function ($query, $scopeId) {
                $query->ownedByAgent($scopeId);
            })
            // 3-Tier Hierarchy (Phase 3 UI) — Super Admin's "Filter by
            // Agent" dropdown sends ?agent_id= (above, unchanged) for
            // narrowing the Clients table to one Agent's Sub-Clients;
            // this new ?account_type= is what AccountsPage now sends
            // as account_type=client on every load so an Agent-type
            // account row can never leak into that "Clients" table
            // (index() previously returned every account_type
            // undifferentiated — a pre-existing gap this phase's UI
            // surfaced, not a regression), and what the Parent
            // Agent / Filter-by-Agent dropdowns send as
            // account_type=agent to list Agents themselves. Restricted
            // to the two real values a caller ever has a reason to ask
            // for; 'super_admin' is excluded since no Account row is
            // expected to carry it (see the Phase 1 migration's
            // docblock) and validating against it would just always
            // return zero rows. Super-Admin-only, same as ?agent_id=
            // above — an Agent caller's own forced ownedByAgent() scope
            // already fully determines which accounts they see, so
            // honoring this for them too would only let them narrow
            // their own results, but not widen them; still ignored for
            // consistency with the existing agent_id precedent.
            ->when(
                $isSuperAdmin && $request->filled('account_type') && in_array($request->string('account_type')->toString(), ['agent', 'client'], true),
                fn ($query) => $query->where('account_type', $request->string('account_type')->toString())
            )
            // Universal Data Table Audit — Date Range Pickers: filters on
            // the account's own provisioning date (created_at), the same
            // field the table's UI has no other way to slice by.
            ->when($request->filled('from'), function ($query) use ($request) {
                $query->whereDate('created_at', '>=', $request->date('from'));
            })
            ->when($request->filled('to'), function ($query) use ($request) {
                $query->whereDate('created_at', '<=', $request->date('to'));
            })
            ->latest('id')
            ->paginate($perPage);

        $accounts->getCollection()->each(
            fn (Account $account) => $account->currentSubscription?->refreshStatus()
        );

        return response()->json($accounts);
    }

    /**
     * GET /api/admin/accounts/expiring-soon — Super Admin Dashboard's
     * "Expiring in 7 Days" metric card + its detail modal. Window is
     * [now, now+7 days] inclusive, current-subscription-status 'active'
     * only (an already-expired/suspended account belongs in the general
     * accounts list, not this upcoming-renewal list — this endpoint is
     * specifically for catching renewals BEFORE they lapse). Ordered
     * soonest-expiring first so the most urgent renewal is always at the
     * top of the modal.
     */
    public function expiringSoon(Request $request): JsonResponse
    {
        $now = now();
        $window = $now->copy()->addDays(7);

        // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 1) — same
        // forced isolation as index() above.
        $agentScopeId = $this->callerAgentScopeId($request);

        $accounts = Account::query()
            ->with(['currentSubscription', 'owner:id,name,email,account_id'])
            ->when($agentScopeId, fn ($query, $scopeId) => $query->ownedByAgent($scopeId))
            ->whereHas('currentSubscription', function ($q) use ($now, $window) {
                $q->where('status', 'active')->whereBetween('expires_at', [$now, $window]);
            })
            ->get();

        $accounts->each(fn (Account $account) => $account->currentSubscription?->refreshStatus());

        $today = $now->copy()->startOfDay();

        $rows = $accounts
            ->filter(fn (Account $account) => $account->currentSubscription?->status === 'active')
            ->map(function (Account $account) use ($today) {
                $sub = $account->currentSubscription;

                return [
                    'account_id' => $account->id,
                    'company_name' => $account->company_name,
                    'plan_label' => ucwords(str_replace('_', ' ', $sub->billing_model)).' ('.strtoupper($sub->engine_type).')',
                    'expires_at' => $sub->expires_at,
                    // Calendar-day distance (not a raw hour count), so
                    // "expires in 6 hours" and "expires in 6 hours,
                    // tomorrow" both read as sensible whole-day counts
                    // rather than both rounding to "0 days".
                    'days_remaining' => max(0, $today->diffInDays($sub->expires_at->copy()->startOfDay())),
                ];
            })
            ->sortBy('days_remaining')
            ->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /api/admin/accounts/{id} — full profile with subscription history.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = Account::with([
            'owner:id,name,email,account_id',
            'agent:id,company_name,account_type',
            'subscriptions' => fn ($q) => $q->orderByDesc('starts_at'),
        ])->findOrFail($id);

        // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 1).
        $this->assertCallerCanAccessAccount($request, $account);

        $account->subscriptions->each->refreshStatus();
        $account->setRelation('currentSubscription', $account->subscriptions->first());

        // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 2) — API
        // Response Audit: effective_modules alongside allowed_modules so
        // the frontend can render active-vs-inherited without
        // recomputing the hierarchy intersection itself.
        $account->setAttribute('effective_modules', $account->effectiveModules());

        // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 4) — Agent
        // Quota Pool & Allocation: how much of the VIEWING Agent's own
        // pool is still unallocated, computed as if this Sub-Client's
        // current allocation were freed up (so re-saving the same value
        // in UpdateQuotaModal never falsely reads as "over pool"). Only
        // meaningful, and only present at all, for an Agent caller —
        // absent (not null) for Super Admin, who has no pool ceiling of
        // their own to report.
        $agentScopeId = $this->callerAgentScopeId($request);
        if ($agentScopeId !== null) {
            $agentAccount = Account::findCached($agentScopeId);
            $account->setAttribute(
                'agent_remaining_pool',
                $agentAccount ? $this->quotaService->remainingPool($agentAccount, $account->id) : null,
            );
        }

        return response()->json($account);
    }

    /**
     * POST /api/admin/accounts — creates the Account, its primary Admin user,
     * and its first Subscription record in a single transaction.
     */
    public function store(Request $request): JsonResponse
    {
        // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 2) — resolved
        // BEFORE validation on purpose: account_type/agent_id are only
        // added to the validation rules at all for Super Admin (below).
        // An Agent's submitted values for either are never trusted (same
        // precedent as every other spoofable param this controller
        // already defends — ?account_id=, ?agent_id=) and are force-set
        // after validation regardless — so validating them for an Agent
        // caller would only produce a confusing 422 for a value that was
        // always going to be discarded, e.g. a garbage agent_id that
        // fails Rule::exists() even though it's about to be overridden
        // with the Agent's own account_id anyway.
        $agentScopeId = $this->callerAgentScopeId($request);
        $isSuperAdmin = (bool) $request->user()?->isSuperAdmin();

        $rules = [
            'company_name' => ['required', 'string', 'max:255'],
            'primary_phone' => ['nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(self::ACCOUNT_STATUSES)],

            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', Rule::unique('users', 'email')],
            // Corrected Unified Client & Admin User Creation — Section 2
            // (Primary Client Admin Credentials). Nullable: the Account
            // schema itself has no phone field of its own (spec's
            // explicit note), so this is stored on the created admin
            // User's phone_number column, same as any other team member.
            'admin_phone' => ['nullable', 'string', 'max:32'],
            'admin_password' => ['required', 'string', 'min:8'],

            'engine_type' => ['required', Rule::in(self::ENGINE_TYPES)],
            'billing_model' => ['required', Rule::in(self::BILLING_MODELS)],
            'rate_per_message' => ['required_if:billing_model,per_message', 'nullable', 'numeric', 'min:0'],
            // Per-Message Wallet Auto-Calc, disclosed: no longer required
            // (or even honored — see the create-time assignment below) for
            // 'per_message'. That billing model's quota is now ALWAYS
            // server-computed as floor(price_paid / rate_per_message), so
            // the admin-typed pair (price paid, rate) can never disagree
            // with the message count actually granted, the way it could
            // before this fix (e.g. ₹100 paid at ₹0.15/msg allocating only
            // 100 messages — worth ₹15 — instead of the 666 actually paid for).
            'total_allocated_messages' => ['required_if:billing_model,flat_quota', 'nullable', 'integer', 'min:1'],
            'price_paid' => ['required', 'numeric', 'min:0'],
            'payment_mode' => ['required', Rule::in(self::PAYMENT_MODES)],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:starts_at'],
            // Client Module Accessibility Checklist — optional at
            // creation (omitted/null = every module enabled, same
            // "null means everything on" default updatePermissions()
            // already uses). Lets Super Admin set the feature checklist
            // in the same Create Client form instead of only after the
            // client already exists.
            'allowed_modules' => ['nullable', 'array'],
            'allowed_modules.*' => ['string', Rule::in(Account::MODULES)],
            // Corrected Unified Client & Admin User Creation — Section 1
            // (Client Organization Details).
            'max_users_limit' => ['nullable', 'integer', 'min:1'],
            'module_assignment' => ['required', Rule::in(Account::MODULE_ASSIGNMENTS)],
        ];

        if ($isSuperAdmin) {
            // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 2) —
            // only Super Admin's account_type/agent_id are ever validated
            // (and therefore only ever present in $data below); see this
            // method's opening comment for why an Agent's own submitted
            // values skip validation entirely rather than being rejected.
            $rules['account_type'] = ['sometimes', Rule::in(['agent', 'client'])];
            $rules['agent_id'] = ['sometimes', 'nullable', 'integer', Rule::exists('accounts', 'id')->where('account_type', 'agent')];
        }

        $data = $request->validate($rules);

        // Who is allowed to set account_type/agent_id, and what an Agent
        // caller is forced to regardless of what it submitted.
        if ($agentScopeId !== null) {
            // An Agent can only ever create a Client that is its OWN
            // sub-client — account_type from an Agent caller is silently
            // ignored/forced, never trusted, and agent_id is always the
            // caller's own account_id, never whatever (if anything) was
            // submitted. Agents do not create other Agents in this phase.
            $data['account_type'] = 'client';
            $data['agent_id'] = $agentScopeId;
        } elseif (! $isSuperAdmin) {
            // Reachable only once some future role/permission actually
            // lets a non-Agent, non-Super-Admin caller reach this route
            // at all (not possible today — see
            // TenantIsolationMiddleware's docblock) — fails closed to a
            // plain, unattached client rather than trusting the request.
            $data['account_type'] = 'client';
            $data['agent_id'] = null;
        } else {
            // Super Admin: trusted as submitted. Omitted account_type
            // defaults to 'client' (the column's own DB default);
            // omitted/null agent_id stays null (a direct platform client).
            $data['account_type'] = $data['account_type'] ?? 'client';
            $data['agent_id'] = $data['agent_id'] ?? null;
        }

        // Module 5 Fix (2026-09-16) - closes a gap this audit found: the
        // Rule::exists('accounts','id')->where('account_type','agent')
        // rule above only validates that agent_id POINTS AT a real
        // Agent - it never stopped a Super Admin from ALSO submitting
        // account_type=agent for the account being created, which would
        // create an Agent nested under another Agent. That directly
        // contradicts this method's own documented invariant a few lines
        // up ("Agents do not create other Agents in this phase") and
        // update()'s own promotion-branch comment below ("An Agent is
        // always top-level, directly under Super Admin - never itself a
        // Sub-Client of another Agent"). The Agent-caller and
        // non-Super-Admin branches above can never hit this (both force
        // account_type='client' unconditionally), so this only ever
        // fires for a Super Admin explicitly submitting both fields.
        abort_if(
            $data['account_type'] === 'agent' && $data['agent_id'] !== null,
            422,
            'An Agent account cannot itself be assigned to a parent Agent.'
        );

        // Hierarchical Module Delegation Engine (Phase 2) — an Agent can
        // never grant its new Sub-Client a module it doesn't itself hold.
        // No-op (returns $data['allowed_modules'] unchanged) for every
        // caller except an Agent — see AccountService::resolveDelegatedModules().
        $data['allowed_modules'] = $this->accountService->resolveDelegatedModules(
            $agentScopeId !== null ? $request->user()->account : null,
            $data['allowed_modules'] ?? null,
        );

        $account = DB::transaction(function () use ($data) {
            $account = Account::create([
                'company_name' => $data['company_name'],
                'primary_phone' => $data['primary_phone'] ?? null,
                'status' => $data['status'] ?? 'active',
                'allowed_modules' => $data['allowed_modules'] ?? null,
                'max_users_limit' => $data['max_users_limit'] ?? null,
                'module_assignment' => $data['module_assignment'],
                // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 2).
                'account_type' => $data['account_type'],
                'agent_id' => $data['agent_id'],
            ]);

            // Corrected Unified Client & Admin User Creation — the Account
            // record is created first, then the initial admin User is
            // mapped to it via account_id, both inside this same
            // transaction (already the case before this refactor —
            // verified by reading this method prior to editing it).
            $admin = User::create([
                'account_id' => $account->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'phone_number' => $data['admin_phone'] ?? null,
                'password' => $data['admin_password'],
                'is_active' => true,
            ]);
            $admin->assignRole('admin');

            // 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 2) — an
            // Agent account's primary user ADDITIONALLY gets the 'agent'
            // role, which is what actually lets it pass
            // routes/api.php's permission:manage-accounts gate on
            // /api/admin/accounts at all (seeded in RolePermissionSeeder).
            // 'admin' above, unchanged, still covers this user's own
            // normal account operations (billing, team, WhatsApp setup,
            // ...) exactly like any other account's primary Admin.
            if ($account->account_type === 'agent') {
                $admin->assignRole('agent');
            }

            // Group Messaging Step 1 — every new tenant starts with
            // exactly one default contact group, inside the same
            // transaction as the rest of account creation so it can never
            // exist without its account (or vice versa).
            ContactGroup::create([
                'account_id' => $account->id,
                'name' => 'All Contacts',
                'is_default' => true,
            ]);

            Subscription::create([
                'account_id' => $account->id,
                'engine_type' => $data['engine_type'],
                'billing_model' => $data['billing_model'],
                'rate_per_message' => $data['billing_model'] === 'per_message'
                    ? $data['rate_per_message'] : null,
                // Per-Message Wallet Auto-Calc, disclosed: for
                // 'per_message', total_allocated_messages is ALWAYS
                // floor(price_paid / rate_per_message) — never the raw
                // client-submitted value (locked server-side, not just in
                // the admin form, so a direct API call can't recreate the
                // price/rate/quota mismatch this fix closes). Rounds DOWN:
                // the client is never granted more messages than what was
                // actually paid for; any fractional remainder (e.g. the
                // ₹0.005 left over from ₹100 ÷ ₹0.15) is not redeemable,
                // matching flat_quota/unlimited's own "exact, no
                // rounding-in-the-client's-favor" precedent.
                'total_allocated_messages' => match ($data['billing_model']) {
                    'unlimited' => null,
                    'per_message' => $data['rate_per_message'] > 0
                        ? (int) floor($data['price_paid'] / $data['rate_per_message'])
                        : 0,
                    default => $data['total_allocated_messages'] ?? null,
                },
                'used_messages' => 0,
                'price_paid' => $data['price_paid'],
                'payment_mode' => $data['payment_mode'],
                'starts_at' => $data['starts_at'],
                'expires_at' => $data['expires_at'],
                'status' => 'active',
            ]);

            return $account;
        });

        // Module 5 Fix (2026-09-16) - index()/show() already eager-load
        // the agent relation; this create response didn't, so a Super
        // Admin creating a client under an Agent had to issue a
        // follow-up GET just to see which Agent it landed under. Same
        // field list as index()/show() for consistency.
        return response()->json(
            $account->load(['currentSubscription', 'owner:id,name,email,account_id', 'agent:id,company_name,account_type']),
            201
        );
    }

    /**
     * PUT /api/admin/accounts/{id} — updates account-level fields only
     * (company_name, primary_phone, status, branding). Subscription/
     * billing fields are handled exclusively by updateSubscription() below.
     *
     * logo_url/brand_accent_color: Social Media Marketing & Meta Ads
     * Automation Expansion (Final Phase) — White-Label Automated PDF
     * Reporting. See the creating migration's docblock for why these are
     * Super-Admin-set here rather than through a tenant self-service
     * endpoint (none exists for any account field). brand_accent_color
     * is validated as a bare 6-hex-digit string (no leading '#') so
     * SocialReportController's hex-to-RGB parsing never has to guess a
     * format; the frontend strips '#' before sending.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $account = Account::findOrFail($id);
        $this->assertCallerCanAccessAccount($request, $account);

        $isSuperAdmin = (bool) $request->user()?->isSuperAdmin();

        $rules = [
            'company_name' => ['sometimes', 'string', 'max:255'],
            'primary_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(self::ACCOUNT_STATUSES)],
            // Module 9 — requests/minute allowed on this account's
            // external Developer API (Api\V1\*); see AppServiceProvider's
            // 'external-api' rate limiter.
            'api_rate_limit_per_minute' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'logo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'brand_accent_color' => ['sometimes', 'nullable', 'regex:/^[0-9a-fA-F]{6}$/'],
            // Super Admin Client Provisioning refactor — the spec's literal
            // text scopes max_users_limit/module_assignment to the Create
            // Client form only, but leaving them create-only here would mean
            // a Super Admin could never raise a client's user cap or change
            // their business category later. Same update() endpoint, same
            // permission:manage-accounts gate as every other account-level
            // field above — a disclosed, deliberate completion, not scope
            // creep. See this refactor's audit report.
            'max_users_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'module_assignment' => ['sometimes', Rule::in(Account::MODULE_ASSIGNMENTS)],
            // Social/Ads Launcher Overhaul — Step 1. Per-tenant AI
            // provider key override; see accounts.gemini_api_key's
            // migration docblock. Same Super-Admin-only tier as every
            // other field in this endpoint. Empty string clears the
            // override (falls back to the platform/env key) rather than
            // storing an empty secret.
            'gemini_api_key' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];

        // 3-Tier Hierarchy & Agent-Client Scope Engine — Existing-Account
        // Conversion. store()'s docblock previously said update() had "no
        // path to change account_type/agent_id after creation"; that was a
        // deliberate Phase-3 scope cut, not a permanent limitation — this
        // closes it. Super-Admin-only (never an Agent, even one editing
        // its own Sub-Client, same precedent as account_type in store()):
        // an Agent could otherwise promote its own Sub-Client into a
        // peer Agent, escaping its scope entirely.
        if ($isSuperAdmin) {
            $rules['account_type'] = ['sometimes', Rule::in(['agent', 'client'])];
            $rules['agent_id'] = ['sometimes', 'nullable', 'integer', Rule::exists('accounts', 'id')->where('account_type', 'agent')];
        }

        $data = $request->validate($rules);

        $isConvertingType = array_key_exists('account_type', $data) && $data['account_type'] !== $account->account_type;
        $newType = $data['account_type'] ?? $account->account_type;

        // Module 5 Fix (2026-09-16) - closes the same gap as store()'s
        // identical new guard above, for the one path store()'s
        // equivalent can't cover: an account that is ALREADY an Agent
        // (so $isConvertingType is false - account_type isn't changing)
        // having its agent_id explicitly set to a real value. Left
        // strictly narrower than the promotion branch just below on
        // purpose: promoting a Client TO Agent silently clears a stray
        // agent_id (the request's real intent was "make this an Agent",
        // not "nest it"), but explicitly setting agent_id on an account
        // that is already an Agent is deliberate input a silent
        // override would just discard without explanation - rejecting
        // it outright is the more honest response.
        abort_if(
            $newType === 'agent' && ! $isConvertingType && array_key_exists('agent_id', $data) && $data['agent_id'] !== null,
            422,
            'An Agent account cannot itself be assigned to a parent Agent.'
        );

        if ($isConvertingType) {
            if ($newType === 'agent') {
                // Promoting an existing Client to Agent (Reseller). An
                // Agent is always top-level, directly under Super Admin —
                // never itself a Sub-Client of another Agent ("Agents do
                // not create other Agents" — store()'s own docblock) — so
                // this clears agent_id regardless of what (if anything)
                // was submitted alongside it.
                $data['agent_id'] = null;
            } else {
                // Demoting an existing Agent back to a plain Client.
                // Refused if it still has Sub-Clients of its own —
                // demoting it would leave those Sub-Clients' agent_id
                // pointing at an account that is no longer an Agent,
                // silently breaking every agent_id-scoped check in the
                // app (module delegation, quota pool, quota-request
                // routing). Reassign or remove them first.
                abort_if(
                    Account::where('agent_id', $account->id)->exists(),
                    422,
                    'This account still has its own Sub-Clients. Reassign or remove them before demoting it back to a Client.'
                );
            }
        }

        DB::transaction(function () use ($account, $data, $isConvertingType, $newType) {
            $account->update($data);

            if (! $isConvertingType) {
                return;
            }

            // The 'agent' Spatie role (not just account_type) is what
            // actually lets this account's owner user pass
            // permission:manage-accounts on /api/admin/accounts and the
            // other Agent-widened gates (Route Master's permissions-tree,
            // Quota Top-Up Requests) — mirrors store()'s own
            // $admin->assignRole('agent') exactly. 'admin' is left
            // untouched either way: it already covers this user's own
            // normal account operations regardless of account_type.
            $owner = $account->owner;

            if (! $owner) {
                return;
            }

            if ($newType === 'agent') {
                $owner->assignRole('agent');
            } else {
                $owner->removeRole('agent');
            }
        });

        // Module 5 Fix (2026-09-16) - same consistency fix as store()'s
        // response above - this endpoint is exactly where agent_id can
        // change, so the response should show the resulting agent
        // relationship without a follow-up GET.
        return response()->json($account->fresh()->load(['currentSubscription', 'owner:id,name,email,account_id', 'agent:id,company_name,account_type']));
    }

    /**
     * PUT /api/admin/accounts/{id}/subscription — extend validity, change
     * engine type, switch billing model, or adjust the custom per-message
     * rate on the account's *current* subscription (updated in place).
     */
    public function updateSubscription(Request $request, int $id): JsonResponse
    {
        $account = Account::findOrFail($id);
        $this->assertCallerCanAccessAccount($request, $account);

        $subscription = $account->currentSubscription;

        if (! $subscription) {
            return response()->json([
                'message' => 'This account has no subscription to update.',
            ], 404);
        }

        $data = $request->validate([
            'engine_type' => ['sometimes', Rule::in(self::ENGINE_TYPES)],
            'billing_model' => ['sometimes', Rule::in(self::BILLING_MODELS)],
            'rate_per_message' => ['nullable', 'numeric', 'min:0'],
            'total_allocated_messages' => ['nullable', 'integer', 'min:1'],
            'price_paid' => ['sometimes', 'numeric', 'min:0'],
            'payment_mode' => ['sometimes', Rule::in(self::PAYMENT_MODES)],
            'starts_at' => ['sometimes', 'date'],
            'expires_at' => ['sometimes', 'date'],
        ]);

        $billingModel = $data['billing_model'] ?? $subscription->billing_model;

        if (
            $billingModel === 'per_message'
            && ! array_key_exists('rate_per_message', $data)
            && $subscription->rate_per_message === null
        ) {
            return response()->json([
                'message' => 'rate_per_message is required when billing_model is per_message.',
                'errors' => ['rate_per_message' => ['This field is required for per_message billing.']],
            ], 422);
        }

        if ($billingModel === 'unlimited') {
            $data['total_allocated_messages'] = null;
            $data['rate_per_message'] = null;
        } elseif ($billingModel === 'flat_quota') {
            $data['rate_per_message'] = null;
        } elseif ($billingModel === 'per_message') {
            // Per-Message Wallet Auto-Calc, disclosed: same server-owned
            // floor(price_paid / rate_per_message) as store() above,
            // overriding whatever total_allocated_messages the caller
            // sent (or omitted) — this is a PATCH-style endpoint, so
            // "effective" rate/price fall back to the subscription's
            // current values when this request doesn't touch that field,
            // mirroring the rate_per_message-required check just above.
            $effectiveRate = (float) ($data['rate_per_message'] ?? $subscription->rate_per_message);
            $effectivePrice = (float) ($data['price_paid'] ?? $subscription->price_paid);
            $data['total_allocated_messages'] = $effectiveRate > 0
                ? (int) floor($effectivePrice / $effectiveRate)
                : 0;
        }

        $subscription->fill($data);
        $subscription->save();
        $subscription->refreshStatus();

        return response()->json($subscription->fresh());
    }

    /**
     * PUT /api/admin/accounts/{id}/quota — 3-Tier Hierarchy & Agent-Client
     * Scope Engine (Phase 4): Agent Quota Pool & Allocation. Deliberately
     * narrower than updateSubscription() above (numeric quota only — no
     * engine/billing/pricing fields): its whole purpose is the pool-limit
     * check below, which only makes sense against a single well-defined
     * number, not an arbitrary subscription mutation (e.g. switching a
     * Sub-Client to 'unlimited' here would make "how much of my pool is
     * left" undefined — that combination stays on the existing broader
     * endpoint, Super-Admin-oriented as before).
     *
     * The pool-limit validation itself only ever runs for an Agent caller
     * (assertWithinPool() is a no-op otherwise) — a Super Admin has no
     * pool of their own to be bounded by, matching every other Super-
     * Admin-vs-Agent asymmetry already established in Phase 1/2 (e.g.
     * AccountService::resolveDelegatedModules()).
     */
    public function updateQuota(Request $request, int $id): JsonResponse
    {
        $account = Account::findOrFail($id);
        $this->assertCallerCanAccessAccount($request, $account);

        $data = $request->validate([
            'total_allocated_messages' => ['required', 'integer', 'min:1'],
        ]);

        $agentScopeId = $this->callerAgentScopeId($request);
        if ($agentScopeId !== null) {
            $agentAccount = Account::findOrFail($agentScopeId);
            $this->quotaService->assertWithinPool($agentAccount, $data['total_allocated_messages'], $account->id);
        }

        $subscription = $account->currentSubscription;

        if (! $subscription) {
            return response()->json([
                'message' => 'This account has no subscription to allocate a quota against.',
            ], 404);
        }

        $subscription->total_allocated_messages = $data['total_allocated_messages'];
        $subscription->save();
        $subscription->refreshStatus();

        return response()->json($subscription->fresh());
    }

    /**
     * PATCH /api/admin/accounts/{id}/permissions — Absolute Super Admin
     * Control: dynamically start/stop specific feature modules for a
     * client. allowed_modules=null resets the account to "every module
     * enabled" (the default for every account until a Super Admin
     * explicitly narrows it) — see Account::hasModuleEnabled().
     */
    public function updatePermissions(Request $request, int $id): JsonResponse
    {
        $account = Account::findOrFail($id);
        $this->assertCallerCanAccessAccount($request, $account);

        $data = $request->validate([
            'allowed_modules' => ['nullable', 'array'],
            'allowed_modules.*' => ['string', Rule::in(Account::MODULES)],
        ]);

        // Hierarchical Module Delegation Engine (Phase 2) — an Agent
        // updating one of its OWN sub-clients (assertCallerCanAccessAccount
        // above already guarantees that's the only $account reachable
        // here for a non-Super-Admin) can never grant a module it
        // doesn't itself currently hold. No-op for Super Admin — see
        // AccountService::resolveDelegatedModules().
        $agentScopeId = $this->callerAgentScopeId($request);

        $account->update([
            'allowed_modules' => $this->accountService->resolveDelegatedModules(
                $agentScopeId !== null ? $request->user()->account : null,
                $data['allowed_modules'] ?? null,
            ),
        ]);

        return response()->json($account->fresh());
    }
}
