# wa-saas-platform — Phase 1 (FOUNDATION) Implementation Plan

**Scope of this document:** planning only. No code was written, no migration was created, no existing file was modified, and no module was redesigned. Every claim below is evidence-tagged; several facts were re-verified directly against the live repository (`C:\xampp\htdocs\wa-saas-platform`, connected device `sl-laptop-d61`) during this pass, in addition to the seven-agent comprehensive audit already delivered as `wa-saas-platform-architecture-report.md`. Where this plan's live re-verification refines or corrects an estimate from that earlier report (e.g. exact module-slug counts), the correction is called out explicitly rather than silently overwritten.

**Evidentiary key:** **[Fact]** — read directly from the current file, cited by path (and line where useful). **[Inference]** — a conclusion drawn from verified facts. **[Hypothesis]** — plausible, not independently confirmed. **UNKNOWN — REQUIRES CONFIRMATION** — cannot be determined from the codebase; needs a human answer. Nothing below assumes a component doesn't exist merely because it wasn't found on a first pass — every "does not exist" claim was checked by direct `find`/`grep` against the live tree, not inferred from absence in a prior document.

**Hard constraints honored throughout this plan** (repeated here because they govern every recommendation that follows): no code, no migrations, no rewrites. The QR/Baileys engine is stable/frozen — nothing below touches `qr-engine-service` or the Baileys session lifecycle. The public API stays backward compatible — every schema/endpoint change proposed is additive. No provider-compatibility rule is ever expressed as a plan-name string check (`if ($plan === 'META')`) — every rule routes through Plan → Entitlement → Capability → Permission → Provider → Provider Capability → Usage/Quota. No microservices, no Kubernetes, no event bus, no rewrite — Laravel-first, modular, additive.

---

## STEP 1 — Current Architecture Inventory

Per-component inventory of everything Phase 1 will read, extend, or build directly on top of. "Reusability" states the Phase 1 disposition: **Reuse as-is**, **Extend**, or **New (Phase 1 must create)**.

| # | Component | File(s) | Purpose | Behavior (verified) | Dependencies | Phase 1 disposition |
|---|---|---|---|---|---|---|
| 1 | `Account` model | `app/Models/Account.php` | Tenant root; encodes the whole 3-tier hierarchy | `account_type enum('super_admin','agent','client')` + self-referencing `agent_id` FK. `const MODULES` = canonical 19-slug catalog (line 140, see Step 2). `effectiveModules()` (line 465) intersects `allowed_modules` with the caller's Agent's `allowed_modules`, further intersected with `SystemRoute::activePermissionKeys()` — a **pre-existing dynamic DB-driven kill switch**. `addGlobalScope('exclude_platform_device', ...)` — the *only* global scope in the codebase; not tenant-isolation, it just hides the Super Admin's own reserved test device row. `findCached()` — 3600s cache, invalidated on save/delete. | Spatie roles/permissions (global, `teams=>false`); `SystemRoute` | **Extend.** `effectiveModules()` is the closest existing analogue to a "capability map" and should be the model Step 5/8 build alongside, not replace. |
| 2 | `AccountController` | `app/Http/Controllers/Api/AccountController.php` | Agent/Client account CRUD, subscription/quota/module/permission mutation | `callerAgentScopeId()` (44-52) derives the caller's Agent scope; every id-addressed mutation calls `assertCallerCanAccessAccount()` (63), 404ing (non-disclosing) on `$account->agent_id !== $agentScopeId`. List endpoints use `scopeOwnedByAgent()`. **[Fact, re-verified]** its own docblock in `TenantIsolationMiddleware` discloses that `/api/admin/accounts` is deliberately **not** wrapped in `tenant.isolation` — `AccountController` re-derives the Agent boundary itself directly off `$request->user()`, not off the middleware's `agent_scope_id` request attribute. | `Account`, `AccountService`, Spatie `permission:manage-accounts` | **Reuse as-is** for its own boundary logic; **the access-control service in Step 7 should wrap, not replace, `callerAgentScopeId()`/`assertCallerCanAccessAccount()`.** |
| 3 | `AccountService::resolveDelegatedModules()` | `app/Services/AccountService.php:58-68` | Module-delegation intersection | `array_intersect($requestedModules ?? Account::MODULES, $callerAgentAccount->allowed_modules ?? Account::MODULES)` — an Agent literally cannot hand a Client a module it doesn't itself hold. | `Account` | **Reuse as-is** as the module dimension; Step 5 generalizes this exact intersection pattern to Entitlements. |
| 4 | `TenantIsolationMiddleware` | `app/Http/Middleware/TenantIsolationMiddleware.php` | Per-request tenant/agent-scope resolution | Sets `account_id`/`agent_scope_id` request attributes. Super Admin may override via `?account_id=`. **[Fact, self-disclosed in its own docblock]**: `agent_scope_id` "currently reaches no live route" — no controller consumes it yet; `AccountController` re-derives the same boundary independently (see row 2). | `Account` | **Reuse as-is.** No change needed for Phase 1; Step 3 records this disclosed gap as a design note, not a defect to fix now. |
| 5 | `ResolvesTenantAccount` trait | `app/Http/Controllers/Concerns/ResolvesTenantAccount.php` | Shared tenant-account resolution for controllers behind `tenant.isolation` | `resolveAccount()` (nullable, for read/list) vs `requireAccount()` (422 if unresolvable, with a **disclosed, logged, local-only** dev-convenience fallback to the first `Account` row). Not a global scope — each controller must call it. | `TenantIsolationMiddleware`, `Account::findCached()` | **Reuse as-is.** This is the correct, consistent pattern already in use; Step 7's `AccessControlService` should call through this trait's resolution, not duplicate it. |
| 6 | `EnsureModuleEnabledMiddleware` (`module.guard`) | `app/Http/Middleware/EnsureModuleEnabledMiddleware.php`; aliased in `bootstrap/app.php:32` | Backend module enforcement | **[Fact, re-verified live]** wired to exactly 6 of 19 `Account::MODULES` slugs: `chatbot`, `meta_ads`, `social_accounts`, `contact_groups`, `analytics`, `message_logs` (confirmed via `grep -o 'module.guard:[a-z_]*' routes/api.php`). The other 13 (`dashboard`, `whatsapp_setup`, `send_alert`, `billing`, `developer_api`, `team_management`, `notifications`, `social_inbox`, `lead_crm`, `comment_automation`, `reports`, `templates`, `device_settings`) are stored/toggleable but **not** backend-enforced by this middleware — confirmed by direct route-file grep, and independently self-disclosed in `Account.php`'s own docblock at line ~118: *"Only 'team_management' is actually enforced server-side today (see `TeamController::store()`) — the rest are stored/toggleable and hidden client-side when disabled."* **This corrects the prior comprehensive report's "~17 modules / ~11 ungated" estimate to the exact figures: 19 modules, 6 module.guard-gated, 1 gated by a separate ad hoc check (`team_management` in `TeamController::store()`), 12 with no backend enforcement at all.** | `Account::hasModuleEnabled()` (delegates to `effectiveModules()`) | **Extend.** Phase 1's Step 12 task list closes this gap by extending `module.guard` coverage — this is the single highest-leverage, lowest-risk Phase 1 deliverable. |
| 7 | `AuthServiceProvider` | `app/Providers/AuthServiceProvider.php` | Only authorization wiring in the app | **[Fact, re-verified, full file read]** exactly one call: `Gate::before(fn(User $u, string $ability) => $u->isSuperAdmin() ? true : null)` (line 35). No `$policies` array. No `Gate::define()` anywhere in the file or elsewhere in the codebase (confirmed by grep). Its own docblock explicitly documents that this short-circuits Spatie's `permission:` middleware (which internally calls `Gate`), and deliberately does *not* touch `TenantIsolationMiddleware`/`SubscriptionGuardMiddleware`, which pass Super Admin through on their own separate logic. | Spatie `HasRoles`/`Authorizable` | **Extend.** Step 7 adds `AccessControlService` methods and, if warranted, a small `$policies` array — without touching this existing `Gate::before` bypass, which must keep working identically. |
| 8 | `app/Policies/` | — | Laravel model-authorization layer | **[Fact, re-verified]** directory does not exist (`ls` fails: "No such file or directory"). Zero Policy classes anywhere in the repo. | — | **New.** Step 7 designs (does not yet create) the first Policy classes. |
| 9 | `app/Http/Resources/` | — | API Resource/Transformer layer | **[Fact, re-verified]** directory does not exist. All 53 controllers build response arrays inline (e.g. `AuthController::formatUser()` below). | — | **Out of Phase 1 scope**, noted for completeness — introducing Resources is a response-shape-consistency concern (§29 of the prior report, P2), not a Foundation-model concern. Not required to ship the capability map (Step 8) — that can be a plain array from a single new method. |
| 10 | `AuthController::me()` / `formatUser()` | `app/Http/Controllers/Api/AuthController.php:143-157`, `205-242` | The **sole** source of the frontend's user/permission/account payload | `me()` returns `{user, permissions, subscription_status}`. `formatUser()` eager-loads `account.currentSubscription`, `roles.permissions`, and — **[Fact, re-verified]** — already calls `$user->account?->setAttribute('effective_modules', $user->account->effectiveModules())` before serializing, so `account.effective_modules` (module-slug array, already Agent-hierarchy-capped) is already present in every `/auth/me` response today, alongside the raw `account.allowed_modules`. | `Account::effectiveModules()`, Spatie `getAllPermissions()` | **Extend.** This is the exact integration point for Step 8's capability map — a new key (e.g. `capabilities`) added alongside the existing `permissions`/`account.effective_modules`, not a payload redesign. |
| 11 | `RolePermissionSeeder` | `database/seeders/RolePermissionSeeder.php` | Ground-truth RBAC catalog | **[Fact, full file re-read]** 28 permissions in `PERMISSIONS` (confirmed exact count by enumeration). 5 seeded roles in `DEFAULT_ROLES`: `super_admin` (all 28), `admin` (15: `manage-subscriptions`, `send-messages`, `view-analytics`, `view-logs`, `manage-developer-settings`, `manage-chatbot`, `manage-team`, `manage-roles`, `view-audit-logs`, `manage-notifications`, `manage-social-accounts`, `launch-meta-ads`, `manage-social-leads`, `view-social-analytics`, `manage-comment-automation`), `user` (3: `send-messages`, `view-analytics`, `view-audit-logs`), `agent` (2: `manage-accounts`, `manage-templates`), `social_marketer` (6: `manage-social-accounts`, `launch-meta-ads`, `manage-social-leads`, `view-social-analytics`, `manage-comment-automation`, `manage-team`). `LEGACY_ROLE_RENAMES` migrates old `'Super Admin'/'Admin'/'User'` role-name rows in place. Super Admin can create additional dynamic roles at runtime via `RoleController` from any subset of the 28. | Spatie `Role`/`Permission` | **Reuse as-is** as the Permission dimension. Step 4/5 map this catalog onto the target model without altering a single seeded value. |
| 12 | `WhatsAppDriverInterface` / `WhatsAppEngineFactory` | `app/Services/WhatsApp/WhatsAppDriverInterface.php`, `WhatsAppEngineFactory.php` | Provider abstraction (Strategy pattern) | **[Fact, full files re-read]** one-method interface (`sendMessage()`). `WhatsAppEngineFactory::make(Account $account)` reads `$account->currentSubscription->engine_type` (`'qr'` | `'meta'`), returns `BaileysDriver` or `MetaCloudApiDriver`, throwing `RuntimeException` on an unconfigured/missing engine. Called from 14+ sites (per prior report, §11) — all already provider-agnostic. | `Account`, `Subscription`, `WhatsAppSession` | **Reuse as-is, do not touch.** This is the existing seam Step 6's Provider Capability model layers on top of — zero changes to this class or its call sites are required for Phase 1. |
| 13 | `subscriptions` table / `Subscription` model | migration `..._create_subscriptions_table.php`; `app/Models/Subscription.php` | Billing + engine + quota, all in one row | **[Fact, migration re-read in full]** columns: `account_id`, `engine_type` (string, default `'qr'`), `billing_model` (string, default `'flat_quota'`), `rate_per_message` (decimal 8,4, nullable), `total_allocated_messages` (nullable), `used_messages` (default 0), `price_paid`, `payment_mode`, `starts_at`/`expires_at`, `status`. **Confirms the report's conflation finding exactly**: Provider (`engine_type`), Billing Model, and Usage Limit (`total_allocated_messages`/`used_messages`) are columns on one flat row, not independent entities. | `Account`, `PlanCatalog` | **Extend via new satellite tables, not a column rewrite** (Step 5/6/12) — `subscriptions` keeps its current columns (backward compatibility) and gains a nullable `plan_id`/`entitlement_set_id`-style FK once the new tables exist; the old columns are not dropped in Phase 1. |
| 14 | `PlanCatalog` | `app/Support/PlanCatalog.php` | Static self-service-checkout plan list | **[Fact, full file re-read]** 3 hardcoded plans, each bundling engine_type + billing_model + quota + price as one indivisible array: `starter` (qr/flat_quota/500msg/₹499/30d), `growth` (qr/flat_quota/2500msg/₹1999/30d), `business` (meta/flat_quota/10000msg/₹7999/30d). Its own docblock **explicitly discloses** this was a deliberate PHP-constant choice over a `plans` table (mirroring `RolePermissionSeeder`'s pattern) and states its own promotion trigger: *"If the platform later needs per-tenant custom pricing, promotional plans, or admin-editable plans without a deploy, that's the trigger to promote this into a real table."* **Phase 1 is exactly that trigger**, per the user's own Plan/Entitlement requirement. | — | **Replace with a `plans` table**, seeded from these exact 3 rows so current checkout behavior is unchanged (Step 5, Step 12). |
| 15 | `SystemRoute` / `route_categories` / `system_routes` | `app/Models/SystemRoute.php`, `RouteMasterSeeder.php` | Existing dynamic, DB-driven route/module kill switch | **[Fact]** `SystemRoute::activePermissionKeys()` returns `null` ("no restriction, not yet seeded") or an active-key array; `Account::effectiveModules()` already intersects against it (row 1 above). `RouteMasterSeeder` backfills these two tables from the hardcoded module constants. | `Account::MODULES` | **Reuse as-is / extend.** This is a real precedent for "capability toggles live in the database, not in code" — Step 5/6's new tables should follow the same data-driven-kill-switch spirit rather than inventing a parallel mechanism. |
| 16 | `TeamController::updatePermissions()` | `app/Http/Controllers/Api/TeamController.php` | Grants one of 9 granular Spatie permissions to a team member | **[Fact, from prior report §9, re-confirmed relevant]** the granting check is only `hasRole('admin')` — it does **not** itself verify the granting Admin's account currently holds the module the permission maps to. Not currently exploitable because every granular-permission route is separately wrapped in `module.guard:<slug>` as the real backstop — but that backstop covers only 6 of 19 modules (row 6). | `module.guard`, Spatie | **Extend (defense-in-depth).** Step 10/12 flags this as a P1 item Phase 1 should close alongside the `module.guard` extension, since fixing `module.guard` coverage closes most of this gap's blast radius automatically. |
| 17 | `QuotaRequestController::approve()` | `app/Http/Controllers/Api/QuotaRequestController.php` | Agent/Super-Admin quota top-up approval | **[Fact, prior report §9]** independently re-checks ownership inside the mutating action itself (`abort_if($agentScopeId !== null && $account->agent_id !== $agentScopeId, 404)`), not just at the list-query level — a directly-onboarded client (`agent_id===null`) is unreachable by any Agent. | `AccountController::callerAgentScopeId()` pattern (independently reimplemented, not shared) | **Reuse as-is.** Note for Step 7: this is a second, independent implementation of the same ownership-check pattern as `AccountController` — a candidate for the centralized service to consolidate *without changing behavior*. |
| 18 | Frontend `AuthContext.tsx` | `frontend-app/src/core/context/AuthContext.tsx` | Client-side permission/module/role gating | **[Fact, re-verified]** `isSuperAdmin()` derived by scanning `user.roles` for `name==='super_admin'` (no boolean flag is ever serialized by the backend — `formatUser()` sends the full `roles` array, not a derived flag). `hasModule()` (147-171) **short-circuits `true` for Super Admin unconditionally** (line 149), then for everyone else prefers `account.effective_modules` over raw `allowed_modules`. `useCallback` dependency array is `[user, isSuperAdmin]` only. | `AuthController::formatUser()`'s payload shape | **Extend.** Step 8 adds a `hasCapability()`-style consumer of the new capability map alongside, not instead of, `hasModule()`/`hasPermission()` — both are explicitly stated as frontend-hiding, not enforcement, per the business requirement. |
| 19 | Frontend `TenantContext.tsx` / `axiosInstance.ts` | `frontend-app/src/core/context/TenantContext.tsx`, `frontend-app/src/core/api/axiosInstance.ts` | Super-Admin / Agent "acting as" client-switcher | **[Fact, re-verified, full relevant section read]** `canSwitchClients` is true for **both** Super Admin and an Agent acting within its own Sub-Client tree (not Super-Admin-only, as a narrower reading might assume) — both mechanisms funnel through the same `selectedAccountId`/`setSelectedAccountId()` module-level variable in `axiosInstance.ts`, which only appends `?account_id=` to outgoing requests. **[Fact, confirmed gap]** switching the selected account does **not** re-fetch `/auth/me` or otherwise recompute `hasModule()`/`hasCapability()` for the acted-as account's actual entitlements — `AuthContext`'s `hasModule()` (row 18) short-circuits `true` for a real Super Admin regardless of which client is selected, so "acting as Client X" today shows the Super Admin their own (unrestricted) view, not a faithful preview of Client X's gated view. | `AuthContext`, `accountService` | **Design note only for Phase 1** (Step 8) — not in the user's explicit Phase 1 task list, and fixing it is a UI/UX decision (should "acting as" be a faithful restricted preview, or an unrestricted admin override?) that needs a product answer. Flagged as **UNKNOWN — REQUIRES CONFIRMATION**, not silently fixed or silently ignored. |
| 20 | `UserFactory` | `database/factories/UserFactory.php` | The only model factory in the repo | **[Fact, re-verified]** `ls database/factories/` returns exactly one file. Unmodified Laravel stock scaffold — no `account_id`, no role assignment, no account_type variants. | — | **New.** Step 11/12 requires `AccountFactory` (with `account_type`/`agent_id` states) and a `SubscriptionFactory` before any tenant-isolation test can be written at all. |
| 21 | `phpunit.xml` | `backend-api/phpunit.xml` | Test-run configuration | **[Fact, full file re-read]** `DB_CONNECTION`/`DB_DATABASE` lines are present but **commented out**; no `.env.testing` file exists (confirmed: `ls` fails). Local `php artisan test` would hit the real MySQL dev database unless an operator manually exports `DB_CONNECTION=sqlite` first. | — | **Extend (config-only, additive).** Step 11/12: uncomment/set an explicit sqlite-in-memory config, matching what `ci.yml` already does via job-level env vars — no test code needs this fixed to exist, but every future test run needs it fixed to be *safe*. |
| 22 | `tests/` | `tests/TestCase.php`, `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` | Entire current test suite | **[Fact, all 3 files read in full]** `TestCase` is an empty stock subclass. `Feature/ExampleTest` asserts `GET /` returns 200, with `RefreshDatabase` imported but commented out. `Unit/ExampleTest` asserts `true===true`. Zero business-logic coverage. | — | **Reuse the scaffold, add real tests alongside** (Step 11). |
| 23 | `.github/workflows/ci.yml` | — | Only CI workflow in the repo | **[Fact, full file re-read]** backend job: `cp .env.example .env` → `composer install` → `php artisan key:generate` → creates a fresh SQLite file → `php artisan migrate --force` (env `DB_CONNECTION=sqlite` set at the **job step level**, not via `phpunit.xml`) → `php artisan test`. Its own comment block **explicitly discloses**: *"this environment has no `php` binary reachable anywhere... any new test code written here could not be executed or verified before being committed... shipping test code that has never actually been run is worse than shipping none."* Frontend job: `tsc --noEmit` + `npm run build`, no test runner. qr-engine-service job: Node syntax-check only, not a real test run. No deploy step exists anywhere in the workflow. | — | **Reuse as-is; extend once new backend tests exist** — no change needed to make Phase 1's own tests runnable in CI, since the SQLite/migrate/test steps already exist and already work with the current schema. |
| 24 | `LogsActivity` trait / `activity_logs`, `login_audit_logs` | `app/Traits/LogsActivity.php` and the two tables | Existing audit-logging mechanism | **[Fact, from prior report §6/§8]** before/after JSON diff, `account_id`/`agent_id`/`user_id`-scoped, called explicitly (opt-in) per mutation site — not automatic, not event-driven (zero Events/Listeners exist anywhere in the codebase). `login_audit_logs` is a point-in-time snapshot written on every login attempt. | — | **Reuse as-is / extend call sites.** Step 9 lists the specific new call sites Phase 1's new mutation endpoints (Entitlement/Plan/Provider changes) must add, using this exact trait — not a new logging mechanism. |

**No Repository pattern exists anywhere in the codebase** [Fact, confirmed by exhaustive grep for "Repository" across `app/`] — Phase 1 does not introduce one; it is not needed at this scale and would contradict the anti-overengineering constraint.

---

## STEP 2 — Current DB vs. Required Foundation DB Tables

**Current tables relevant to the Foundation model** [Fact, cross-checked against the live `wa_saas_platform.sql` dump referenced in the prior report and the migrations re-read in this pass]:

| Existing table | Maps to which Foundation concept today | Gap |
|---|---|---|
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` (Spatie) | **Permission** | None — this dimension is already correctly separated and global-scoped by design (`teams=>false`). Reuse as-is. |
| `subscriptions` | **Subscription**, conflated with **Provider** + **Usage/Quota** + part of **Plan/Billing-Model** | `engine_type`, `billing_model`, `rate_per_message`, `total_allocated_messages`/`used_messages` are flat columns on one row, not references to independent `providers`/`plans`/`usage_quotas` rows (Step 1, row 13). |
| `Account::MODULES` (PHP constant) + `allowed_modules` (JSON/array column on `accounts`) | Closest existing analogue to **Entitlement**, at module granularity | Not a table — a hardcoded 19-slug PHP array with no row-level metadata (no description, no "requires provider X" rule, no capability sub-breakdown). `effectiveModules()` already does the *intersection logic* an Entitlement system needs, just against a hardcoded list, not a table. |
| `PlanCatalog` (PHP constant) | **Plan** | Not a table (Step 1, row 14) — its own docblock already names table-promotion as the correct next step once "admin-editable plans without a deploy" is needed, which Phase 1's requirement is. |
| — (none) | **Capability** | Does not exist as a first-class concept anywhere — confirmed by exhaustive case-insensitive grep across `backend-api/app` and `frontend-app/src` for "capability"/"entitlement" in this session's earlier gap-fill research pass: only informal comment/prose mentions, never a class, column, migration, or JSON key. |
| — (none) | **Provider** (as a first-class row, not a string literal) | Does not exist — `engine_type` is a bare string (`'qr'`\|`'meta'`) on `subscriptions`, not a foreign key to a `providers` table. |
| — (none) | **Provider Capability** | Does not exist — the "QR has no groups support" / "Meta cannot do groups" rule is expressed today as 10+ scattered `engine_type !== 'qr'` string checks (per prior report §11), which is exactly the anti-pattern the user's brief asks Phase 1 to stop perpetuating. |
| `total_allocated_messages`/`used_messages` on `subscriptions` | **Usage/Quota**, in-place-mutated, no ledger | No append-only usage table exists (`transactions`/`wallet_transactions`/`usage_ledger` — none of these table names exist, confirmed by the prior report's full 50-table enumeration, re-cited in Step 1). |

**Explicit "no duplicate tables" check** — for every NEW table Step 5/6 proposes below, here is why it is not a duplicate of something that already exists:
- `plans` — not a duplicate of `subscriptions` (a `Subscription` is a *purchased instance*; a `Plan` is the *catalog entry* it was purchased from — today `PlanCatalog` plays this role as PHP, not SQL).
- `capabilities` — not a duplicate of `permissions` (Spatie `permissions` are **who** can perform **which UI/API action** as a user-level RBAC concept — e.g. `send-messages`, `manage-team`; **Capability** is **what a tenant account is entitled to use at all**, independent of which user is asking — e.g. "this account may use WhatsApp Groups". These are orthogonal axes and the user's own 8-concept model keeps them explicitly separate).
- `providers` — not a duplicate of the `engine_type` string column (that column becomes an FK reference to this table in a later phase; Phase 1 does not drop or rename `engine_type` — see Step 12's migration-risk notes).
- `provider_capabilities` — not a duplicate of `Account::MODULES`/`allowed_modules` (module-level delegation is a *tenant-to-tenant* concept — can Agent X give Client Y this module; Provider Capability is a *provider-to-capability* concept — does the QR engine support Groups at all, independent of any tenant).
- `usage_quotas` — not a duplicate of `subscriptions.total_allocated_messages`/`used_messages` (those columns are **kept, unchanged**, for backward compatibility in Phase 1; the new table is where a generalized, provider-agnostic, append-only usage record is layered on top — this directly reuses the same lesson already self-documented in the prior report's §14/§17 ledger findings, which recommended never repeating the in-place-mutation pattern for a new ledger).

**UNKNOWN — REQUIRES CONFIRMATION:** whether Phase 2+ should eventually migrate `subscriptions.engine_type`/`billing_model` to real FKs (`provider_id`, dropping the string columns) or keep both permanently for backward compatibility. This is explicitly **out of Phase 1's scope** — Phase 1 only adds the new tables and wires them additively; the cutover decision is a Phase 2 design spike per the prior report's own migration-roadmap guidance (§28, item 4).

---

## STEP 3 — Multi-Tenancy Verification

Answering the specific questions the brief requires, each traced to code, not assumed:

**Is `account_id` enforced globally (Eloquent global scope) or per-request?** [Fact] Per-request only. `Account.php` is the *only* model using `addGlobalScope`, and that scope (`exclude_platform_device`) is unrelated to tenant isolation — it just hides the Super Admin's reserved test-device row. No `BelongsToTenant`-style trait exists anywhere in the codebase (confirmed by grep). Tenant scoping is achieved by `TenantIsolationMiddleware` stashing `account_id` as a request attribute, and each controller being individually responsible for reading it (via the `ResolvesTenantAccount` trait, the consistent pattern) and applying it to its own queries.

**Does every controller correctly apply that filter?** [Inference, not exhaustively re-verified in this pass — the prior report's spot-check covered `AccountController`, `TeamController`, `QuotaRequestController` and found no gap in that subset] A full 53-controller sweep was explicitly out of scope for both the prior report and this plan. **This is precisely why Step 11's testing design treats an automated tenant-isolation regression suite as the Phase 1 deliverable that de-risks this unknown, rather than another one-off manual audit.**

**Is there a risk class here, structurally?** [Inference] Yes — a controller that forgets to call `resolveAccount()`/`requireAccount()` (or that hand-rolls its own `Account::find($request->id)` instead) would not be caught by anything at the ORM layer. This is a common, well-understood Laravel architecture risk (not unique to this codebase), and the correct mitigation at this codebase's scale is the test suite in Step 11, not retrofitting a global scope (which itself risks silently breaking the disclosed "Super Admin sees everything" cross-tenant read paths that are *deliberately* unscoped today).

**Does the Agent-scope mechanism actually reach live routes?** [Fact, self-disclosed by the middleware's own docblock, re-verified] `TenantIsolationMiddleware`'s `agent_scope_id` request attribute is set correctly but **currently reaches no live route** — `/api/admin/accounts` (the only route group the middleware's own docblock names as the intended consumer) is deliberately not wrapped in `tenant.isolation`, and `AccountController` re-derives the identical boundary itself, directly from `$request->user()`, via `callerAgentScopeId()`. **This is not a bug** — both code paths reach the same correct outcome — but it means there are currently **two independent implementations of "which Agent does this caller belong to"** (`TenantIsolationMiddleware::agent_scope_id` — unused — and `AccountController::callerAgentScopeId()` — used; `QuotaRequestController` has a third, separately reimplemented copy of the same check). Step 7's centralized `AccessControlService` is the natural place to consolidate these three into one, **without changing any of their current behavior** — a pure de-duplication, not a logic change.

**Can a non-Super-Admin ever supply `?account_id=` and have it honored?** [Fact, re-verified in `TenantIsolationMiddleware`] No — the middleware's own docblock states this explicitly: "a `?account_id=` query parameter from a plain Client Admin/User/Social Marketer is never honored here — only Super Admin, and an Agent acting within its own Sub-Client tree."

**Does an Agent's `allowed_modules` cap what it can delegate?** [Fact] Yes, verified twice independently — `AccountService::resolveDelegatedModules()` (row 3, Step 1) at grant-time, and `Account::effectiveModules()` (row 1, Step 1) at read-time. Both use the identical `array_intersect` pattern.

**Conclusion for Step 3:** the multi-tenancy mechanism that exists is architecturally sound and the spot-checked surface is correctly guarded, but it is **per-controller-discipline-dependent, not structurally guaranteed**, and has zero automated regression protection. Phase 1 does not change the mechanism; it (a) closes the module-enforcement gap that sits on top of it (Step 12), and (b) builds the test suite that turns "spot-checked and believed correct" into "continuously verified" (Step 11).

---

## STEP 4 — Current RBAC Mapping

**Are permissions global or tenant-scoped?** [Fact] Global. `config/permission.php` sets `'teams' => false` — a Spatie `Role`/`Permission` row is not tenant-scoped; the same `admin` role definition applies identically to every tenant. The team has designed around this consistently (verified, not assumed — no code anywhere attempts to fake per-tenant roles via naming conventions or workarounds).

**Exact current catalog** [Fact, re-read in full, Step 1 row 11]: 28 permissions, 5 default roles (`super_admin`=28, `admin`=15, `user`=3, `agent`=2, `social_marketer`=6), plus unlimited Super-Admin-creatable dynamic roles via `RoleController` from any subset of the 28.

**Capability questions, answered against code:**
- *Can a role's permission set vary by tenant?* No — global by design (see above). Per-tenant variation happens through **module** gating (`Account.allowed_modules`/`effectiveModules()`), a completely separate mechanism layered on top of the global permission a user holds. This is exactly the two-axis design (WHO can do WHAT globally, via Permission; WHICH tenant may use WHAT product surface, via Module/soon-Entitlement) the user's 8-concept model asks Phase 1 to make explicit and consistent.
- *Is there a Super-Admin bypass, and where does it live?* Yes, exactly one: `Gate::before()` in `AuthServiceProvider.php:35` (Step 1, row 7). It is the single point of truth for "Super Admin can do anything" at the permission-check level; `TenantIsolationMiddleware` and `SubscriptionGuardMiddleware` separately (and correctly, per their own docblocks) also pass Super Admin through, on tenant-scoping and billing-state grounds respectively — three independent, individually-scoped bypasses, not one shared mechanism, and each is scoped to its own concern (authorization vs. tenant-scoping vs. billing-state) rather than being a single all-powerful flag.
- *Are there Policies or `Gate::define()` calls anywhere?* No — confirmed absent (Step 1, rows 7-8). Every authorization decision today is either a Spatie `permission:`/`role:` route-middleware check, a controller-level `abort_if`, or the module-guard middleware. This is a real gap relative to the "canProvider/canAction" centralized-service requirement in Step 7 — not because the current mechanism is wrong, but because there is no single place today that composes Permission + Module + (future) Entitlement + Provider Capability into one answer; each controller currently composes its own subset ad hoc.
- *Same-module check on permission grants?* [Fact, Step 1 row 16] `TeamController::updatePermissions()` checks only `hasRole('admin')`, not "does this Admin's account currently hold the module this permission maps to." Not currently exploitable (the `module.guard` backstop covers the 6 modules where this matters most today), but architecturally fragile for the 13 currently-ungated modules — directly tied to the Step 1/row 6 finding.

**Conclusion for Step 4:** the current RBAC mapping is Permission (global, Spatie) × Module (tenant-scoped, `Account.allowed_modules`) as two independent, already-separated axes — which is *already* consistent with the user's insistence that provider/plan rules never collapse into a single hardcoded check. What's missing is a third axis (Capability/Entitlement, provider-aware) and a single service that composes all of them — exactly Step 5-7's job.

---

## STEP 5 — Plan / Entitlement Design

**Design goal:** make Plan, Entitlement, Capability, Provider, Provider Capability, and Usage/Quota independently variable — so a tenant can be constructed as "Meta engine + per-message billing + CRM + Journey entitlements" without a hand-added `PlanCatalog` entry — while reusing every existing mechanism (Permission via Spatie, Module intersection logic via `AccountService`/`Account::effectiveModules()`) rather than replacing them.

**Proposed new tables** (additive; none of the tables in Step 2 are dropped or altered in Phase 1):

- **`capabilities`** — `id`, `slug` (unique, e.g. `whatsapp_qr`, `whatsapp_meta`, `whatsapp_groups`, `crm`, `journey_automation`, `ads`, `social`, `ai`), `label`, `description`, `category` (e.g. `whatsapp` / `messaging` / `growth` / `platform`), timestamps. This is the atomic, addressable unit everything else references — deliberately at a slightly finer grain than today's 19 `Account::MODULES` slugs where it matters (e.g. splitting `whatsapp_meta` the engine from `whatsapp_groups` the feature, since Provider Capability needs to express "Meta does not support Groups" as a fact about the *pairing*, not about either slug alone) and at the *same* grain as today's modules everywhere else, so migration is a near-1:1 mapping, not a redesign.
- **`providers`** — `id`, `slug` (`qr`, `meta`, and a null-safe `none`/no-WhatsApp-provider state for Customer-C-style Social+AI-only tenants), `label`, `driver_class` (nullable, for future reference back to `WhatsAppDriverInterface` implementations — informational only, Phase 1 does not touch the driver layer). Seeded from the two `engine_type` values already in use, so this is purely additive metadata, not a behavior change.
- **`provider_capabilities`** — `id`, `provider_id` FK, `capability_id` FK, `supported` (bool), `reason` (nullable text — e.g. "Meta Cloud API cannot do groups" vs. "QR is intentionally the entry-tier engine; Journey is a paid-tier feature", so the *type* of restriction — technical impossibility vs. deliberate business-tier gate — is data, not tribal knowledge). This table is the direct implementation of the user's explicit compatibility table (Step 6 details the exact seed rows).
- **`plans`** — `id`, `slug`, `label`, `price`, `duration_days`, `description`, timestamps. Seeded 1:1 from `PlanCatalog`'s 3 existing entries (`starter`, `growth`, `business`) so current checkout behavior is byte-for-byte unchanged on day one.
- **`plan_entitlements`** — `id`, `plan_id` FK, `capability_id` FK, `usage_limit` (nullable int, provider-agnostic replacement for `total_allocated_messages` at the plan-template level — an actual tenant's live usage still lives on `subscriptions`/the new `usage_quotas` table, not here). This is the pivot that finally lets a Plan bundle an arbitrary set of Capabilities instead of one hardcoded engine+billing+quota tuple.
- **`account_entitlements`** — `id`, `account_id` FK, `capability_id` FK, `source` (`'plan'` | `'manual_grant'` | `'agent_delegated'`), `granted_by_account_id` (nullable FK, for the Agent-delegation audit trail), timestamps. **This is the mechanism that reuses `AccountService::resolveDelegatedModules()`'s exact intersection pattern** — an Agent delegating a Capability to a Client still can only grant what the Agent's own `account_entitlements` already contains, generalizing today's `allowed_modules` array-intersection to a real table with an audit trail (source/granted-by), which the current plain-array mechanism cannot express at all.
- **`usage_quotas`** — `id`, `account_id` FK, `capability_id` FK (nullable — null means "generic message quota", matching today's single `subscriptions` counter; non-null lets a future phase track per-capability usage, e.g. AI credits separately from WhatsApp messages, without a schema change), `allocated`, `used`, `period_starts_at`, `period_ends_at`. **Phase 1 does not migrate `subscriptions.used_messages` into this table** — that cutover is explicitly deferred (Step 2's UNKNOWN) to avoid a data-migration risk inside a "foundation, don't rewrite" phase; this table is created and wired for **new** capabilities only (starting with nothing live in Phase 1, since Phase 1 ships no new billable capability itself — see Step 12's task list, which is schema/service/test work, not a new product surface).

**How this maps onto the existing Agent/Selling-Entitlement/Own-Product-Entitlement model the user described:** the same `account_entitlements` table, with `source='agent_delegated'`, expresses "this Client received Capability X from its Agent." A **separate, Phase-1-scoped** concept — **Agent Selling Entitlements** (which product capabilities an Agent is *permitted to sell*, independent of whether the Agent's own account uses them) — is **not** the same row as `account_entitlements` and must not be conflated with it, per the user's explicit 3-dimension Agent model. Phase 1's schema design (Step 12) adds a `sellable` boolean (or a separate `agent_selling_entitlements` table, mirroring `account_entitlements` but scoped to "may offer to a Client" rather than "currently grants to self") — **UNKNOWN — REQUIRES CONFIRMATION**: whether "sellable" should be a boolean flag on `account_entitlements` (source=`'agent_delegated'` rows imply the granting Agent must independently hold `sellable=true` for that capability) or a fully separate table. This plan recommends the separate-table design for clarity (matching the user's explicit "3 separate dimensions" instruction literally) but flags it as a decision Step 12's task should make explicit and get confirmed before the (future, not-Phase-1) migration is written.

**Reuse discipline check:** every table above is additive; `Account::MODULES`, `PlanCatalog`, `subscriptions`'s existing columns, and `AccountService::resolveDelegatedModules()` all keep working unmodified. The new tables are a parallel, finer-grained layer that Step 12's task list wires up *without* cutting over any existing read path in Phase 1 — cutover (making `effectiveModules()`/`hasModule()` actually consult `account_entitlements` instead of `allowed_modules`) is explicitly a Step 12 task with its own migration-risk rating, not an implicit side effect of creating the tables.

---

## STEP 6 — Provider Capability Design

**The exact compatibility rules the user specified, expressed as `provider_capabilities` rows (Step 5) rather than code:**

| Provider | Capability | `supported` | `reason` (data, not code) |
|---|---|---|---|
| `qr` | `whatsapp_send` | true | Baileys sends text/template messages — existing, working, untouched. |
| `qr` | `whatsapp_groups` | true | QR/Baileys groups feature is real and maintained (prior report §13). |
| `qr` | `ads` | **false** | Deliberate business-tier restriction, not a technical one — QR is the free/entry engine; CTWA ad attribution (`message.referral`) is also technically Meta-webhook-specific (prior report §19), so this restriction is *also* grounded in a real technical dependency for the CTWA case specifically, not purely a pricing decision. |
| `qr` | `journey_automation` | **false** | Deliberate business-tier restriction — `WhatsAppJourneyEngine` technically works through `WhatsAppEngineFactory::make()` on either engine today, so this is a **product-tier gate expressed as data**, which is precisely why it belongs in this table instead of a hardcoded `if ($engine==='qr')` check: the business can change this rule later by editing a row, not shipping code. |
| `meta` | `whatsapp_send` | true | `MetaCloudApiDriver`, existing. |
| `meta` | `whatsapp_groups` | **false** | Genuine technical impossibility — Meta Cloud API cannot do groups (prior report §11/§13). |
| `meta` | `ads` | true | CTWA capture (`captureCtwaLead()`) is Meta-webhook-native — matches existing working code (prior report §19). |
| `meta` | `crm` | true | No technical dependency on WhatsApp engine at all — CRM is engine-agnostic; this row exists to satisfy the explicit compatibility table, not because CRM code reads `engine_type`. |
| `meta` | `journey_automation` | true | Same engine-agnostic reasoning; Meta is simply the tier where this is sold. |
| `none` (no WhatsApp provider) | `social` | true | Customer-C-style (Social+AI only) — no WhatsApp engine required at all; `social`/`ai` capabilities are provider-independent by design. |
| `none` | `ai` | true | Same as above. |

**Why `social` and `ai` never appear paired against `qr`/`meta` in this table:** they are not gated by WhatsApp-provider choice at all — a tenant can hold `social`+`ai` with zero WhatsApp entitlement (Customer C) or alongside either WhatsApp provider (Customer D: Meta+CRM+Journey+Ads+AI). Modeling them as provider-independent capabilities (simply present or absent in `account_entitlements`, never consulted against `provider_capabilities`) is the correct, minimal design — adding rows like `(provider: qr, capability: social, supported: true)` would be technically harmless but semantically misleading (it would imply `social` is *about* the WhatsApp provider, which it isn't) and was deliberately excluded.

**Enforcement point — where this table gets consulted, and by whom:** a new `ProviderCapabilityService::supports(Provider $provider, Capability $capability): bool` (a thin, cached read — this is a small lookup table, no need for anything more elaborate) is called from exactly two places in Phase 1: (a) `AccessControlService` (Step 7), when composing an access decision that involves a provider-dependent capability; (b) the entitlement-assignment path (granting/changing a Client's or Agent's capabilities), so an operator *cannot even save* an incompatible combination (e.g. attempting to grant `journey_automation` to a `qr`-engine account is rejected at write-time with a clear error naming the `provider_capabilities` row that blocked it) — this is the concrete mechanism that replaces "hardcoded plan-name checks" with a single, data-driven, auditable rule source. **This directly satisfies the user's explicit requirement** ("provider compatibility rules must NOT be hardcoded as plan-name string checks") **without inventing a rules engine or DSL** — it is one small lookup table and one service method, consistent with the anti-overengineering constraint.

**What this design deliberately does not do:** it does not touch `WhatsAppEngineFactory::make()`, `BaileysDriver`, `MetaCloudApiDriver`, or any of the 14+ existing call sites (Step 1, row 12) — those already correctly abstract *how a message gets sent*; Provider Capability is strictly about *what combination of product capabilities a tenant is allowed to hold*, a layer above the driver abstraction, not a replacement for it.

---

## STEP 7 — Centralized Access-Control Service Design

**Proposed class:** `App\Services\Access\AccessControlService` (new file, `app/Services/Access/`). Four methods, matching the user's exact requested signatures:

- **`can(User $user, string $capability): bool`** — composes: (1) Spatie permission check (does this user's role hold the underlying Permission, if the capability maps to one — not every Capability necessarily has a 1:1 Permission, e.g. a pure product-entitlement capability like `crm` may have no directly-corresponding Spatie permission at all, only sub-action permissions like `manage-crm-contacts` a later phase would add); (2) `Gate::before()`'s existing Super-Admin bypass (unchanged — this service calls `Gate::allows()` internally rather than re-implementing the bypass, so the one existing bypass stays the single source of truth); (3) the user's account's `account_entitlements` (does the *tenant* hold this capability at all, independent of the user's role).
- **`canTenant(Account $account, string $capability): bool`** — checks `account_entitlements` directly (via `Account::effectiveModules()`-style intersection logic generalized to the new table, per Step 5), without a user context — used for the capability-map endpoint (Step 8) and for background/system-initiated checks that have no `$user`.
- **`canProvider(Provider $provider, string $capability): bool`** — thin wrapper over `ProviderCapabilityService::supports()` (Step 6) — kept as its own method on the facade class (not folded into `canTenant`) because the user's brief asks for it as a distinct signature, and because "is this capability possible on this provider at all" (a platform-wide fact) is a genuinely different question from "does this tenant hold this capability" (a tenant fact) — conflating them would make `provider_capabilities` un-queryable independent of any tenant, which the entitlement-assignment write-time check (Step 6) needs.
- **`canAction(User $user, string $action): bool`** — the closest to today's existing `hasPermission()`/Spatie-middleware pattern; provided as a named alias so call sites that only ever needed a Permission check (the large majority of the 156 routes) have an obvious, minimal-surface method to call instead of `can()` with a capability that doesn't really exist — avoids forcing every existing route to suddenly reason about Capabilities it doesn't need to.

**Middleware vs. Policy vs. Service vs. Gate — responsibility split (explicitly scoped to avoid over-engineering):**
- **Middleware** (existing `module.guard`, extended per Step 12, plus route-level `permission:`/`role:`) stays the **coarse, route-level, all-or-nothing** gate — fast-fail before a controller even runs. It calls into `AccessControlService` rather than re-implementing checks, but its job (reject the whole request) does not change.
- **Policies** (new, Step 1 row 8) are introduced **only where a decision needs a specific model instance**, e.g. "can this user edit *this* Account row" (today done ad hoc via `assertCallerCanAccessAccount()`/`abort_if` inline in controllers). Phase 1 designs (per the "do not code yet" constraint) but does not yet write an `AccountPolicy` — the design decision is that a Policy method should internally call `AccessControlService`, not duplicate its logic, so there is exactly one place the actual rule lives.
- **`AccessControlService`** is the **single source of truth** every other layer calls into — this is the answer to the brief's explicit ask for "not over-engineered": rather than four independent authorization systems, there is one service and three thin callers (Middleware for route-level, Policy for instance-level, Gate for the existing Blade/Gate-facade convenience some code may still want).
- **`Gate::define()`** calls are added **only as thin wrappers around `AccessControlService` methods** (e.g. `Gate::define('use-capability', fn($user, $capability) => $accessControlService->can($user, $capability))`), so anything already calling `Gate::allows()`/`@can` continues to work, and the existing `Gate::before()` Super-Admin bypass (Step 1, row 7) is untouched and still runs first, exactly as today.

**Explicitly rejected as over-engineered for this codebase's scale:** a generic rules-engine/DSL, a separate microservice for authorization, or a caching layer beyond simple per-request memoization — the current codebase's entire RBAC surface is 28 permissions × 5 default roles × 19 modules; a plain service class with a few cached lookups is sufficient, matching the user's explicit "do not introduce Kubernetes/Kafka/event-sourcing/etc." constraint applied to authorization architecture specifically.

**Consolidation opportunity, not required for Phase 1 but flagged:** `AccountController::callerAgentScopeId()`, `QuotaRequestController`'s independently-reimplemented ownership check, and `TenantIsolationMiddleware`'s unused `agent_scope_id` attribute (Step 3) are three separate implementations of "which Agent does this caller belong to." `AccessControlService` is the natural home for a single `resolveAgentScope(User $user): ?int` method both controllers could call — **Phase 1's task list (Step 12) treats this as optional/P2 cleanup, not required**, since consolidating three currently-correct implementations carries real regression risk for zero behavior change, which conflicts with the minimal-change principle; it is listed as a candidate, not a mandate.

---

## STEP 8 — Dynamic Sidebar (Capability-Map JSON)

**Existing foundation to build on, not replace** [Fact, Step 1 row 10]: `AuthController::formatUser()` already computes and serializes `account.effective_modules` (a *module*-slug array, already Agent-hierarchy-capped via `Account::effectiveModules()`) on every `/auth/me` response, today. The frontend already partially consumes this (`AuthContext.hasModule()`, Step 1 row 18).

**Proposed addition — not a redesign:** extend `AuthController::me()`'s response with one new key, computed from the new `account_entitlements`/`provider_capabilities` tables (Step 5/6) via `AccessControlService::canTenant()` (Step 7), in exactly the shape the user specified:

```json
{
  "user": { "...": "unchanged" },
  "permissions": ["...unchanged..."],
  "subscription_status": "unchanged",
  "capabilities": {
    "whatsapp_qr": true,
    "whatsapp_meta": false,
    "whatsapp_groups": true,
    "crm": true,
    "journey_automation": false,
    "ads": false,
    "social": false,
    "ai": false
  }
}
```

This `capabilities` map becomes the frontend's new source of truth for sidebar rendering, sitting **alongside** (not replacing, in Phase 1) the existing `permissions` array and `account.effective_modules` — both of which real, working frontend code already depends on today; removing them would be an unnecessary breaking change the "backward compatible" constraint rules out. A follow-up phase can decide whether `effective_modules` is eventually deprecated in favor of the finer-grained `capabilities` map; Phase 1 does not make that call.

**Frontend consumption, matching the user's example sidebars:**
- A QR-only customer's map has `whatsapp_qr:true`, everything else `false` → sidebar shows WhatsApp Setup/Send/Templates/Groups only, no CRM/Journey/Ads/Social/AI nav items.
- A Meta+CRM+Journey customer's map has `whatsapp_meta:true, crm:true, journey_automation:true`, `whatsapp_qr:false`, `ads:false`, `social:false`, `ai:false`.
- A Social+AI-only customer (no WhatsApp at all) has `whatsapp_qr:false, whatsapp_meta:false, social:true, ai:true` — a real, representable state under this design, matching Customer C exactly, which the current `Account::MODULES` array *could* already represent today by omission but with no explicit "and this account intentionally has zero WhatsApp entitlement" signal — the capability map makes that state explicit and queryable.
- Agent sidebar items (Dashboard, My Customers, Add Customer, Customer Plans, Sales, Commission, Requests, Support, Settings) are **not** capability-gated the same way — they are gated by `account_type==='agent'` plus the Agent's own **Agent Permissions** dimension (Step 5's user-described 3-dimension Agent model), a separate concern from the tenant capability map above. Product modules appear in an Agent's own workspace only if the Agent's own account (not its Clients') holds the relevant `account_entitlements` row, via **Agent Own Product Entitlements** — the same `capabilities` map mechanism, just computed for the Agent's own `account_id` rather than a Client's.

**Explicit non-negotiable, restated because it governs this Step's design:** this capability map is a **frontend convenience for rendering, not an enforcement mechanism**. Every capability listed in the map must also be independently checked server-side by `AccessControlService`/`module.guard` on the actual route — Step 12's task list treats "ship the capability-map endpoint" and "extend module.guard/write the backend enforcement tests" as two separate, both-required tasks, never one standing in for the other.

**Design note on the "acting as" gap (Step 1, row 19):** because `hasModule()`/`hasCapability()` would need to short-circuit `true` for a real Super Admin, "Super Admin acting as Client X" cannot show a faithful restricted capability preview without an explicit, separate code path (e.g. the capability map response itself changing based on `?account_id=` while `isSuperAdmin()` stays `true` for actual authorization purposes) — this is **UNKNOWN — REQUIRES CONFIRMATION**: does the business want "acting as" to be a faithful read-only preview of what that Client sees, or an unrestricted admin override with a visual label only? This decision is out of Phase 1's explicit task list but is flagged here since Step 8's capability-map endpoint is the natural place it would be implemented if confirmed.

---

## STEP 9 — Audit Logging Foundation

**Existing mechanism, reused not replaced** [Fact, Step 1 row 24]: `LogsActivity` trait → `activity_logs` (before/after JSON diff, `account_id`/`agent_id`/`user_id`-scoped); `login_audit_logs` (point-in-time login snapshot, already covers "login" from the user's required list).

**Required coverage per the user's explicit list, mapped to current state:**

| Required audit event | Current coverage | Phase 1 action |
|---|---|---|
| Login | ✅ `login_audit_logs`, every attempt, already live | None — reuse as-is. |
| Permission changes | **[Fact]** `TeamController::updatePermissions()` — **UNKNOWN — REQUIRES CONFIRMATION** whether this specific call site invokes `LogsActivity` today; not re-verified in this pass (out of this plan's re-verification budget) — flagged rather than assumed either way, per the evidentiary discipline instruction. | Verify (a cheap, read-only check) as a Step 12 task; add the trait call if missing. |
| Plan changes | N/A today — `PlanCatalog` is a static PHP list, nothing to "change" per-tenant beyond picking one at checkout (already logged via `Invoice`/`Subscription` creation, per the prior report §14). | New — once `plans`/`account_entitlements` exist (Step 5), every write to `account_entitlements` must call `LogsActivity`, same pattern as everywhere else. |
| Entitlement changes | N/A today — concept doesn't exist yet. | New — same as above; this is a **new, first-class Phase 1 requirement** since Entitlement is itself new. |
| Agent-customer creation | **[Fact]** `AccountController::store()` — **UNKNOWN — REQUIRES CONFIRMATION**, same caveat as permission changes above. | Verify; this is a high-value, cheap-to-confirm check since account creation is one of the most audit-critical actions in a reseller model. |
| Subscription changes | **[Fact, prior report §14]** `Subscription` uses `LogsActivity`'s generic field-diff — confirmed present as *a* mechanism, though the same report flags it as the closest thing to a ledger, not a purpose-built one (§14 finding — separate concern from audit logging itself, which is fine). | Reuse as-is for Phase 1's purposes (audit trail, not financial ledger — the ledger gap is a Step 10/§14 finding, not an audit-logging gap). |
| Agent-Client creation | Same as "Agent-customer creation" above. | Same action. |
| API key create/revoke | **[Fact]** two independently-evolved mechanisms exist (`AuthenticateApiKey`, `ApiAuthMiddleware`, Step 1's prior-report cross-reference) — **UNKNOWN — REQUIRES CONFIRMATION** whether either's create/revoke controller logs via `LogsActivity`. | Verify both; this is explicitly named in the user's required list and touches security-sensitive credentials, so it should not be left UNKNOWN past Phase 1's first task. |
| Provider connection changes | **[Fact]** `MetaConfigController` exists; its completeness was explicitly flagged **UNKNOWN — REQUIRES CONFIRMATION** in the prior comprehensive report (§11) and is re-flagged here rather than re-guessed. | Verify as part of the same Phase-1 audit-coverage sweep; this becomes directly relevant once `providers`/`provider_capabilities` exist. |
| Sensitive admin actions | Covered generically by `LogsActivity`'s opt-in-per-call-site design; no exhaustive per-action confirmation exists. | Not a Phase 1 blanket-retrofit task (would violate the minimal-change principle across 53 controllers) — Phase 1 scopes this specifically to the **new** Entitlement/Plan/Provider-Capability mutation endpoints it creates, where `LogsActivity` is applied from day one, not retrofitted later. |

**Design decision, stated plainly:** Phase 1 does **not** attempt a blanket audit-logging retrofit across all 53 existing controllers — that is disproportionate to a "Foundation" phase and risks unrelated regressions. It **does** guarantee that every *new* write path Phase 1 itself introduces (Entitlement grants, Plan assignment, Provider Capability rule edits) calls `LogsActivity` from the first commit, and it schedules a cheap, read-only verification pass (Step 12) over the specific existing call sites the user named, converting each UNKNOWN above into a confirmed [Fact] before Phase 1 is considered complete.

---

## STEP 10 — Security Review (P0 / P1 / P2 / P3), Scoped to Phase 1

Classified only from concrete repo evidence already gathered (this pass and the prior comprehensive report); severities follow the same CRITICAL/HIGH/MEDIUM/LOW → P0-P3 convention as the prior report, filtered here to what is directly relevant to the Foundation work.

**P0 (must fix before/alongside Phase 1, though not themselves Foundation-model work):**
- `/auth/login` has zero rate limiting (prior report §8/§20/§25) — not a Foundation-model concern per se, but re-flagged here because Phase 1 is about to add new sensitive mutation endpoints (Entitlement/Plan changes) behind the same unthrottled Sanctum-session perimeter; closing the login gap first reduces the blast radius of everything Phase 1 adds behind it.

**P1 (directly Phase 1 deliverables):**
- `module.guard` covers 6 of 19 modules — re-verified exact figures in this pass (Step 1, row 6), correcting the prior report's "~17/~11" estimate. This is Phase 1's single highest-leverage, lowest-risk security deliverable.
- No Policy classes / no `Gate::define()` exist — Step 7 designs the first ones; leaving this unaddressed means every future instance-level authorization decision keeps being written as ad hoc inline `abort_if`, which is exactly the pattern that produced the `TeamController::updatePermissions()` same-module-check gap (Step 1, row 16).
- Three independent re-implementations of "which Agent does this caller belong to" (Step 3/Step 7) — not currently exploitable (all three are individually correct), but a maintainability/consistency risk that grows every time a new Agent-scoped endpoint is added without reusing one of the existing three patterns.
- Provider-compatibility rules not yet centrally enforceable at write-time (Step 6) — until `provider_capabilities` + the write-time check exist, nothing currently stops an operator from manually creating an inconsistent state (e.g. a `qr`-engine account with `journey_automation` in `allowed_modules`) other than convention.

**P2 (real, not Phase 1-blocking):**
- Response-shape inconsistency (prior report §7/§29) — unrelated to the Foundation model; not touched by this phase.
- Two independently-evolved API-key auth mechanisms (Step 1's cross-reference) — flagged in Step 9's audit-coverage sweep, not otherwise a Phase 1 task.

**P3:**
- Documentation/comment drift items from the prior report (§24) — none newly discovered in this pass; not re-listed here to avoid duplication.

**No new CRITICAL finding.** Consistent with the prior report's conclusion (§20/§25) — nothing found in this Phase-1-focused re-verification pass rises above the HIGH/P1 items already known, and the one historically CRITICAL issue (global-role-mutation privilege escalation) remains confirmed fixed.

---

## STEP 11 — Testing Design

**Prerequisite infrastructure work (must land before any test below can run safely)** [Fact, Step 1 rows 20-23]: `phpunit.xml`'s commented-out `DB_CONNECTION`/`DB_DATABASE` must be uncommitted-out (set to sqlite in-memory, matching what `ci.yml` already does at the job-env level) or an `.env.testing` must be created; `AccountFactory` (with `account_type`/`agent_id` states covering super_admin/agent/client) and `SubscriptionFactory` must be written — neither exists today, and no tenant-isolation test is writable without them.

**Test matrix, mapped to the 13 scenarios required, each with a concrete target:**

| # | Scenario | Target class/behavior under test | Depends on |
|---|---|---|---|
| 1 | Super Admin access | `Gate::before()` bypass (Step 1 row 7) grants every ability; a feature test asserting a Super-Admin-authenticated request reaches every route-group regardless of `permission:`/`module.guard` | `AccountFactory` (super_admin state) |
| 2 | Agent access | `AccountController::callerAgentScopeId()`/`assertCallerCanAccessAccount()` — Agent can list/view its own Clients | `AccountFactory` (agent + client states, `agent_id` linkage) |
| 3 | Agent A cannot access Agent B's customer | Same methods, asserting 404 (not 403 — the existing non-disclosing behavior, Step 1 row 2) across the Agent-A/Agent-B boundary | Same factories, two distinct Agent trees |
| 4 | Agent cannot grant higher permission than itself | `AccountService::resolveDelegatedModules()` (Step 1 row 3) intersection — assert a requested module outside the Agent's own `allowed_modules` is silently dropped, not granted | `AccountFactory` |
| 5 | Customer only sees entitled modules | `Account::effectiveModules()` (Step 1 row 1) — assert intersection against both the Agent's cap and `SystemRoute::activePermissionKeys()` | `AccountFactory`, seeded `route_categories`/`system_routes` |
| 6 | Customer cannot call unauthorized backend endpoint | The `module.guard`-covered routes, post-Step-12-extension — assert a 403/404 when the module is off, for **all 19** module slugs, not just the 6 covered today | Step 12's `module.guard` extension task must land first |
| 7 | QR customer cannot access Journey | New `provider_capabilities`/`account_entitlements` (Step 5/6) — assert the write-time check (Step 6) rejects granting `journey_automation` to a `qr`-engine account, and that a read-time check via `AccessControlService::canProvider()` returns false | Step 5/6 tables must exist |
| 8 | QR customer cannot access Ads | Same mechanism, `ads` capability | Same |
| 9 | Meta customer can access allowed capabilities | Same mechanism, positive case (`crm`, `journey_automation`, `ads` all `true` for `meta`) | Same |
| 10 | Social-only customer cannot access WhatsApp | `account_entitlements` — assert an account with only `social`/`ai` entitlements has `capabilities.whatsapp_qr`/`whatsapp_meta` both `false` in the Step 8 endpoint's response | Step 5/8 |
| 11 | Entitlement removal immediately blocks access | Write test: revoke an `account_entitlements` row, assert the *next* request's `AccessControlService::canTenant()` check (not a cached stale value — `Account::findCached()`'s existing cache-invalidation-on-save pattern, Step 1 row 1, must extend to the new table) returns false | Step 5/7; explicitly tests the cache-invalidation behavior, since `findCached()`'s existing 3600s TTL is a real staleness risk if the new entitlement checks don't correctly invalidate on write |
| 12 | Tenant isolation (general) | `TenantIsolationMiddleware` + `ResolvesTenantAccount` — the general-purpose regression suite the prior report's §23/§30 already called the single highest-leverage testing gap; this Phase re-affirms it as P0/P1, not superseded by the capability-specific tests above | `AccountFactory`, multiple tenants |
| 13 | API authorization | Route-level `permission:`/`role:` + the new `Gate::define()` wrappers (Step 7) — assert an unauthorized role gets 403 across a representative sample of routes per permission | `RolePermissionSeeder` (already reusable as-is, Step 1 row 11) |

**Sequencing note:** scenarios 1-6, 12-13 are testable **today**, using only existing mechanisms plus the two missing factories — they do not depend on any new Step 5/6/7/8 table or service, and should be written **first**, both because they de-risk the largest existing gap (zero coverage on code already confirmed correct) and because they give Step 12's schema work a safety net to build against. Scenarios 7-11 depend on the new tables/service existing, so they are naturally sequenced after the migrations in Step 12's task list, not before.

---

## STEP 12 — Exact Phase 1 Task List

Each task lists: objective; existing files touched; new files required; DB changes; API changes; frontend changes; risk; testing required; dependencies; complexity (S/M/L). Sequencing follows dependency order, not numeric priority alone.

**TASK 1 — Fix test-run configuration and write missing factories**
- Objective: make `php artisan test` safe to run locally without risking the dev database, and make tenant/subscription test data constructible at all.
- Existing files: `backend-api/phpunit.xml` (uncomment/set `DB_CONNECTION`/`DB_DATABASE`, matching `ci.yml`'s existing sqlite pattern).
- New files: `database/factories/AccountFactory.php` (states: `superAdmin()`, `agent()`, `client(agentId)`), `database/factories/SubscriptionFactory.php`.
- DB changes: none.
- API changes: none.
- Frontend changes: none.
- Risk: LOW — config-only plus new, additive factory files; nothing existing is modified beyond uncommenting.
- Testing required: a trivial smoke test confirming `RefreshDatabase` + the new factories work against sqlite in-memory.
- Dependencies: none — this is the prerequisite for every other task's testing.
- Complexity: **S**.

**TASK 2 — Write the tenant-isolation / RBAC regression suite against existing behavior**
- Objective: cover Step 11 scenarios 1, 2, 3, 4, 5, 12, 13 — the scenarios testable against code that already exists today, unchanged.
- Existing files touched: none (read-only against `AccountController`, `TenantIsolationMiddleware`, `AccountService`, `Account::effectiveModules()`, `RolePermissionSeeder`).
- New files: `tests/Feature/TenantIsolationTest.php`, `tests/Feature/AgentScopeTest.php`, `tests/Feature/ModuleDelegationTest.php`, `tests/Feature/RolePermissionTest.php`.
- DB/API/frontend changes: none.
- Risk: NONE — pure test addition against unchanged behavior; a failing test here would indicate a **pre-existing** bug the plan did not introduce, which is itself valuable information, not a regression.
- Testing required: this task *is* the testing.
- Dependencies: Task 1.
- Complexity: **M** (breadth of scenarios, not difficulty per scenario).

**TASK 3 — `capabilities`, `providers`, `provider_capabilities` migrations + seeders**
- Objective: create the Capability and Provider-Capability dimensions (Step 5/6), seeded exactly per Step 6's table.
- Existing files touched: none.
- New files: 3 migrations (`create_capabilities_table`, `create_providers_table`, `create_provider_capabilities_table`); `database/seeders/CapabilitySeeder.php`, `ProviderSeeder.php`, `ProviderCapabilitySeeder.php`; models `app/Models/Capability.php`, `app/Models/Provider.php`, `app/Models/ProviderCapability.php`.
- DB changes: 3 new additive tables; zero changes to any existing table.
- API changes: none in this task (no endpoint yet — schema + seed only).
- Frontend changes: none.
- Risk: LOW — purely additive, no existing table/column touched, no existing query path affected.
- Testing required: a seeder-idempotency test (`firstOrCreate`-style, matching `RolePermissionSeeder`'s own re-run-safe pattern) plus assertions the seeded rows exactly match Step 6's table.
- Dependencies: Task 1 (factories not strictly required here, but the test-infra fix should land first as a matter of sequencing discipline).
- Complexity: **S**.

**TASK 4 — `plans`, `plan_entitlements` migrations + seeder from `PlanCatalog`**
- Objective: promote `PlanCatalog` into a real table, per its own disclosed promotion trigger (Step 1, row 14), without changing current checkout behavior.
- Existing files touched: none — `PlanCatalog` itself is **not** modified or deleted in Phase 1 (removing it is a future-phase cutover decision, Step 5).
- New files: 2 migrations; `database/seeders/PlanSeeder.php` (seeds `starter`/`growth`/`business` with the exact values from `PlanCatalog::PLANS`); models `app/Models/Plan.php`, `app/Models/PlanEntitlement.php`.
- DB changes: 2 new additive tables.
- API/Frontend changes: none — the existing checkout flow keeps reading `PlanCatalog`, unchanged, in Phase 1.
- Risk: LOW — additive; explicitly does not cut over the live checkout path, avoiding the exact risk category (a live, revenue-facing flow) that would otherwise turn a "foundation" task into a de facto rewrite.
- Testing required: assert the seeded `plans`/`plan_entitlements` rows are value-identical to `PlanCatalog::all()`'s current output — a regression guard for the *future* cutover, written now while the comparison is cheap.
- Dependencies: Task 3 (needs `capabilities` to exist for `plan_entitlements` FKs).
- Complexity: **S**.

**TASK 5 — `account_entitlements`, `usage_quotas` migrations**
- Objective: create the tenant-level Entitlement and generalized Usage/Quota tables (Step 5).
- Existing files touched: none — `subscriptions.total_allocated_messages`/`used_messages` are untouched.
- New files: 2 migrations; models `app/Models/AccountEntitlement.php`, `app/Models/UsageQuota.php`.
- DB changes: 2 new additive tables; no data migrated into them in Phase 1 (Step 5's explicit scoping — new capabilities only, nothing live yet).
- API/Frontend changes: none in this task.
- Risk: LOW-MEDIUM — LOW for the tables themselves (additive); MEDIUM is deferred to Task 8 below, where cache-invalidation correctness (Step 11 scenario 11) actually matters.
- Testing required: schema-level tests only in this task (FK integrity, cascade behavior matching the existing `ON DELETE CASCADE` convention on other `account_id`-scoped tables per the prior report §6).
- Dependencies: Task 3.
- Complexity: **S**.

**TASK 6 — `AccessControlService` + `ProviderCapabilityService`**
- Objective: implement Step 7's four-method service and Step 6's `ProviderCapabilityService::supports()`.
- Existing files touched: none — this is new code alongside, not inside, existing controllers.
- New files: `app/Services/Access/AccessControlService.php`, `app/Services/Access/ProviderCapabilityService.php`.
- DB changes: none (reads Tasks 3-5's tables).
- API changes: none in this task — no route calls this service yet.
- Frontend changes: none.
- Risk: LOW — new, unreferenced code; cannot regress anything until Task 7/8 wire a route to it.
- Testing required: unit tests for all four `AccessControlService` methods and `ProviderCapabilityService::supports()`, using Task 3-5's seeded data — this is where Step 11 scenarios 7, 8, 9 get their first coverage.
- Dependencies: Tasks 3, 5.
- Complexity: **M**.

**TASK 7 — Write-time provider-compatibility enforcement on entitlement grants**
- Objective: implement Step 6's "cannot save an incompatible combination" guarantee.
- Existing files touched: none new — this task creates the first real caller of `AccessControlService`/`ProviderCapabilityService`.
- New files: `app/Http/Controllers/Api/Admin/AccountEntitlementController.php` (or equivalent — a new, minimal endpoint for Super-Admin/Agent to grant/revoke an `account_entitlements` row), with a Form Request that calls `ProviderCapabilityService::supports()` before allowing the write.
- DB changes: none beyond Tasks 3-5.
- API changes: **new, additive** endpoints only (e.g. `POST/DELETE /api/admin/accounts/{id}/entitlements`) — no existing route's contract changes.
- Frontend changes: none required for Phase 1 (this is an admin/backend capability; a UI for it is explicitly out of this plan's "do not code yet" scope and would be a Phase 2 frontend task).
- Risk: LOW — new route, additive; the only behavior change is that this *new* route can reject a write, which is the entire point.
- Testing required: Step 11 scenarios 7, 8, 9 as feature tests against this new endpoint; also the `LogsActivity` call for "Entitlement changes" (Step 9).
- Dependencies: Task 6.
- Complexity: **M**.

**TASK 8 — Extend `module.guard` to full 19-module coverage + same-module check on `TeamController::updatePermissions()`**
- Objective: close Step 1 row 6's gap and Step 1 row 16's fragility, the single highest-leverage Phase 1 security deliverable.
- Existing files touched: `routes/api.php` (wrap the remaining 13 route groups in `module.guard:<slug>`, matching the existing pattern exactly — same middleware, same alias, no new middleware class needed); `app/Http/Controllers/Api/TeamController.php` (`updatePermissions()` gains a same-module check, additive to the existing `hasRole('admin')` check, not a replacement of it).
- New files: none.
- DB changes: none.
- API changes: **behavior-narrowing, not contract-breaking** — previously-reachable-when-module-off routes now correctly 403; this is fixing a bug relative to the stated business requirement, not an API redesign. Documented explicitly as a behavior change in the PR/release notes (not a breaking *contract* change — the route shapes, verbs, and success responses are unchanged; only the previously-incorrect "always allowed" case changes to "correctly denied").
- Frontend changes: none required — the frontend already hides these nav items when the module is off (per the existing `hasModule()` gating); this task makes the backend agree with what the frontend already displays, closing the gap rather than changing frontend behavior.
- Risk: **MEDIUM** — narrowing previously-open access is the one Phase 1 change with real regression potential: any legitimate current workflow that (perhaps unintentionally) relies on a currently-ungated module being reachable regardless of its toggle state will break. This is precisely why Step 11 scenario 6 exists and must be green *before* this task is considered done, and why this task should ship with an explicit rollback plan (trivial — remove the added `module.guard:` wraps).
- Testing required: Step 11 scenario 6, exhaustively, across all 19 slugs (not just the 6 that work today) — plus a manual pre-deployment check that no currently-relied-upon workflow depends on an ungated module for a tenant that has it toggled off (an audit query, not a code change: `SELECT account_id FROM accounts WHERE JSON_CONTAINS(allowed_modules, ...) = 0` cross-referenced against recent activity in the affected modules — read-only, no destructive action).
- Dependencies: Task 2 (needs the regression-test scaffolding in place first).
- Complexity: **M**.

**TASK 9 — Capability-map endpoint (`/auth/me` extension)**
- Objective: Step 8's deliverable.
- Existing files touched: `app/Http/Controllers/Api/AuthController.php` — `formatUser()`/`me()` gain the `capabilities` key, additively, alongside (not replacing) `permissions` and `account.effective_modules`.
- New files: none required (a small private helper method on `AuthController`, or optionally a new `CapabilityMapService` if the logic grows past a few lines — **UNKNOWN — REQUIRES CONFIRMATION** whether the team prefers this inline or as its own service; this plan recommends a thin service for testability, consistent with Step 7's "one source of truth" principle, calling `AccessControlService::canTenant()` per capability).
- DB changes: none (reads Tasks 3-5).
- API changes: **additive only** — new JSON key on an existing, already-authenticated endpoint; no existing key removed or changed shape.
- Frontend changes: `AuthContext.tsx` gains a `hasCapability(slug)` method reading the new map, added alongside (not replacing) `hasModule()`/`hasPermission()`. No existing frontend behavior changes unless a specific page is deliberately migrated to the new check — Phase 1 does not mandate migrating any existing page's gating logic (out of "do not rewrite" scope); it only makes the new mechanism available.
- Risk: LOW — additive API field, additive frontend method; nothing existing is removed.
- Testing required: Step 11 scenario 10 (Social-only customer); a snapshot-style test asserting the map's shape/keys match Step 5's seeded `capabilities` table exactly (so a future capability addition can't silently break the map's expected keys).
- Dependencies: Tasks 3, 5, 6.
- Complexity: **S**.

**TASK 10 — Cache-invalidation correctness for entitlement checks**
- Objective: Step 11 scenario 11 — ensure `AccessControlService::canTenant()` never serves a stale answer after an entitlement is revoked, extending the existing `Account::findCached()` invalidate-on-save pattern (Step 1 row 1) to also fire on `account_entitlements` writes.
- Existing files touched: `app/Models/Account.php` (or a model event/observer on `AccountEntitlement` that calls the same cache-invalidation `Account` already uses on its own save/delete — reusing the existing mechanism, not inventing a new caching layer).
- New files: possibly `app/Observers/AccountEntitlementObserver.php`, if the team prefers observers over inline model-event calls (matching whichever pattern is more consistent with the rest of the codebase — **UNKNOWN — REQUIRES CONFIRMATION**, since zero Observers exist anywhere in the codebase today per Step 1's "zero Events/Listeners" finding, so this would be the first; alternatively, a direct `static::saved()`/`static::deleted()` closure on the model, matching `Account.php`'s own existing style, which is the lower-novelty choice and this plan's recommendation).
- DB changes: none beyond Task 5.
- API changes: none.
- Frontend changes: none.
- Risk: LOW — this is a correctness fix for new code (Task 5/6), not a change to any existing cached read path.
- Testing required: Step 11 scenario 11 directly — grant, verify true, revoke, verify false within the same test, no manual cache-clear.
- Dependencies: Tasks 5, 6.
- Complexity: **S**.

**TASK 11 — Audit-coverage verification sweep**
- Objective: convert Step 9's UNKNOWNs (permission changes, Agent-customer creation, API key create/revoke, provider connection changes) into confirmed [Fact]s, and add `LogsActivity` calls to Task 7's new entitlement-mutation endpoint.
- Existing files touched: read-only inspection of `TeamController::updatePermissions()`, `AccountController::store()`, the two API-key controllers, `MetaConfigController` — each gains a `LogsActivity` call **only if confirmed missing**, as a minimal, additive one-line addition per site, not a refactor.
- New files: none.
- DB changes: none (writes to the existing `activity_logs` table only).
- API/Frontend changes: none.
- Risk: LOW — additive logging calls, no behavior change to the actions themselves.
- Testing required: an assertion per confirmed-missing site that the relevant action now produces an `activity_logs` row.
- Dependencies: none (can run in parallel with Tasks 3-10).
- Complexity: **S**.

**TASK 12 — Agent Selling Entitlements design decision + (if confirmed) schema**
- Objective: resolve Step 5's explicit open question (separate table vs. flag) with the user, then implement whichever is confirmed.
- Existing files touched: none until the decision is made.
- New files: conditional on the decision — either `agent_selling_entitlements` table + model, or a `sellable` boolean column on `account_entitlements`.
- DB changes: conditional, additive either way.
- API/Frontend changes: none in Phase 1 (schema only, per the "do not code yet" constraint extending naturally to "don't build the selling UI yet either").
- Risk: LOW, but **this task cannot start until the open question is answered** — listed here so it is not silently decided unilaterally.
- Testing required: N/A until implemented.
- Dependencies: Task 3 (needs `capabilities`/`account_entitlements` to exist first); **blocked on user confirmation**.
- Complexity: **S** (once decided).

**TASK 13 — Documentation of the new Foundation model**
- Objective: a short internal reference (not user-facing docs, not this plan itself) mapping the 8 concepts to their new tables/services/classes, for the next engineer who touches this code — mirroring the disclosure-heavy commenting style already consistently used throughout this codebase (every file inventoried in Step 1 carries this same kind of "why," not just "what," documentation).
- Existing files touched: none.
- New files: one short markdown file in the repo (location per team convention — this plan does not prescribe `Claude outputs/` vs. elsewhere, since that pattern's own status is already flagged as an open question in the prior report, §4/§24, regarding the orphaned migration found there).
- DB/API/Frontend changes: none.
- Risk: NONE.
- Testing required: N/A.
- Dependencies: all prior tasks (written last, describing what was actually built).
- Complexity: **S**.

---

## STEP 13 — DO NOT CODE YET: Closing Summary

### A. Current Architecture Summary
A mature Laravel 11 modular monolith (53 controllers, 36 models, 35 services, 50 tables) with a genuinely working 3-tier tenant hierarchy, a real (if per-request-discipline-dependent) tenant-isolation mechanism, a real Strategy-pattern WhatsApp provider abstraction already used at 14+ call sites, and a global (not per-tenant) Spatie RBAC layer with exactly one authorization bypass (`Gate::before()` for Super Admin) and zero Policy classes. Module-level tenant entitlement exists today as a hardcoded 19-slug PHP array (`Account::MODULES`) with correct Agent-delegation-capping intersection logic, but only 6 of those 19 slugs are actually backend-enforced (`module.guard`) — the rest are frontend-hidden only, which is precisely the enforcement gap the business has named as unacceptable. Plan, Provider, and Usage/Quota are today conflated into flat columns on one `subscriptions` row, and Capability/Entitlement do not exist as first-class concepts anywhere in the codebase.

### B. Existing Components We Can Reuse (unmodified)
`WhatsAppDriverInterface`/`WhatsAppEngineFactory`/`BaileysDriver`/`MetaCloudApiDriver` (untouched, per the QR-frozen constraint); `TenantIsolationMiddleware`, `ResolvesTenantAccount`; `AccountController::callerAgentScopeId()`/`assertCallerCanAccessAccount()`; `AccountService::resolveDelegatedModules()`; `Account::effectiveModules()` and its `SystemRoute::activePermissionKeys()` dynamic-kill-switch integration; the entire `RolePermissionSeeder` catalog; `Gate::before()`'s Super-Admin bypass; `LogsActivity`/`activity_logs`/`login_audit_logs`; `ci.yml`'s existing sqlite test pipeline (already works, needs zero changes to run Phase 1's new tests).

### C. Components That Need Modification (additive only)
`AuthController::formatUser()`/`me()` (new `capabilities` key, existing keys unchanged); `routes/api.php` (13 additional `module.guard:` wraps, same middleware); `TeamController::updatePermissions()` (additive same-module check); `phpunit.xml` (uncomment two lines); `AuthContext.tsx` (new `hasCapability()` method, existing methods unchanged); `Account.php`/a new Observer (cache-invalidation extended to the new entitlement table).

### D. Components That Need Creation (all additive, all new files/tables)
Backend: `capabilities`, `providers`, `provider_capabilities`, `plans`, `plan_entitlements`, `account_entitlements`, `usage_quotas` tables + models + seeders; `AccessControlService`, `ProviderCapabilityService`; `AccountEntitlementController` (or equivalent, new admin endpoint); `AccountFactory`, `SubscriptionFactory`; the first Policy class(es) (designed in Step 7, not written until a future phase unless the team wants them in Phase 1 itself — **UNKNOWN — REQUIRES CONFIRMATION**, this plan treats Policy-writing as optional/Phase-1-adjacent since `AccessControlService` alone already satisfies the four required method signatures without a Policy class strictly being necessary yet). Tests: the full Step 11 matrix (13 scenarios, ~8-10 new test files). Possibly `agent_selling_entitlements` (Task 12, pending confirmation).

### E. Risks
The one **MEDIUM**-risk Phase 1 item is Task 8 (`module.guard` extension) — it is a behavior-narrowing change by design (closing an enforcement gap necessarily means something previously reachable becomes unreachable), and is the only Phase 1 task where "additive" doesn't mean "zero behavior change." Every other task is genuinely additive (new tables, new services, new endpoints, new frontend methods) with LOW or NONE risk. The `subscriptions`/`PlanCatalog` cutover (Step 2's UNKNOWN, Step 5's explicit deferral) is **not** a Phase 1 risk because Phase 1 does not attempt it — it is named here only so it is not mistaken for scope creep if raised later.

### F. Phase 1 Dependency Graph
```
Task 1 (test infra + factories)
  ├─→ Task 2 (regression tests on existing behavior) ──────────────┐
  ├─→ Task 3 (capabilities/providers/provider_capabilities)         │
  │     ├─→ Task 4 (plans/plan_entitlements)                        │
  │     ├─→ Task 5 (account_entitlements/usage_quotas)               │
  │     │     ├─→ Task 6 (AccessControlService/ProviderCapabilityService)
  │     │     │     ├─→ Task 7 (write-time enforcement endpoint)
  │     │     │     ├─→ Task 9 (capability-map endpoint)
  │     │     │     └─→ Task 10 (cache-invalidation correctness)
  │     │     └─→ Task 12 (Agent Selling Entitlements — blocked on confirmation)
  └─→ Task 8 (module.guard extension) ←── depends on Task 2's scaffolding
Task 11 (audit-coverage sweep) — independent, parallelizable with everything above
Task 13 (documentation) — last, after everything above
```

### G. Exact Task List
See **Step 12** above (Tasks 1-13, in full detail — not repeated here to avoid duplication).

### H. Recommended Implementation Order
1. Task 1 (unblocks all testing). 2. Task 2 (de-risks everything else against the largest pre-existing gap — zero coverage on code already believed correct). 3. Tasks 3-5 in sequence (schema layer, strictly additive, lowest risk). 4. Task 6 (service layer, still zero live callers, zero risk). 5. Tasks 7, 9, 10 (first live callers of the new service — can run in parallel with each other once Task 6 lands). 6. Task 8 (the one MEDIUM-risk task — deliberately sequenced *after* the regression-test scaffolding from Task 2 exists, and after the new schema/service layer is proven, even though it doesn't technically depend on Tasks 3-7/9-10, because shipping the one behavior-narrowing change last, once the team has the most test coverage and the most confidence in the surrounding system, is the lower-risk ordering). 7. Task 11 (parallelizable any time after Task 1). 8. Task 12 (whenever the open question is answered — does not block anything else). 9. Task 13 (last).

### I. What MUST NOT Be Changed
`qr-engine-service` (any file) — untouched, per the explicit frozen-engine constraint. `WhatsAppDriverInterface`, `WhatsAppEngineFactory`, `BaileysDriver`, `MetaCloudApiDriver`, and every one of their 14+ call sites — zero changes. `subscriptions` table's existing columns — not dropped, not renamed, not repurposed. `PlanCatalog` — not deleted (Task 4 creates a parallel `plans` table seeded *from* it; the class itself stays until a future, separately-confirmed cutover phase). `Account::MODULES`/`allowed_modules` — not removed; `effective_modules` keeps being served in `/auth/me` unchanged, alongside the new `capabilities` key. `Gate::before()`'s Super-Admin bypass — not touched or duplicated; every new authorization path (Task 6's `AccessControlService`) calls through it, never around it. The existing `RolePermissionSeeder` catalog (28 permissions, 5 roles) — not altered; the Foundation model is layered on top of it, not a replacement for it. No existing API route's request/response contract changes, except the deliberate, explicitly-flagged behavior-narrowing in Task 8.

### J. Questions Requiring User Confirmation
1. **Agent Selling Entitlements** (Task 12): separate `agent_selling_entitlements` table (this plan's recommendation, matching the literal "3 separate dimensions" instruction) vs. a `sellable` flag on `account_entitlements`?
2. **Module-guard extension rollout** (Task 8): acceptable to ship as a single behavior-narrowing change across all 13 remaining modules at once, or does the business want a per-module staged rollout (riskier to sequence, safer per-increment)?
3. **"Acting as" capability preview** (Step 1 row 19 / Step 8's design note): should Super-Admin/Agent "acting as" a Client show a faithful restricted capability preview, or remain an unrestricted admin override with only a visual label? Not a Phase 1 task either way, but the capability-map endpoint's design (Task 9) would differ depending on the answer.
4. **Policy classes**: does the team want the first `Policy` class(es) actually written in Phase 1 (Step 7 only designs them), given `AccessControlService` alone already satisfies the four required method signatures without one strictly being necessary yet?
5. **Documentation location** (Task 13): where should the new Foundation-model reference doc live, given the prior report's own finding (§4/§24) that the `Claude outputs/` folder already contains at least one orphaned, non-functional artifact (a migration file outside the migrations path) — is that folder still the intended convention, or should Phase 1's documentation go elsewhere?
6. **Provider-compatibility business-tier rules** (Step 6): the `qr`+`journey_automation`=false and `qr`+`ads`=false rows are, per this plan's own analysis, partly a genuine technical constraint (CTWA) and partly a deliberate pricing-tier decision (Journey). Confirming this distinction explicitly (rather than this plan inferring it) would make the seeded `provider_capabilities.reason` column accurate from day one rather than an educated guess.

---

*End of Phase 1 Foundation plan. Every [Fact] claim above was either re-verified directly against the live repository during this session (via the connected-device bridge to `C:\xampp\htdocs\wa-saas-platform`) or is explicitly cited back to the prior delivered comprehensive report, never assumed. Every UNKNOWN is flagged rather than guessed. No code, migration, or existing file was written or modified in the course of producing this plan — this document itself is the only artifact created.*
