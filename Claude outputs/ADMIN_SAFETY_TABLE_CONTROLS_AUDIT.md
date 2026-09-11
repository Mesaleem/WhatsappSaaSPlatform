# Admin Safety & Table Controls — Audit Report

**Scope:** Team Users management (invite, permissions matrix, table actions) across `backend-api/app/Http/Controllers/Api/TeamController.php`, `frontend-app/src/pages/users/UsersPage.tsx`, and `frontend-app/src/pages/team/TeamPermissionsPage.tsx`.

## 0. Path correction (disclosed up front)

The request named `frontend-app/src/pages/team/TeamUsersPage.tsx` and `frontend-app/src/components/team/InviteMemberModal.tsx`. **[Fact, verified by directory search]** Neither file exists in this repository. The Team Users table and the invite form are implemented as a single consolidated page: `frontend-app/src/pages/users/UsersPage.tsx` (792 lines) — the invite UI is an in-file `InviteUserModal` component, not a standalone `InviteMemberModal.tsx`. `frontend-app/src/pages/team/TeamPermissionsPage.tsx` does exist exactly as named. All work below was applied to the real files; no new files were created, no duplicate/parallel implementation was introduced.

## 1. Admin self-preservation & button visibility (`UsersPage.tsx`)

**Permissions link, hidden for admin-role targets.** Before this change the link was gated only on the *viewer's* role (`hasRole('admin')`). Added a second condition on the *target row*:
```tsx
{hasRole('admin') && !u.roles.some((r) => r.name === 'admin') && (
  <button onClick={() => navigate(`/team/permissions?user=${u.id}`)}>Permissions</button>
)}
```
Verified: an Admin-role row no longer renders the Permissions link, for any viewer.

**Remove, own row.** **[Fact]** This was already implemented before this change — `disabled={busyId === u.id || isSelf}` with a tooltip. No code change was needed; confirmed by reading the pre-existing source.

**Deactivate/Activate, own row.** This one was **not** already guarded — `disabled={busyId === u.id}` only. Fixed to match the existing Remove-button pattern:
```tsx
disabled={busyId === u.id || isSelf}
title={isSelf ? 'You cannot deactivate your own account' : undefined}
```
Verified against `isSelf = currentUser?.id === u.id`, already computed per row.

## 2. Invite/Edit form — Admin role excluded (`TeamController.php` + `UsersPage.tsx`)

The invite form's role checkboxes (`RoleCheckboxList`) render directly from whatever `GET /api/team/roles` returns — there is no separate hardcoded list to edit on the frontend. Fixed at the single source of truth:
```php
// TeamController::roles()
$roles = Role::whereNotIn('name', ['super_admin', 'admin'])->orderBy('name')->get(['id', 'name']);
```
(was: `Role::where('name', '!=', 'super_admin')`). Verified by reading `UsersPage.tsx`'s `RoleCheckboxList` — it has no independent role source, so this backend change removes the Admin checkbox from the invite form with no frontend change required.

**Beyond the literal ask, disclosed:** the same exclusion was added to `TeamController::store()`'s and `update()`'s `role_names.*` validation rule (`whereNotIn('name', ['super_admin', 'admin'])`, was `!= 'super_admin'` only). Rationale: `store()` is the create-time enforcement point (item 5 of the request); `update()` uses the identical validation pattern and, left unpatched, would let a Client Admin bypass the create-time restriction by inviting a Team Member first and then promoting them to Admin via Edit. `updateUser`/`UpdateTeamUserPayload` currently has no wired-up UI (verified — no "Edit User" button exists in `UsersPage.tsx`, confirmed by full-file read), so this is presently a latent/defense-in-depth fix, not a currently-reachable bug — flagged so it isn't mistaken for scope creep.

## 3. Permissions matrix dropdown filtering (`TeamPermissionsPage.tsx`)

```tsx
const nonAdminUsers = useMemo(() => users.filter((u) => !u.roles.some((r) => r.name === 'admin')), [users]);
```
Used for both the `<select>` options **and** the `selectedUser` lookup (previously `users.find(...)`) — filtering only the dropdown options would have left a stale `?user=<admin-id>` URL still loading and displaying that Admin's (non-editable) matrix. Banner added, exact text as specified:
```tsx
{!isLoadingUsers && nonAdminUsers.length === 0 && (
  <div>...All team members in this account are Admins with full access.</div>
)}
```

## 4. Universal Clear Filters button (`UsersPage.tsx`)

**[Fact]** `ClearFiltersButton` already existed as a shared component in `components/common/DataTableControls.tsx`, used by 9 other pages (AccountsPage, TemplateManagerPage, AuditLogsPage, ChatbotPage, DeveloperPage, NotificationsPage, MetaAdsPage, SocialInboxPage, AnalyticsPage) — it was simply not yet wired into `UsersPage.tsx`. Added, matching the exact convention used by those pages (plain `const`, not `useMemo`):
```tsx
const hasActiveFilters =
  search !== '' || roleFilter !== '' || statusFilter !== '' || clientFilter !== '' || from !== '' || to !== '';
const clearFilters = () => { setSearch(''); setRoleFilter(''); setStatusFilter(''); setClientFilter(''); setFrom(''); setTo(''); };
```
placed at the end of the existing filter bar. Note: `clientFilter` (the Super-Admin-only "All clients" dropdown, visible only in global scope) was included in both the active-check and the reset — the request named Search/Role/Status/Date Range specifically, but leaving `clientFilter` out of "Clear Filters" would mean the button doesn't actually clear every active filter on the page for a Super Admin viewer. Disclosed as an in-scope addition, not silently bundled.

## 5. Backend controller enforcement (`TeamController.php`)

**`toggle()` (the actual deactivate/activate endpoint — there is no separate `deactivate()` method; the request's item 5 names one, disclosed here).** Was missing a self-guard entirely (only the account-owner was protected). Added:
```php
abort_if($request->user()->id === $user->id, 403, 'You cannot deactivate or remove your own account.');
```

**`destroy()`.** **[Fact]** A self-guard already existed, but as `422` with the message `'You cannot remove your own account.'`. Changed to `403` with the exact message specified, to match the new `toggle()` guard and because a self-lockout attempt is an authorization failure, not a validation error. This is a behavioral change to an already-shipped endpoint: verified `extractErrorMessage()` (the frontend's shared error-display helper) reads only `response.data.message`, never branches on status code, so no frontend code depends on the old `422`.

**`store()`.** Validation now excludes `admin` from `role_names.*` (see §2). Super Admin was already blocked from calling `store()` at all (pre-existing check at the top of the method), so this rule's practical effect is exactly "a non-Super-Admin cannot assign `admin`," as requested.

## 6. Verification performed

- `npx tsc -b` in `frontend-app/` — **0 errors.**
- `npx oxlint` on both modified `.tsx` files — **0 errors**, 4 pre-existing warnings, all on `useEffect` calls this change did not touch (confirmed by line number against the diff).
- PHP: **[Unknown / disclosed limitation]** no `php` binary is available in this execution environment, so `php -l` / `php artisan test` could not be run. Verified instead by (a) a balanced-braces/parens/brackets check across the whole file, (b) reading the full modified method bodies to confirm each edit's surrounding syntax is intact, and (c) every new line reuses syntax already proven elsewhere in this exact file (`abort_if(...)`, `Rule::exists(...)->where(fn ($q) => ...)`). This is not a substitute for actually running the test suite — recommend running `php artisan test` (or at minimum `php -l` on this file) on your machine before deploying.
- Backend test coverage for these endpoints: **0** — `backend-api/tests/` contains only the unmodified Laravel skeleton tests (confirmed by prior repository read). None of the guards above are covered by an automated test; a manual run-through (or new Feature tests for `TeamController`) is recommended before this ships, given this is exactly the kind of self-lockout/RBAC logic your own prior audit reports in this folder have flagged as a recurring risk area.

## Files changed

- `backend-api/app/Http/Controllers/Api/TeamController.php` — `toggle()`, `destroy()`, `store()`, `update()`, `roles()`
- `frontend-app/src/pages/users/UsersPage.tsx` — import, filter state, filter bar, Permissions/Deactivate buttons
- `frontend-app/src/pages/team/TeamPermissionsPage.tsx` — dropdown filtering + banner
