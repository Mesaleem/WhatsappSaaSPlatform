# Quota Exhaustion Request Workflow & Custom Invoice Generation — Audit Report

**Role:** Principal SaaS Billing & Full-Stack Architect
**Scope:** `backend-api/`, `frontend-app/`
**Status:** Implemented. Migration file created but **NOT executed** (see §6 — explicit authorization required).

---

## 1. Schema corrections (disclosed, [Fact])

The spec's wording does not match this codebase's real schema in two places. Both were implemented against the **real** schema, not the literal spec wording, with the correction disclosed here and in code docblocks.

| Spec said | Reality | What was built |
|---|---|---|
| Increment `accounts.message_quota_limit` | No such column exists anywhere in `Account.php` or any `accounts` migration. Quota is modeled per-**subscription** (`Subscription::total_allocated_messages` / `used_messages`, via `Account::currentSubscription()`) — every existing quota read path (`AnalyticsController`, `ChatbotEngineService`, `BillingPage`) already reads from there. | `approve()` increments `Subscription::total_allocated_messages` on the account's current subscription. |
| Invoice status `unpaid` / `pending_payment` | `invoices.status` enum is `'pending' \| 'paid' \| 'failed'` (migration, `InvoiceCreditService`, `PaymentGatewayController`, `BillingPage`'s badge map). | New top-up invoices are created with `status: 'pending'`, consistent with every existing invoice row. |

`BillingPage.tsx` also lives at `frontend-app/src/pages/billing/BillingPage.tsx`, not `pages/admin/BillingPage.tsx` as stated — edited at its real path (Super Admin's variant of that page renders `ClientBillingSummaryTable`, not this tenant self-service UI — see §4).

## 2. Backend — new files

- **`database/migrations/2026_09_11_120000_create_quota_requests_table.php`** — `quota_requests` table: `account_id`, `requested_by` (FK users), `requested_extra_messages` (unsigned int), `reason` (nullable text), `status` (`pending|approved|rejected`, default `pending`), `reviewed_by` (nullable FK users), `reviewed_at`, `invoice_id` (nullable FK invoices, `nullOnDelete`), indexes on `[account_id, status]` and `[status, created_at]`. **Not migrated** — see §6.
- **`app/Models/QuotaRequest.php`** — fillable/casts, `account()`/`requestedBy()`/`reviewedBy()`/`invoice()` relations, `isPending()` helper.
- **`app/Http/Controllers/Api/QuotaRequestController.php`**:
  - `store()` — Client Admin submits a request. Blocks Super Admin (no tenant account). Validates the subscription is `flat_quota` (422 otherwise — `unlimited`/`per_message` have nothing finite to top up). Validates `requested_extra_messages` (1–1,000,000) and optional `reason`.
  - `index()` — Super Admin list, `?status=` filter (default `pending`), paginated, eager-loads `account:id,company_name` and `requestedBy:id,name,email`.
  - `approve()` — Super Admin only. In one `DB::transaction()`: increments `total_allocated_messages`, computes amount/tax/total (see §3), creates the `Invoice` row, marks the `QuotaRequest` `approved` with `reviewed_by`/`reviewed_at`/`invoice_id`. A mid-transaction failure can never leave quota credited without an invoice or vice versa.
  - No `reject()` action was implemented — the spec's endpoint list names only `store`/`index`/`approve`. The `rejected` status value exists in the schema for forward compatibility but nothing currently sets it.

## 3. Disclosed assumptions ([Hypothesis])

- **Pricing rate**: no top-up rate exists anywhere in `PlanCatalog` (which only prices whole plans) or elsewhere. `QuotaRequestController::RATE_PER_EXTRA_MESSAGE = 1.00` (INR/message) is a placeholder constant, one line to change once a real rate is set — same disclosed-constant pattern `PaymentGatewayController::TAX_RATE` already uses. `TAX_RATE = 0.18` mirrors that existing constant exactly.
- **Gateway on the generated invoice**: `invoices.payment_gateway` is `NOT NULL`, but a Super-Admin-approved top-up has no real gateway transaction behind it. `approve()` picks whichever gateway is actually enabled+configured (`PaymentGatewaySetting::enabledGatewaysCached()->first(isFullyConfigured())`), falling back to the literal string `'razorpay'` if none is configured.
- **"Pay Invoice" / "View Bill" scope**: these labels replace the existing PDF-download button's text (`pending` → "Pay Invoice", `paid` → "View Bill") on the *same* download action. No new payment-capture flow was built — `PaymentGatewayController::createOrder()` is plan-key-driven only; generalizing it to accept an arbitrary existing invoice ID is a separate, payment-critical change out of this task's scope. Clicking either label downloads the real invoice PDF today.

## 4. Routing — the critical fix

`POST /quota-requests/store` is placed in the **same middleware group as `/billing/*`** (`tenant.isolation` + `permission:manage-subscriptions`, deliberately **outside** `subscription.guard`). Root cause: the moment this feature is most needed is exactly when `Subscription::computeStatus()` has flipped to `'exhausted'`, which makes `Account::hasActiveSubscription()` false. Under the ordinary `tenant.isolation + subscription.guard` combo every other tenant route uses, this very POST would itself 403 at 100% quota usage — the literal trigger case named in the spec. This mirrors why `/billing/*` was placed the same way.

`GET /admin/quota-requests` and `POST /admin/quota-requests/{id}/approve` sit under `permission:manage-billing-settings`, the same tier as `/admin/billing/*` (gateway/mail settings) — approving mutates a tenant's subscription and generates a real invoice, which is billing administration.

## 5. Frontend

- **`types/quotaRequest.ts`**, **`services/quotaRequestService.ts`** — new, following this codebase's per-resource type/service convention. Note: `QuotaRequest.requestedBy` (camelCase) is the JSON key Eloquent serializes the eager-loaded relation under — `requested_by` (snake_case) stays the raw FK integer column; both keys coexist in the same response object, this is not a naming inconsistency.
- **`components/billing/QuotaTopUpModal.tsx`** — new. Fields: Requested Extra Messages, Reason/Notes, Submit. Same fixed-overlay modal shell as the existing `StripeCardModal`.
- **`pages/admin/QuotaRequestsPage.tsx`** — new. Super Admin review/approval queue, modeled on `AuditLogsPage.tsx`'s table/filter/pagination conventions. Registered at `/admin/quota-requests` (`App.tsx`, gated `permission="manage-billing-settings"`) and added to `AppLayout.tsx`'s `NAV_ITEMS` (`superAdminOnly: true`, reusing the `billing` nav tint).
- **`pages/DashboardPage.tsx`** (`SubscriptionHealthCard`) — added a "Request Extra Quota" button, shown when `billing_model === 'flat_quota' && quotaPercentUsed >= 90`. The card is a `<Link to="/billing">`; the button calls `e.preventDefault()`/`e.stopPropagation()` so it opens the modal instead of navigating. Local toast on success (existing codebase pattern: `useState` + 4s `setTimeout`).
- **`pages/billing/BillingPage.tsx`** (Tenant Admin view only — Super Admin's variant of this page renders `ClientBillingSummaryTable` and was left untouched, since Super Admin has no tenant subscription of their own to top up) — same conditional button added to the "Current Plan Status" card; submission success reuses the existing `checkoutSuccess` banner state. Invoice History action label changed per §3.
- **Approval success toast**: the spec's exact text — *"Extra quota added to your account. Invoice generated under Billing & Plans."* — is shown on `QuotaRequestsPage` to the **Super Admin performing the approval**, not pushed live into the requesting Client Admin's own session (no websocket/broadcast channel was requested or exists for this). The Client Admin sees the new invoice the next time they open Billing & Plans.

## 6. State Mutation Protocol — migration NOT executed

Per governing instructions, database migrations are never run automatically. The migration file `2026_09_11_120000_create_quota_requests_table.php` has been **created as code** (which this task explicitly commissions — "Create a `quota_requests` table") but **`php artisan migrate` has not been run** against any database. Until it is, `QuotaRequestController` will throw a "table not found" error against a live database.

**Intended command** (run manually, when authorized):
```
php artisan migrate --path=database/migrations/2026_09_11_120000_create_quota_requests_table.php
```

## 7. Verification

- `npx tsc -b` — **0 errors** across the whole frontend project (includes all new/edited files).
- `npx oxlint` on all 8 new/edited frontend files — **0 errors**, 7 pre-existing warnings (all `react(set-state-in-effect)` in `DashboardPage.tsx`'s *other*, untouched `useEffect` hooks — same pattern this codebase already uses throughout, e.g. `AuditLogsPage.tsx`; none are in code this task added).
- PHP: **no `php` binary is available in this sandbox** (confirmed via `which php` — same limitation disclosed in the prior two audit reports this session). Verified instead via a balanced-braces/parens/brackets check plus full manual re-read of every new/edited method — all four backend files (`routes/api.php`, `QuotaRequest.php`, `QuotaRequestController.php`, the migration) are balanced and every field/relation/method referenced was confirmed to exist against the real models before use (`Account::currentSubscription`, `Subscription::billing_model`/`total_allocated_messages`, `Invoice`'s fillable list, `PaymentGatewaySetting::enabledGatewaysCached()`/`isFullyConfigured()`/`gateway`). This is **not a substitute** for `php -l` / `php artisan test` — recommend running the real test suite (and the migration, once authorized) in a dev environment before deploying.
- Zero test coverage exists for this feature (consistent with this project's overall test coverage, noted in the initial project analysis) — no automated regression test was added; none existed to extend.

## 8. Files changed / created

**Backend:**
- `database/migrations/2026_09_11_120000_create_quota_requests_table.php` (new, not migrated)
- `app/Models/QuotaRequest.php` (new)
- `app/Http/Controllers/Api/QuotaRequestController.php` (new)
- `routes/api.php` (edited — import + 3 routes)

**Frontend:**
- `src/types/quotaRequest.ts` (new)
- `src/services/quotaRequestService.ts` (new)
- `src/components/billing/QuotaTopUpModal.tsx` (new)
- `src/pages/admin/QuotaRequestsPage.tsx` (new)
- `src/pages/DashboardPage.tsx` (edited — `SubscriptionHealthCard`)
- `src/pages/billing/BillingPage.tsx` (edited — top-up button, invoice label, modal wiring)
- `src/App.tsx` (edited — route)
- `src/components/layout/AppLayout.tsx` (edited — nav item)
