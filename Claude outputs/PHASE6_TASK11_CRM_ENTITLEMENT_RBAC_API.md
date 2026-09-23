# Phase 6 — CRM Task 11: CRM Entitlement, RBAC & Developer API

**Date:** 2026-09-23 · **Status:** ✅ PASS · Backend 1301/1301 (MariaDB 10.11.14) · Frontend 524/524

## 1. Audit findings (before changes)

| Area | Finding |
|---|---|
| Tenant CRM routes (`/api/crm/*`, 28 route definitions) | All carry auth:sanctum → tenant.isolation → subscription.guard → module.guard:lead_crm → permission:manage-crm → capability.guard:crm. No CRM model is reachable from any other controller. **No change needed.** |
| Plans / seeders | starter excludes `crm`; growth (qr) and business (meta) include it; provider rows allow `crm` on qr and meta; reconciliation revokes it generically. **No change needed.** |
| Permissions | `manage-crm` is the single CRM permission (admin, social_marketer, super_admin). Read-only = expired subscription (subscription.guard). **No new permission.** |
| Frontend | CRM nav + routes already require manage-crm + lead_crm + crm. **No change needed.** |
| `POST /api/v1/crm/leads` | Had capability.apikey:crm only — **no module check and no subscription check** (gap). |
| `/api/v1` throttling | **Bug:** Laravel's middleware priority hoisted `throttle:external-api` ahead of `auth.apikey`, so every `/v1` call used the 20/min-per-IP fallback; `api_rate_limit_per_minute` was never applied and 429s were not logged. |

## 2. Changes

| File | Change |
|---|---|
| `app/Http/Middleware/EnsureApiKeyModule.php` (new) | `module.apikey:<slug>` — `Account::hasModuleEnabled()` on the key's account; fails closed |
| `app/Http/Middleware/EnsureApiKeySubscription.php` (new) | `subscription.apikey` — `hasActiveSubscription()` for non-GET/HEAD/OPTIONS; fails closed |
| `bootstrap/app.php` | 2 aliases; `prependToPriorityList()` so log → auth → throttle order holds |
| `app/Http/Controllers/Api/V1/CrmLeadController.php` | `show`, `status`, `assignee`, `attachTag`, `detachTag` (reuse `CrmLeadService`, `CrmTagService`, `PresentsCrmLeads`, the tenant FormRequests) |
| `routes/api.php` | `/v1/crm/leads` group: 6 routes under subscription.apikey + module.apikey:lead_crm + capability.apikey:crm, `whereNumber` |
| `tests/Feature/CrmEntitlementRbacApiTest.php` (new) | 49 tests |
| `tests/Feature/CrmLeadStatusLifecycleTest.php` | Task 5 "no v1 status endpoint" test now asserts the endpoint uses the lifecycle service |
| `frontend-app/src/pages/developer/DeveloperPage.tsx` + test | Developer Portal blurb lists the new endpoints (+1 test) |

No migration, permission, capability, plan or seeder change.

## 3. Verification

| Check | Result |
|---|---|
| Full suite, MariaDB 10.11.14 (throwaway DB) | **1301 passed**, 5726 assertions, 0 failed, 0 skipped |
| Full suite, SQLite | 1297 passed + 4 skipped, 5717 assertions |
| `--filter=Crm` (MariaDB) | 643 passed |
| `--filter='Meta\|Social\|Lead\|Ads\|Ctwa'` (MariaDB) | 656 passed |
| `--filter='Api\|Developer\|V1\|Idempot\|Webhook'` (MariaDB) | 223 passed |
| `--filter='Qr\|Baileys\|Engine\|Group'` (MariaDB) | 119 passed |
| Mutation checks | no subscription.apikey → 2 fail; no module.apikey → 2 fail; priority fix reverted → 4 fail; v1 lookup without forAccount → 3 fail |
| Frontend tsc / tests / build / lint | clean / 524 passed / built / 64 warnings 0 errors (baseline) |

## 4. Known limitations

1. No view-only CRM permission (manage-crm = read + write).
2. API-key writes produce no `activity_logs` row (LogsActivity requires a user); attributed via `api_request_logs`.
3. `subscription.guard` checks the actor's account, so an Agent with an active subscription can write to an expired sub-client's CRM (pre-existing, platform-wide).
4. Super Admin bypasses module/capability guards by design.
5. No v1 list/search, tag listing or assignee listing: integrators need lead/tag/user ids from their own records or the CRM UI.
6. The throttle fix changes every `/v1` route: limits are now per account (`api_rate_limit_per_minute`, default 60), and invalid-key requests are refused before the limiter.
