# Super Admin Edit & Deactivation Capabilities — Audit Report

**Scope:** `frontend-app/src/pages/users/UsersPage.tsx` (the real "Team Users" page — see the prior `ADMIN_SAFETY_TABLE_CONTROLS_AUDIT.md` for why `TeamUsersPage.tsx` doesn't exist as a separate file) and `backend-api/app/Http/Controllers/Api/TeamController.php`.

## 1. Super Admin action visibility (`UsersPage.tsx`)

| Action | Before this change | After this change | Verified |
|---|---|---|---|
| Edit | Did not exist anywhere in the app (`teamService.updateUser()`/`UpdateTeamUserPayload` existed but were unused dead code) | New — Super-Admin-only, opens a new `EditUserModal` (name/email/phone/role) | `{superAdmin && (<button>Edit</button>)}` |
| Deactivate/Activate | Hidden entirely for Super Admin | Shown for Super Admin (and Client Admin) | `{!isSelf && (<button>{u.is_active ? 'Deactivate' : 'Activate'}</button>)}` — no `superAdmin` gate left |
| Reset Password | Already shown for Super Admin | Unchanged | `{isSuperAdmin() && (...)}` — pre-existing, not touched |
| Permissions | Hidden for Super Admin (viewer's role check) | Still hidden for Super Admin — now **also** hidden for the viewer's own row | `{hasRole('admin') && !isSelf && !u.roles.some(r => r.name === 'admin') && (...)}` |

`hasRole('admin')` is literally false for a Super Admin viewer (their role is `super_admin`), so "HIDE Permissions link... across all users" for Super Admin holds by construction — not a new check, but re-verified after this edit.

**Edit is scoped to Super Admin only**, per the request's literal text ("Show Edit action" appears only under "SUPER ADMIN ACTION BUTTONS"). Client Admin has no Edit trigger for their own team today — flagging this in case that was assumed to come along for free; it did not, and adding it is a small, separate follow-up if wanted.

**Remove** was not requested for Super Admin and was left untouched (`{!superAdmin && !isSelf && (...)}`) — Super Admin still cannot remove a user, only deactivate/edit them.

## 2. Client Admin self-preservation — hide vs. disable (a deliberate change)

The prior refactor (see `ADMIN_SAFETY_TABLE_CONTROLS_AUDIT.md`) made Deactivate and Remove **disabled-with-tooltip** on the caller's own row, matching the pre-existing Remove-button convention. This request explicitly says **"Hide"**, so both are now not rendered at all on the caller's own row (`!isSelf` gates the whole button, not just its `disabled` prop) — a genuine UX change from the last iteration, not a bug. Permissions gained the same self-hide, which it did not have before (it was previously only gated on the target's role, not on self).

"Show Permissions ONLY for non-admin team members belonging to the same `account_id`" — the account_id scoping was already structurally guaranteed before this change: a Client Admin's `GET /team/users` is always scoped to their own account by `TeamController::index()`, so their table never contains another tenant's rows to begin with. No code change was needed for that half of the requirement; verified by re-reading `index()`.

## 3. Backend policy (`TeamController.php`)

**`update()` and `toggle()`** (the actual activate/deactivate endpoint — there is no separate `deactivate()` method; flagged again from the prior report since this request also names one): the `if ($request->attributes->get('is_super_admin')) { return 403; }` guard was removed from both. Every other guard was left intact and untouched:
- The account-owner protection (`abort_if($account->owner?->id === $user->id, ...)`) still applies to Super Admin too — deliberately not weakened. Editing/deactivating the account's own owner has no ownership-transfer flow behind it regardless of who's asking, so this stays a hard block for everyone, Super Admin included. This wasn't asked for explicitly either way; leaving it in place is the conservative, minimal-change choice.
- The self-lockout guard added in the prior refactor (`abort_if($request->user()->id === $user->id, 403, ...)`) is unaffected — verified it can never fire for a Super-Admin caller in the first place, since `$user` here is always resolved via `User::where('account_id', $account->id)->find($id)`, and a Super Admin's own row always has `account_id === null`, so it can never be the row a Super Admin is acting on.
- `update()`'s `role_names.*` validation still excludes both `super_admin` and `admin` unconditionally (unchanged from the prior refactor) — so Super Admin cannot use the new Edit action to promote anyone to Admin either. This was a deliberate choice, not an oversight: the alternative (letting Super Admin assign `admin` here) would have been inconsistent with `TeamController::roles()` still excluding `admin` from the picker for everyone, and was not asked for — flagging it so it's an explicit decision, not a silent gap.

**`permissions()` / `updatePermissions()`** — verified unchanged. Both still gate on the literal `if (! $request->user()->hasRole('admin')) { return 403; }`, which already does double duty: it rejects Super Admin (role name `super_admin`, never `admin`) and it structurally guarantees "matching `account_id`" for a Client Admin caller, because `TenantIsolationMiddleware` never honors a `?account_id=` override for a non-Super-Admin — a Client Admin's `requireAccount()` can only ever resolve to their own account. No change was needed here; this requirement was already fully satisfied before this task.

**`destroy()`** — untouched, still rejects Super Admin (matching the frontend's Remove button staying Client-Admin-only).

## 4. Verification performed

- `npx tsc -b` in `frontend-app/` — **0 errors.**
- `npx oxlint` on `UsersPage.tsx` — **0 errors**, 2 pre-existing warnings, both on `useEffect` calls this change did not touch.
- PHP: same disclosed limitation as the prior report — no `php` binary in this execution environment. Verified by a balanced-braces/parens check plus a full read of both modified methods; every new/removed line reuses syntax already proven elsewhere in this exact file. Recommend `php artisan test` (or at least `php -l`) before deploying — there is still **zero** automated test coverage on `TeamController`.

## Files changed

- `backend-api/app/Http/Controllers/Api/TeamController.php` — `update()`, `toggle()` (guard removed; all other checks intact)
- `frontend-app/src/pages/users/UsersPage.tsx` — new `EditUserModal`, `editUser` state, Actions column restructured (Edit added; Permissions/Deactivate/Remove re-gated), page-level docblock updated to match
