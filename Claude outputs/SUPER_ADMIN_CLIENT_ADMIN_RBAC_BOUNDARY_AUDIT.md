# Audit Report — Super Admin / Client Admin RBAC Boundary Enforcement

Scope: `backend-api/` + `frontend-app/` on `wa-saas-platform`. All findings are **[Fact]** (verified by reading the actual code before/after editing, and by direct `grep`/count checks shown below) unless tagged **[Inference]**/**[Hypothesis]**/**[Unknown]**.

---

## 0. Root-cause finding that shaped this task

Before this task, `RolePermissionSeeder::DEFAULT_ROLES['super_admin'] => self::PERMISSIONS` (the **entire** permission catalog) plus `TenantIsolationMiddleware`'s `?account_id=` override meant a Super Admin could impersonate any tenant and hit **every** `TeamController` endpoint exactly like that tenant's own Admin — create users, edit/deactivate/delete them, and open the Granular Permission Matrix. **[Fact]**, verified by reading both files before making any change. Nothing in the data model prevented this; it was a pure route/controller-authorization gap. This task closes it at three layers (route guard, controller guard, UI) rather than one, so no single missed check re-opens it.

---

## 1. Restricting Super Admin's scope

### 1.1 "Enabled Module Routes" naming — disclosed mapping, no rename

The spec calls this `enabled_modules` (JSON array). **[Fact]** the existing, already-implemented mechanism is `Account::allowed_modules` — same concept (a per-account JSON array of `Account::MODULES` slugs, `null` = all enabled), built out fully in the immediately preceding "Module & Feature Access" refactor (3 categories, Master checkboxes, presets — see that task's audit report). **Decision, disclosed**: I did not rename the column/field. Renaming `allowed_modules` → `enabled_modules` would touch the DB column, the model cast, every controller validation rule, every frontend type/service call, and the already-shipped UI — a large, purely-cosmetic, high-risk change with zero functional benefit, and "Minimal Change Principle" argues directly against it. If the literal name `enabled_modules` matters (e.g. for an external contract), say so and I'll do the rename as a dedicated, reviewed change.

### 1.2 Single Primary Client Admin at setup — verified, unchanged

**[Fact]** `AccountController::store()` is the only place in the entire backend that calls `assignRole()` outside `TeamController` (`grep -n "assignRole\|givePermissionTo\|syncRoles" AccountController.php` → exactly one hit, `$admin->assignRole('admin')`, inside the atomic account-creation transaction). There is no second admin-creation path anywhere. No change was needed here — already correct.

### 1.3 Granular Permission Matrix hidden from Super Admin — new, three layers

1. **Route layer** (`frontend-app/src/core/guards/ProtectedRoute.tsx`): added a new `strictRole` prop, distinct from the pre-existing `role` prop. **[Fact]**, verified by `grep`, the existing `role` prop bypasses for Super Admin (`if (role && !superAdmin && !hasRole(role))`) — and was **never actually used anywhere** in `App.tsx` before this task (0 matches), so I left its bypass-for-Super-Admin semantics untouched (some future route may legitimately want "this role, or Super Admin standing in for it") and added `strictRole` as a second, additive check that does **not** bypass: `if (strictRole && !hasRole(strictRole))`. `/team/permissions` now carries `strictRole="admin"` — a Super Admin hitting that URL directly is redirected to `/unauthorized`, not merely kept off a nav link.
2. **Controller layer** (`backend-api/app/Http/Controllers/Api/TeamController.php`): `permissions()` and `updatePermissions()` both now open with `if (! $request->user()->hasRole('admin')) return 403`. This single check simultaneously excludes Super Admin (role name `super_admin`, never `admin`) **and** `social_marketer` (which still holds `manage-team` for the ordinary Team Users CRUD below, per an earlier, still-intentional design — see §1.4 — but is not the literal `admin`/"Client Admin" role this spec names). Verified this is the only backend surface exposing `TeamController::MANAGED_PERMISSIONS`; there is no second read/write path for these 9 permissions.
3. **UI layer** (`frontend-app/src/pages/users/UsersPage.tsx`): the "Permissions" row action is now gated on `hasRole('admin')` instead of always rendering, so it disappears from the table for a Super Admin (and for `social_marketer`) rather than being present-but-error-prone.

File also physically **moved** per the spec's named path: `frontend-app/src/pages/users/PermissionsMatrixPage.tsx` → `frontend-app/src/pages/team/TeamPermissionsPage.tsx`. Since `device_bash` cannot delete files on your machine without an explicit permission grant I didn't request this session, the old file was **relocated (not deleted)** to `_to_delete/frontend-app/src/pages/users/PermissionsMatrixPage.tsx` under your project root — delete that `_to_delete/` folder yourself when convenient; nothing in the app references the old path anymore (verified: `grep -rln "PermissionsMatrixPage"` now matches only a descriptive comment inside the new file, no imports).

The new `TeamPermissionsPage.tsx` also **removes**, as dead code (not just hides), every cross-tenant/`?account_id=`/`isSuperAdmin`/`selectedAccountId` branch the old page carried — since the route is now provably Client-Admin-only, there is no code path left in this file that can act on any account but the caller's own.

### 1.4 "Super Admin MUST NOT... create regular team users" — new, three layers

1. `TeamController::store()` now opens with an `is_super_admin` (the request attribute `TenantIsolationMiddleware` already sets) check → 403 "Super Admin cannot create team users directly," **before** the account is even resolved.
2. `frontend-app/src/pages/users/UsersPage.tsx`'s "Invite Member" button: the old visibility condition was `(scope === 'account' || superAdmin)`. **[Fact]**, worth flagging on its own: this condition was **always true** for every caller — `scope` is unconditionally `'account'` for any non-Super-Admin (confirmed by reading `TenantIsolationMiddleware`: a regular user's `?account_id=` is never honored, `account_id` is always their own), and the second clause covered Super Admin regardless of scope. So this button was never actually gated by anything before this task. It is now `{!superAdmin && (...)}` — the first real gate it has had.
3. `InviteUserModal`'s Super-Admin/account-picker branch inside `UsersPage.tsx` is now **unreachable** (the button that opens it is gone for Super Admin) but was **not deleted** — deliberately, to keep this diff minimal and low-risk; it's inert, not incorrect. Backend rejection (#1) is the real backstop regardless of any leftover client code path.

### 1.5 "Super Admin MUST NOT manage individual team user CRUD permissions" — scope decision, disclosed

The literal spec sentence groups two things: "manage individual team user CRUD permissions" (read as the Matrix's View/Create/Edit/Delete/Send-Messages checkboxes — covered in §1.3) "or create regular team users" (§1.4). Read narrowly, it does **not** explicitly mention `update`/`toggle` (activate/deactivate)/`destroy` (remove).

**[Inference], the decision I made and want to flag explicitly**: I extended the same `is_super_admin` 403 guard to `update()`, `toggle()`, and `destroy()` too — i.e. Super Admin can no longer edit a team member's name/email/role, (de)activate them, or remove them, for **any** tenant. Reasoning:
- The task's own required audit framing (§4 below, your literal wording) labels the boundary as **Super Admin (Route/Account Allocation)** vs. **Client Admin (User Management & CRUD Permissions)** — placing "User Management" as a whole, not just "CRUD Permissions," on the Client Admin side.
- The task's title is "**Strictly** separate... responsibilities" — a partial boundary (blocking creation and the matrix, but leaving edit/deactivate/delete open) is not a strict separation.
- `GET /team/users` (the roster) is **not** touched — read-only cross-tenant oversight remains, since nothing asks to remove Super Admin's visibility, only their ability to act.

**If this reading is too broad** — e.g. if you want Super Admin to retain the ability to deactivate/remove a compromised user in an emergency without waiting on the Client Admin — tell me and I'll narrow the guard back to `store()`/`permissions()`/`updatePermissions()` only. This is a one-line-per-method change to revert (delete the `is_super_admin` check block from `update()`/`toggle()`/`destroy()`).

**Deliberately NOT touched**: the pre-existing, Super-Admin-only "Reset Password" action (`ResetPasswordModal` → `AdminUserController::changePassword`, a separate controller entirely). This is disclosed, pre-existing platform-support functionality (this codebase has no self-service "forgot password" flow — confirmed in an earlier task), not "team user CRUD permissions" or "creating a team user" in any literal sense, and the current spec doesn't mention it. Left exactly as it was.

**Deliberately NOT touched**: `RoleController` (dynamic Role management, `manage-roles` permission, held by `admin` and `super_admin`). This edits **global** Role definitions, not a specific team member — a different, pre-existing feature the current spec never names, and it has no reachable frontend page in `AppLayout.tsx`'s nav today (confirmed by `grep` — no "Roles" nav entry exists), so it's already effectively dormant in the UI.

---

## 2. Client Admin Granular RBAC Matrix — moved and re-scoped

Covered mechanically in §1.3. Additional points:

- **Module-based filtering** ("Filter available CRUD options in the matrix so Client Admin can ONLY grant permissions for modules enabled on their Client Account"): the WhatsApp Module checkbox group now renders only if `hasModule('chatbot') || hasModule('send_alert')` is true; the Social Ads Module group only if `hasModule('meta_ads')` is true. **[Inference]**, traced directly to `TeamController::MANAGED_PERMISSIONS`'s own docblock: `whatsapp.view/create/edit/delete` map 1:1 onto `ChatbotRuleController`'s CRUD (i.e. the `chatbot` module), `send-messages` reuses the permission already gating `/alerts/*` (the `send_alert` module), and the 4 `social_ads.*` permissions map 1:1 onto `AdCampaignController` (the `meta_ads` module) — so gating each group on exactly those slugs is the precise, traceable mapping, not a guess. Uses `AuthContext::hasModule()` directly against the caller's own account (no new API call — `/auth/me` already returns `account.allowed_modules`), which only works because the page is now provably Client-Admin-only (no cross-tenant account to disambiguate).
- **Known, disclosed edge case, not fixed**: if a Super Admin disables a module (e.g. `chatbot`) for a client that already had `whatsapp.*` permissions granted to some team members, this page will hide that whole group — the admin can no longer see or revoke those now-stale grants from this screen (though they also can't grant new ones for a disabled module, which is the literal ask). Those permissions remain **active** server-side (`effective`/`direct` are untouched by this filter — only the UI's rendering is conditional). This mirrors a pre-existing, already-disclosed gap: most module toggles in this app are client-side/UI-only, not server-enforced (see §2 of the earlier "Module & Feature Access" audit) — this is not a new regression, just the same gap surfacing in a new place.
- **Team member scope**: unaffected — `GET /team/users` was already, and remains, strictly scoped to the caller's own `account_id` by `TenantIsolationMiddleware` for any non-Super-Admin caller (verified in §3 below). Since this page can no longer be reached by Super Admin at all, its user-picker dropdown is now, by construction, always "team members belonging strictly to their own account_id" — the spec's exact phrase.

---

## 3. Route & Action Policies

- **"Client Admin can create users up to `max_users_limit` and assign CRUD permissions"** — already implemented in an earlier task (`Account::hasReachedUserLimit()` checked in `store()` for every caller; `updatePermissions()` grants exactly the submitted subset). No change needed beyond the new `hasRole('admin')` gate on the permissions endpoints (§1.3).
- **"Restrict user listing APIs so Client Admins only see and manage users under their own `account_id`"** — **[Fact]**, verified by reading `TenantIsolationMiddleware::handle()`: a `?account_id=` query parameter from any non-Super-Admin caller is **never honored** ("only Super Admin may select a different tenant" — the middleware's own docblock, confirmed against its code); the `account_id` request attribute is unconditionally that user's own `account_id`. Every `TeamController` method reads this attribute via `ResolvesTenantAccount`, never `$request->user()->account_id` directly, so this guarantee is uniform across `index`/`store`/`update`/`toggle`/`destroy`/`permissions`/`updatePermissions`. **This was already correct before this task — no code change was needed here**, only verification.

---

## 4. Verified boundary summary

| Action | Super Admin | Client Admin (`admin` role) | `social_marketer` (pre-existing, unrelated grant) |
|---|---|---|---|
| Create Account + 1 Primary Admin | ✅ (only path: `AccountController::store`) | ❌ (no such endpoint) | ❌ |
| Allocate `allowed_modules` (Enabled Module Routes) | ✅ (`AccountController::update`/`updatePermissions`) | ❌ | ❌ |
| `GET /team/users` (roster) | ✅ read-only, cross-tenant | ✅ own account only | ✅ own account only |
| `POST /team/users` (create team member) | ❌ 403 (new) | ✅ up to `max_users_limit` | ✅ (pre-existing `manage-team` grant, unchanged by this task) |
| `PATCH/DELETE /team/users/{id}` (edit/toggle/remove) | ❌ 403 (new — see §1.5's disclosed scope call) | ✅ | ✅ |
| `GET/PATCH /team/users/{id}/permissions` (Matrix) | ❌ 403 (new) | ✅ (`hasRole('admin')` only) | ❌ 403 (new) |
| `/team/permissions` page reachable at all | ❌ (new — `strictRole="admin"`, does not bypass) | ✅ | ❌ |

---

## 5. Verification performed

- **TypeScript**: `npx tsc -b --noEmit` — clean, 0 errors, after all frontend edits (`App.tsx`, `ProtectedRoute.tsx`, `UsersPage.tsx`, new `TeamPermissionsPage.tsx`).
- **Lint**: `npx oxlint` on the four touched/added frontend files — 0 errors; 4 warnings, all the pre-existing `set-state-in-effect` style warning this codebase already tolerates throughout its `load()`-in-`useEffect` convention (same category confirmed in earlier tasks' audits, not a new class of warning).
- **Backend PHP**: no PHP binary exists on-device, so `TeamController.php` was checked with the same comment- and string-literal-aware brace/paren/bracket balancer used in every prior backend change this session — all three bracket types balanced (`()`: 148/148, `{}`: 19/19, `[]`: 54/54).
- Confirmed via `grep` counts that each new guard block appears exactly once per intended method (`store`: 1, `update`/`toggle`/`destroy`: 3 combined, `permissions`/`updatePermissions`: 2 combined) — no duplicate or missing guard.
- Confirmed via `grep` that no remaining import/reference to the old `pages/users/PermissionsMatrixPage` path exists anywhere in `frontend-app/src` (only a descriptive comment inside the new file's own docblock).
- Not executed: no browser/dev server was available on-device to click through as an actual Super Admin vs. Client Admin session and visually confirm the hidden buttons/redirects — verified by code reading (every condition traced above) and the compiler/linter passes, not by an end-to-end UI test.

---

## 6. Known, disclosed follow-ups / decisions requiring your confirmation

1. **§1.5 above** — I blocked Super Admin from `update`/`toggle`/`destroy` on team members (not just create + permissions), reasoning from the audit's own "(User Management & CRUD Permissions)" framing and "strictly separate." This is the one call in this task with real judgment involved; tell me if it's too broad and I'll narrow it back to create + permissions only, in minutes.
2. `enabled_modules` naming (§1.1) — kept as `allowed_modules` rather than renamed; say so if the literal field name matters externally.
3. Old `PermissionsMatrixPage.tsx` was **moved, not deleted** (device permissions) — sits at `_to_delete/frontend-app/src/pages/users/PermissionsMatrixPage.tsx` in your project root; delete that folder yourself when convenient.
4. Stale WhatsApp/Social-Ads permission grants surviving a later module disablement (§2) remain active but hidden from this UI — a pre-existing category of gap (most module toggles are UI-only, not server-enforced), not newly introduced here.
