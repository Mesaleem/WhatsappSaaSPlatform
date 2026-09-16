# P0 Security Fix — RoleController Cross-Tenant Privilege Escalation
**Date:** 2026-09-16
**Scope:** `backend-api/routes/api.php` only (the `/roles` route group). No other file changed for this fix.

---

## 1. AUDIT FINDING

Confirmed exactly as reported, against the actual source (nothing assumed):

- `backend-api/app/Http/Controllers/Api/RoleController.php` has three methods: `index()` (list), `store()` (create a role + `syncPermissions()`), `update()` (rename a role and/or `syncPermissions()`). There is no `destroy()` — the audit's "if present" caveat for delete does not apply; only `store`/`update` mutate anything.
- All three routes (`routes/api.php` lines 236–239, pre-fix) sat behind `Route::middleware(['tenant.isolation', 'subscription.guard'])` → `Route::middleware('permission:manage-roles')` and nothing else.
- `config/permission.php` line 138: `'teams' => false` — confirmed Spatie roles are global, not tenant-scoped.
- `database/seeders/RolePermissionSeeder.php`'s `DEFAULT_ROLES` constant: `'admin' => [... 'manage-team', 'manage-roles', 'view-audit-logs', ...]` — **the seeded `admin` role (Client Admin) holds `manage-roles`**, alongside `super_admin` (which holds it via the `PERMISSIONS` catalog). Neither `user`, `agent`, nor `social_marketer` hold it.
- `RoleController::update()` already had one narrow, pre-existing guard: it special-cases the literal role name `'super_admin'` and 403s any attempt to modify that ONE role — but this did nothing to stop a Client Admin from modifying `admin`, `user`, or any newly-created dynamic role's permissions, and did nothing at all for `store()` (creating brand-new global roles).

**Conclusion: the audit finding is accurate.** A user holding the `admin` role (i.e. any tenant's Client Admin) could call `POST /api/roles` or `PUT /api/roles/{id}` and mutate global Spatie role definitions/permissions shared by every tenant on the platform — a genuine cross-tenant privilege-escalation path.

## 2. ROOT CAUSE

`permission:manage-roles` is an ordinary Spatie permission, and — by original design — `manage-roles` was seeded onto `admin` as part of "Client Admin manages their own account's subscription, team and roles" (per the seeder's own comment on that role). That design assumption breaks down because `RoleController` doesn't operate on anything tenant-scoped — it operates directly on Spatie's global `Role` model rows, which are shared platform-wide. The route never layered on an additional check for the one thing that actually matters here: is this caller Super Admin. `permission:manage-roles` alone answers "can this user manage roles for their own tenant", not "is this a platform-global mutation this user should be allowed to make" — those are two different questions the code conflated.

## 3. SECURITY FIX IMPLEMENTED

Added a second, additional middleware layer — `role:super_admin` — in front of the two mutating routes (`POST /roles`, `PUT /roles/{id}`) only, on top of (not instead of) the existing `permission:manage-roles` gate. `GET /roles` (read-only) was deliberately left as-is.

`role:super_admin` is not a new mechanism — it is Spatie's own `RoleMiddleware` (already aliased as `'role'` in `bootstrap/app.php`) and is **already used identically elsewhere in this exact codebase** for other platform-global-only endpoints: `Route::middleware('role:super_admin')->get('/admin/whatsapp/devices', ...)` and the `/admin/whatsapp/self-device/*` group (`routes/api.php` lines 758 and 773, pre-existing, untouched). This fix reuses that exact precedent rather than inventing a second authorization architecture, per the task's explicit instruction.

`role:super_admin` resolves via `$user->hasAnyRole(['super_admin'])`, which is backed by the same canonical mechanism `User::isSuperAdmin()` uses (`$this->hasRole('super_admin')`) and the same one `TenantIsolationMiddleware` and `AuthServiceProvider`'s `Gate::before()` already key off. No new Super-Admin-detection logic was written anywhere.

## 4. FILES CHANGED

- **`backend-api/routes/api.php`** — the only file changed for this fix. The `/roles` route group was restructured so `POST /roles` and `PUT /roles/{id}` sit inside an additional `role:super_admin` middleware group, nested inside the existing `permission:manage-roles` group. `GET /roles` is untouched, still directly under `permission:manage-roles`. A docblock comment above the change documents the exact vulnerability and why `role:super_admin` (not a new mechanism) was chosen. +33 lines (mostly the explanatory comment), 0 lines removed — a pure, additive restructuring; no existing route, controller, seeder, or model file was touched.

`RoleController.php` itself was deliberately **not** modified: since routing is the only way any HTTP request reaches `store()`/`update()`, and I traced every route in `routes/api.php` that references `RoleController` (there are exactly three, all in this one group), the route-level gate is a complete boundary — there is no other path into these two methods that would need a matching controller-level check. Adding a redundant check inside the controller as well would not close any additional gap and would only add code to keep in sync, so it was left out per the minimal-change requirement.

## 5. EXACT AUTHORIZATION RULE

`POST /api/roles` and `PUT /api/roles/{id}` now require, in this order: (1) authenticated (`auth:sanctum`, pre-existing, unchanged), (2) `tenant.isolation` + `subscription.guard` (pre-existing, unchanged), (3) permission `manage-roles` (pre-existing, unchanged), **(4) NEW: Spatie role `super_admin`**. All four must pass. `GET /api/roles` still requires only (1)–(3), unchanged.

## 6. ROLE-BY-ROLE BEHAVIOR

- **Super Admin** — holds role `super_admin` → passes the new gate. Also already holds `manage-roles` via `RolePermissionSeeder::PERMISSIONS`, and is additionally exempted from every permission/role check platform-wide by `AuthServiceProvider`'s `Gate::before()` (`return $user->isSuperAdmin() ? true : null;`) — so Super Admin's access to `store()`/`update()`/`index()` is completely unchanged by this fix, in every respect.
- **Agent** — holds role `agent` (and, per the real account-provisioning flow in `AccountController::store()`, is also assigned `admin` at account creation, which is exactly the vulnerable combination this fix closes). Does not hold `super_admin` → blocked by the new gate with 403, regardless of holding `manage-roles` via the `admin` role.
- **Client Admin** — holds role `admin` only. Does not hold `super_admin` → blocked by the new gate with 403. This is the primary case the audit finding was about, and it is now closed.
- **Client User** — holds role `user`, which never had `manage-roles` in the first place → already blocked by the pre-existing `permission:manage-roles` gate before reaching the new one; also does not hold `super_admin` regardless, so the new gate would block it too even if `manage-roles` were somehow granted later.
- **Any other non-Super-Admin role** (`social_marketer`, or any future dynamic role a Super Admin creates) — none hold `super_admin` by construction (`super_admin` is a fixed, seeded role name, not something `RoleController::store()` lets anyone create a second instance of — `Rule::unique('roles','name')` prevents a duplicate `super_admin` row, and `update()`'s existing guard prevents renaming another role onto that name) → blocked.

## 7. METHODS PROTECTED

`RoleController::store()` and `RoleController::update()` — the only two mutating methods that exist. `index()` is read-only and was intentionally left on `permission:manage-roles` alone (see §3). There is no `destroy()` method in this controller to protect.

## 8. TESTS EXECUTED

**None were added or executed**, for a reason specific to this project (not improvised, not skipped casually):

- `backend-api/phpunit.xml` has its `DB_CONNECTION`/`DB_DATABASE` test-DB overrides **commented out**, and no `.env.testing` file exists in the repo. The only two test files in the entire project are the untouched Laravel stub examples (`tests/Unit/ExampleTest.php`, `tests/Feature/ExampleTest.php`); neither uses `RefreshDatabase`, `DatabaseTransactions`, or any DB-touching trait.
- `backend-api/.env` (the file Laravel would actually load for any test run absent a `.env.testing`) points at `DB_DATABASE=wa_saas_platform` — the same production-pointing database confirmed in use for this session's prior tasks. Writing and running a Feature test against `/api/roles` in this state would create/mutate real `Role`/`Permission`/`User`/`Account` rows in that same database — exactly the kind of "improvised destructive testing against production" your instructions explicitly forbid (§11).
- This project has **already made an explicit, documented decision about exactly this situation**: `.github/workflows/ci.yml`'s own comment states, verbatim: *"this environment has no `php` binary reachable anywhere... shipping test code that has never actually been run is worse than shipping none — it creates false confidence."* That CI workflow does run real PHPUnit, but only via GitHub Actions with a fresh, isolated `sqlite` file DB (`DB_CONNECTION=sqlite`, `DB_DATABASE=database/database.sqlite`, migrated fresh) — never against this environment's live `.env`. PHP CLI remains unavailable in this device-bridge shell (`php: command not found`, confirmed again for this task), matching every prior task in this session.

Following that same already-established project precedent rather than contradicting it, I did not write a new PHPUnit test file I could not execute or verify here. **PHP CLI unavailable in this environment.**

**What I verified instead (read-only, no mutation):** traced the exact code path Spatie's `RoleMiddleware`/`PermissionMiddleware` execute (read `vendor/spatie/laravel-permission/src/Middleware/RoleMiddleware.php` and `Exceptions/UnauthorizedException.php` directly — confirmed `UnauthorizedException` is a `Symfony\...\HttpException` constructed with a hardcoded `403`); confirmed `bootstrap/app.php` forces JSON error rendering for every `/api/*` request regardless of `Accept` header, so this 403 renders as clean JSON (`{"message": "..."}`), not an HTML error page; confirmed the exact seeded permission/role assignments in `RolePermissionSeeder.php`; confirmed the account-provisioning flow that gives an Agent's user both `agent` and `admin` roles (`AccountController::store()`, referenced by the seeder's own comment); confirmed no other route in `routes/api.php` reaches `RoleController::store`/`update`; and confirmed brace/paren/bracket balance on the edited file.

## 9. TEST RESULTS

Not applicable — no tests were executed (see §8 for why, and what to do about it below).

**If you want this covered by a real, executed test:** the exact scenarios from your task's §10 (A–G) are ready to implement as a `tests/Feature/RoleControllerSecurityTest.php` using `RefreshDatabase` against `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` (the standard Laravel convention this project's own `phpunit.xml` already has commented out, ready to uncomment) — seed via `RolePermissionSeeder`, create one `Account`+`User` per role under test (`super_admin`, `agent`+`admin` combined per the real provisioning pattern, `admin` alone, `user` alone), `actingAs()` each, and assert `postJson('/api/roles', ...)`/`putJson('/api/roles/{id}', ...)` return 201/200 for Super Admin and exactly 403 for every other role, with the target role's `permissions` array reread from the DB and asserted unchanged after every rejected attempt. This would run safely in GitHub Actions' existing CI job the moment it's added, or locally once PHP + the sqlite test config are available. I'm handing you the exact plan rather than the file itself, because shipping the file without being able to run it once would risk exactly the false confidence this project's own CI comment already warns against.

## 10. DATABASE/SCHEMA IMPACT

None. This fix is entirely a routing/authorization change — no migration, no model, no seeder, no table was touched.

## 11. MIGRATION REQUIRED: **NO**

**NO MIGRATION REQUIRED.**

## 12. PRODUCTION DATA SAFETY

No destructive command was run or suggested. No `DROP`/`TRUNCATE`/`migrate:fresh`/`migrate:refresh`/`db:wipe` was executed. No role, permission, account, or user row was created, modified, or deleted by this change — the fix only adds a route-level authorization gate; it does not touch data. No database command of any kind was run against the connected environment during this task.

## 13. GIT DIFF SUMMARY

```
backend-api/routes/api.php | +33 / -0  (new — this task's only change)
```
(`git diff -w --stat`, whitespace-insensitive.) Every other file `git status` reports as modified is from either pre-existing CRLF-only noise unrelated to any session task, or from the three prior tasks completed earlier in this session (`AnalyticsController.php`, `DashboardPage.tsx`, `AnalyticsPage.tsx`, `types/analytics.ts` — the analytics/KPI work; `WhatsAppFlow.php`, `WhatsAppFlowSession.php`, `AppLayout.tsx`, `ActivityLogsPage.tsx` — the Journey Builder/sidebar/UI fixes) — all confirmed at the exact same line counts as when those tasks concluded, meaning nothing from this security fix touched or altered any of that prior work. `RoleController.php` and `database/seeders/RolePermissionSeeder.php` both show **zero diff** — neither was touched, confirming no role/permission definitions were changed.

## 14. REMAINING ISSUES

- **Frontend (§8 of your task):** inspected thoroughly — there is **no frontend code path that calls `/api/roles` at all**. `grep`ing the entire `frontend-app/src` tree for `manage-roles`, `roleService`, or any reference to `/roles`/`api/roles` found exactly one hit: a single comment-only mention of `manage-roles` inside a TypeScript type file's example list (`types/auth.ts`), never used to gate any component, route, or nav item. There is no "Role Management" page in this app's frontend today. **No frontend change was made or is needed** — there is nothing exposing this operation to any UI, authorized or not.
- **Adjacent, out-of-scope observation (not touched, reported only):** while tracing every `assignRole`/`syncRoles`/`givePermissionTo` call platform-wide (per your §2's explicit search list), I confirmed `TeamController::store()`/`update()` (which let a Client Admin assign *existing* roles to *their own tenant's* team members) already explicitly excludes `super_admin`/`admin`/`agent` from the assignable role list (`$excludedRoleNames`) — a separate, already-correct safeguard against a different escalation path (self-promotion via role assignment, not global role-definition mutation). This is pre-existing, correct behavior; I did not touch it, and it is unrelated to this fix.
- **Test coverage gap remains** (see §8/§9) — no automated regression test guards this fix today, because this environment cannot safely or verifiably run one. This is the same disclosed PHP-unavailability limitation present in every task this session, now compounded by the repo's own lack of an isolated test-DB config. Flagged, not silently left unaddressed.
- Everything explicitly out of scope per your §17 (Agent analytics rollup, Super Admin per-agent analytics filter, Admin/User granular permission investigation, QR inbound, social webhook HMAC, native WhatsApp group creation, template/quota/billing/audit-log/analytics/contact-group changes) was not touched, audited only as far as needed to confirm they share no code path with this fix.

## 15. FINAL SECURITY VERIFICATION

Proven from the actual source/diff, not assumed:

1. **Non-Super-Admins cannot mutate global Spatie roles** — `POST`/`PUT /api/roles*` now require Spatie role `super_admin` (`role:super_admin` middleware, Spatie's own `RoleMiddleware` checking `$user->hasAnyRole(['super_admin'])`), on top of the pre-existing `permission:manage-roles` gate.
2. **Client Admin cannot modify global `admin` role permissions** — Client Admin's user holds only role `admin`, never `super_admin`; blocked.
3. **Client Admin cannot modify global `user` role permissions** — same gate, same result.
4. **Agent cannot modify global roles** — Agent's user holds `agent` (+ `admin` per real provisioning), never `super_admin`; blocked.
5. **Client User cannot modify global roles** — holds only `user`; blocked by the pre-existing `manage-roles` gate already, and would be blocked by the new gate regardless.
6. **Super Admin can still perform the intended role-management operation** — holds `super_admin`, already holds `manage-roles`, and is additionally exempted from every permission/role check by the pre-existing `AuthServiceProvider::Gate::before()` bypass; zero behavior change.
7. **Unauthorized requests return 403** — Spatie's `UnauthorizedException` (thrown by `RoleMiddleware`) extends Symfony's `HttpException` constructed with a hardcoded status `403`, confirmed by reading the vendor source directly.
8. **Unauthorized requests do not mutate permissions** — Laravel's middleware pipeline halts and throws before the controller method is ever invoked; `store()`/`update()`'s `Role::create()`/`syncPermissions()` calls never execute for a rejected request.
9. **No tenant/account data was changed** — the entire fix is a routing-middleware restructuring in one file; no data-layer code was touched.
10. **No database migration was required** — confirmed no schema change of any kind; NO MIGRATION REQUIRED.
11. **Existing RBAC definitions were not changed** — `RolePermissionSeeder.php` shows zero diff; every role's seeded permission list is exactly as it was.
12. **Existing hierarchy/module/template/analytics functionality was not touched** — `git diff --stat` confirms the only file this task changed is `routes/api.php` (+33/-0), and every other file with pending changes in the working tree belongs to earlier, separate tasks in this session at their already-reported line counts, untouched by this one.
