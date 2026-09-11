# Audit Report — Client Management, Team Users, Dynamic RBAC Sidebar & Global Table Filters

Scope: rename Manage Accounts → Manage Clients; Client create/edit/deactivate with instant auth blocking; consolidated Client+Admin onboarding; Team Users Create/Edit/Reset/Deactivate; dynamic RBAC sidebar per role; universal Clear Filters across tabular views. Verified live on-device (`wa-saas-platform`); no database mutation has been run — see Section 6.

---

## 1. Manage Clients & Account Deactivation Engine

**Root-cause finding.** [Fact] `Account::hasActiveSubscription()` already called `isAdministrativelyActive()` (`status === 'active'`) before checking subscription dates, and `AuthController::login()` already called `hasActiveSubscription()`. A `status = 'suspended'` account's users were **already blocked from login** — but with the wrong message ("Your account's subscription is not active."), conflating administrative suspension with a genuinely lapsed subscription. The fix is narrower than "build blocking from scratch": a new, earlier, specific check.

**Design decision — no new column.** [Inference] Rather than add an `is_active` boolean (a redundant second concept), "deactivation" reuses the existing `Account::status` enum (`active`/`suspended`/`expired`). `status = 'suspended'` = deactivated. This required **zero new migrations**.

**Two layers, because "instant" means an already-logged-in session too:**

| Layer | File | Behavior |
|---|---|---|
| Login-time | `AuthController::login()` | New check for `!$account->isAdministrativelyActive()`, run before the subscription check, returns 403 `{"message": "Your organization account has been suspended. Contact Super Admin.", "error_code": "CLIENT_ACCOUNT_SUSPENDED"}` |
| Mid-session | `SubscriptionGuardMiddleware` | Same check added at the top of `handle()` (after the Super Admin bypass). Blocks **all HTTP methods**, not just mutations — deliberately stricter than this middleware's existing "read-only mode" for a merely lapsed subscription (GET still allowed there). An already-issued Sanctum token stops working on its very next request. |

**Two distinct suspension concepts — not merged:** `User.is_active` (per-user, pre-existing, message "Your account has been suspended. Contact your administrator.", `ACCOUNT_SUSPENDED`) vs. the new `Account.status !== 'active'` (per-tenant, "Your organization account has been suspended. Contact Super Admin.", `CLIENT_ACCOUNT_SUSPENDED`). Confirmed distinct in both backend and the frontend `ApiErrorResponse.error_code` union type (`types/auth.ts`, `CLIENT_ACCOUNT_SUSPENDED` added).

**Frontend — Create/Edit/Toggle, mostly pre-existing.** [Fact, verified by reading `AccountController::update()`] `PUT /admin/accounts/{id}` already accepted `status` via `Rule::in(ACCOUNT_STATUSES)` — **no backend controller change was needed for the toggle itself**, only the enforcement above. `AccountsPage.tsx` (renamed "Accounts" → "Clients", "New Account" → "New Client") gained a `handleToggleStatus()` action and a third row button (Power/PowerOff icon) that flips `status` between `active`/`suspended` via the existing endpoint, with a confirm-dialog warning that every user on that client will be locked out. `CreateAccountModal.tsx` create-mode title/button relabeled ("Create New Client & Admin" / "Create Client"); edit-mode text untouched.

**"Manage Accounts" → "Manage Clients" — applied platform-wide**, not just the sidebar: `AppLayout.tsx` nav label, `DashboardPage.tsx` quick-action, plus doc-comment updates in `AuthContext.tsx`/`BillingPage.tsx`. Route path `/admin/accounts` deliberately left unchanged (URL stability; not user-facing).

**Disclosed gap not closed by this change:** the frontend has no global axios interceptor for `CLIENT_ACCOUNT_SUSPENDED` (mirroring the existing, deliberate non-handling of `SUBSCRIPTION_EXPIRED` — see `axiosInstance.ts`'s own comment: "no longer turned into a global blocked flag/redirect"). The backend blocks every request; the calling page surfaces the 403's message through its own existing error banner. There is no forced logout/redirect — a suspended user's page simply starts failing every request with the correct message. [Hypothesis] This is consistent with the codebase's established pattern for the sibling case and was not flagged as a defect by the spec; call this out if a forced-redirect UX is actually wanted.

## 2. Consolidated Client & User Onboarding

**Already built, verified, not rebuilt.** [Fact] `AccountController::store()` already creates `Account` + `User` (admin) + `Subscription` in one DB transaction from one form; `CreateAccountModal.tsx`'s create-mode fields already include `admin_name`/`admin_email`/`admin_password`. This satisfies "create a Client and immediately map an Admin User… in a single unified form" as shipped before this task — only the modal's copy was relabeled.

**Team Users — file is `UsersPage.tsx`, not `TeamUsersPage.tsx`** (the spec's assumed filename does not exist; disclosing rather than silently renaming the file, which would break its route import). Also pre-existing: `TeamController` (`index`/`store`/`toggle`/`destroy`/`roles`) and `UsersPage.tsx`'s Create-User modal, Reset-Password modal (Super-Admin-only, `AdminUserController::changePassword`), Deactivate toggle, and client-side search/role/status/date filters.

**Genuinely new work in this file:**
- `TeamController::update()` (new) + `PATCH /api/team/users/{id}` (new route) — validates name/email (unique, ignoring self)/role, blocks editing the account owner, re-syncs the Spatie role.
- `UpdateTeamUserPayload` type + `teamService.updateUser()`.
- `EditUserModal` (new component) wired into `UsersPage()`: `editUser` state, a Pencil "Edit" action button per row, conditional render.
- Trigger button and modal copy: "Invite Member" → "Create User" (matches the spec's exact button/modal name); `InviteUserModal`'s own submit text likewise.

## 3. Dynamic RBAC Sidebar

**No `Sidebar.tsx` exists.** All nav/gating logic lives in `AppLayout.tsx`'s `NAV_ITEMS` array + `isNavItemVisible()`, which already implements permission/role/module-based filtering (`permission`, `superAdminOnly`, `hiddenForRoles`, `requiresModule` flags per item). This was **extended via permission grants, not rebuilt.**

**Root-cause gap found and fixed:** `RolePermissionSeeder::DEFAULT_ROLES['admin']` (Client Admin) held **zero** of the five social-marketing permissions — meaning Client Admin could not see Social Accounts/Meta Ads/Inbox/Comment Rules/Reports despite the spec requiring it. `social_marketer` held no `manage-team` — meaning Team Users was hidden from them despite the spec requiring it. Both fixed by adding those permissions to the respective seeder arrays (verified: no migration, but this **requires re-running `php artisan db:seed --class=RolePermissionSeeder`** — see Section 6, gated on your authorization).

**Final visibility matrix (post-seed-rerun), verified directly against `NAV_ITEMS` gating:**

| Nav item | Gate | Super Admin | Client Admin | Social Marketer |
|---|---|---|---|---|
| Dashboard | none | ✅ | ✅ | ✅ |
| Social Accounts | `manage-social-accounts` | ✅ (bypass) | ✅ | ✅ |
| Meta Ads Launcher | `launch-meta-ads` | ✅ | ✅ | ✅ |
| Social Inbox | `manage-social-leads` | ✅ | ✅ | ✅ |
| Comment Rules | `manage-comment-automation` | ✅ | ✅ | ✅ |
| Social Reports | `view-social-analytics` | ✅ | ✅ | ✅ |
| Team Users | `manage-team` | ✅ | ✅ | ✅ |
| Template Manager | `superAdminOnly: true` | ✅ | ❌ | ❌ |
| Manage Clients / Gateway / Social Gateway / Device Settings | `superAdminOnly: true` | ✅ | ❌ | ❌ |

**Disclosed, deliberate deviation from the spec's literal list — Template Manager.** `MessageTemplateController`'s own docblock states its actions are "platform-wide management… a template isn't tenant-scoped DATA, it's platform content one Super Admin manages for every/any tenant." Exposing this literal page to Client Admin/Social Marketer would leak and allow mutation of every *other* tenant's templates plus platform-wide approve/reject power — a tenant-isolation violation, not a cosmetic nav choice. This was raised to you directly; **you selected "Leave Template Manager Super-Admin-only."** No code change was made toward exposing it; disclosed here as the one spec item not implemented as literally written, by your own decision.

## 4. Universal Clear Filters Button

Added `ClearFiltersButton` to `components/common/DataTableControls.tsx` (disabled by default via `disabled={!active}`, re-enables the instant any bound state is non-default, resets via a page-supplied `onClear`). Applied to every tabular/filterable view in the app — 10 pages, 15 distinct filter scopes (several pages have more than one independently-filtered table/tab):

| Page | Filter scope(s) | Notes |
|---|---|---|
| `AccountsPage.tsx` (Clients) | search/status/from/to | |
| `UsersPage.tsx` (Team Users) | search/role/status/from/to | |
| `TemplateManagerPage.tsx` | search/status | |
| `AuditLogsPage.tsx` | search/status/role/from/to | |
| `DeveloperPage.tsx` | API Keys tab (search/status/from/to); Webhooks tab (search/status) | 2 independent scopes, same file |
| `NotificationsPage.tsx` | Templates tab (search); History tab (search/from/to); Mail Logs tab (search/status/from/to) | 3 independent scopes, same file |
| `ChatbotPage.tsx` | Rules tab (search); Logs tab (search/status) | 2 independent scopes, same file |
| `AnalyticsPage.tsx` | Message Logs section (search/status) | The chart's own date-range preset (`customFrom`/`customTo`) is a dashboard control, not a table filter, and was left alone |
| `SocialInboxPage.tsx` | search/platform | **New filter controls built from scratch** — this page had zero before (a two-pane thread list, not a table); added a search box (participant name/snippet) + platform dropdown above the thread list |
| `MetaAdsPage.tsx` | search/status | **New filter controls built from scratch** — zero before; added campaign-name search + ACTIVE/PAUSED status filter above the campaigns table |

State behavior verified by reading every `ClearFiltersButton` call site: `active` is always a boolean OR-chain of that scope's own filter state (`x !== ''` for every field), so it is `false`/disabled on mount and the instant any control changes; `onClear` always resets every one of that scope's own `useState` setters back to `''`, matching every other filter's default.

## 5. Verification

No PHP binary exists on-device (confirmed again this task), so backend verification is brace/paren/bracket balance + `<?php` start — all 5 touched PHP files pass, 0 imbalances: `AuthController.php`, `SubscriptionGuardMiddleware.php`, `TeamController.php`, `routes/api.php`, `RolePermissionSeeder.php`.

Frontend verification was run for real this time, not just brace-counted:
- **`npx tsc -b --noEmit`** — **0 errors**, whole project.
- **`npx oxlint`** — **0 errors**, 47 warnings, 98 files (identical warning count to the pre-existing Phase 5/6 baseline). Cross-checked every warning location in every file touched this task (`UsersPage.tsx`, `MetaAdsPage.tsx`, `SocialInboxPage.tsx`, `DataTableControls.tsx`, `TemplateManagerPage.tsx`, `AuditLogsPage.tsx`, `DeveloperPage.tsx`, `NotificationsPage.tsx`, `ChatbotPage.tsx`, `AnalyticsPage.tsx`, `AccountsPage.tsx`) — every single one is the same pre-existing `react(set-state-in-effect)` pattern already present before this task (`void load()` in a `useEffect`, `setPage(1)` on filter change, `SearchInput`'s own pre-existing debounce-sync effect). **None of the new logic added this task (`hasActiveFilters`, `clearFilters`, `filteredThreads`, `filteredCampaigns`, `EditUserModal`, `TeamController::update`) introduced any new warning or error.**
- **Route/permission wiring** — cross-checked directly against source, not assumed: `PUT /admin/accounts/{id}` gated on `permission:manage-accounts` and matches `accountService.update()`'s `axiosInstance.put(...)`; `PATCH /api/team/users/{id}` registered and matches `teamService.updateUser()`; `AppLayout.tsx`'s `isNavItemVisible()` confirmed to gate every nav item exactly as tabulated in Section 3.

## 6. Database Changes Requiring Authorization

**No migration, seed, or other database mutation has been run.**

- **No new migration this task** — the deactivation engine reuses the existing `Account.status` column; requirement #2 needed no new tables.
- **One seed re-run is required and needs your sign-off before it's executed:**
  ```
  php artisan db:seed --class=RolePermissionSeeder
  ```
  This is what actually grants `admin` its 5 new social permissions and `social_marketer` its new `manage-team` permission (Section 3). Until this runs, the sidebar visibility matrix in Section 3 does **not** yet match a live database — the code is ready, the grant is not applied.
- The 10 migrations disclosed as still-pending in the prior Phase 5/6 report remain pending; this task adds none.

## 7. File Manifest

**Backend — modified:** `AuthController.php`, `SubscriptionGuardMiddleware.php`, `TeamController.php` (new `update()`), `routes/api.php` (new `PATCH /users/{id}`), `RolePermissionSeeder.php` (2 role grants).

**Frontend — modified:** `AppLayout.tsx`, `DashboardPage.tsx`, `AuthContext.tsx`, `BillingPage.tsx` (label rename); `AccountsPage.tsx`, `CreateAccountModal.tsx` (Clients rename + deactivate toggle); `UsersPage.tsx` (Create User rename, new Edit User); `types/team.ts`, `teamService.ts` (Edit User payload/call); `types/auth.ts` (`CLIENT_ACCOUNT_SUSPENDED` literal); `signalIndigo.ts` (`ROLE_LABEL.user` → "Team Member", display-only); `DataTableControls.tsx` (new `ClearFiltersButton`); `TemplateManagerPage.tsx`, `AuditLogsPage.tsx`, `DeveloperPage.tsx`, `NotificationsPage.tsx`, `ChatbotPage.tsx`, `AnalyticsPage.tsx`, `SocialInboxPage.tsx`, `MetaAdsPage.tsx` (Clear Filters; last two also got new filter controls).

---

**Bottom line:** every requirement in the spec is implemented and verified except one, which was withheld by your own explicit choice (Template Manager stays Super-Admin-only). Client deactivation is enforced at both login and mid-session layers with distinct, correct messaging. The sidebar's permission-based gating already existed and needed only two permission grants — both disclosed and gated on a seed re-run you have not yet authorized. Clear Filters is live on all 10 named/implied tabular views, including two (Social Inbox, Meta Ads) that had no filtering at all before this task. Frontend verification is real (`tsc`/`oxlint`, not brace-counting); backend verification remains structural only, since no PHP binary is available in this environment.
