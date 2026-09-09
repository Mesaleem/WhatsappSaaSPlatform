<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    private const ENGINE_TYPES = ['qr', 'meta'];
    private const BILLING_MODELS = ['flat_quota', 'per_message', 'unlimited'];
    // 'stripe' added in Module 8 alongside the automated checkout flow —
    // an Admin manually recording a Stripe-settled payment here should be
    // able to select it, same as 'razorpay' already could.
    private const PAYMENT_MODES = ['cash', 'razorpay', 'stripe'];
    private const ACCOUNT_STATUSES = ['active', 'suspended', 'expired'];

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

        $accounts = Account::query()
            ->with(['currentSubscription', 'owner:id,name,email,account_id'])
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
    public function expiringSoon(): JsonResponse
    {
        $now = now();
        $window = $now->copy()->addDays(7);

        $accounts = Account::query()
            ->with(['currentSubscription', 'owner:id,name,email,account_id'])
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
    public function show(int $id): JsonResponse
    {
        $account = Account::with([
            'owner:id,name,email,account_id',
            'subscriptions' => fn ($q) => $q->orderByDesc('starts_at'),
        ])->findOrFail($id);

        $account->subscriptions->each->refreshStatus();
        $account->setRelation('currentSubscription', $account->subscriptions->first());

        return response()->json($account);
    }

    /**
     * POST /api/admin/accounts — creates the Account, its primary Admin user,
     * and its first Subscription record in a single transaction.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'primary_phone' => ['nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(self::ACCOUNT_STATUSES)],

            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', Rule::unique('users', 'email')],
            'admin_password' => ['required', 'string', 'min:8'],

            'engine_type' => ['required', Rule::in(self::ENGINE_TYPES)],
            'billing_model' => ['required', Rule::in(self::BILLING_MODELS)],
            'rate_per_message' => ['required_if:billing_model,per_message', 'nullable', 'numeric', 'min:0'],
            'total_allocated_messages' => ['required_if:billing_model,flat_quota,per_message', 'nullable', 'integer', 'min:1'],
            'price_paid' => ['required', 'numeric', 'min:0'],
            'payment_mode' => ['required', Rule::in(self::PAYMENT_MODES)],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $account = DB::transaction(function () use ($data) {
            $account = Account::create([
                'company_name' => $data['company_name'],
                'primary_phone' => $data['primary_phone'] ?? null,
                'status' => $data['status'] ?? 'active',
            ]);

            $admin = User::create([
                'account_id' => $account->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => $data['admin_password'],
                'is_active' => true,
            ]);
            $admin->assignRole('admin');

            Subscription::create([
                'account_id' => $account->id,
                'engine_type' => $data['engine_type'],
                'billing_model' => $data['billing_model'],
                'rate_per_message' => $data['billing_model'] === 'per_message'
                    ? $data['rate_per_message'] : null,
                'total_allocated_messages' => $data['billing_model'] === 'unlimited'
                    ? null : ($data['total_allocated_messages'] ?? null),
                'used_messages' => 0,
                'price_paid' => $data['price_paid'],
                'payment_mode' => $data['payment_mode'],
                'starts_at' => $data['starts_at'],
                'expires_at' => $data['expires_at'],
                'status' => 'active',
            ]);

            return $account;
        });

        return response()->json(
            $account->load(['currentSubscription', 'owner:id,name,email,account_id']),
            201
        );
    }

    /**
     * PUT /api/admin/accounts/{id} — updates account-level fields only
     * (company_name, primary_phone, status). Subscription/billing fields are
     * handled exclusively by updateSubscription() below.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $account = Account::findOrFail($id);

        $data = $request->validate([
            'company_name' => ['sometimes', 'string', 'max:255'],
            'primary_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(self::ACCOUNT_STATUSES)],
            // Module 9 — requests/minute allowed on this account's
            // external Developer API (Api\V1\*); see AppServiceProvider's
            // 'external-api' rate limiter.
            'api_rate_limit_per_minute' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        $account->update($data);

        return response()->json($account->load(['currentSubscription', 'owner:id,name,email,account_id']));
    }

    /**
     * PUT /api/admin/accounts/{id}/subscription — extend validity, change
     * engine type, switch billing model, or adjust the custom per-message
     * rate on the account's *current* subscription (updated in place).
     */
    public function updateSubscription(Request $request, int $id): JsonResponse
    {
        $account = Account::findOrFail($id);
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
        }

        $subscription->fill($data);
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

        $data = $request->validate([
            'allowed_modules' => ['nullable', 'array'],
            'allowed_modules.*' => ['string', Rule::in(Account::MODULES)],
        ]);

        $account->update(['allowed_modules' => $data['allowed_modules'] ?? null]);

        return response()->json($account->fresh());
    }
}
