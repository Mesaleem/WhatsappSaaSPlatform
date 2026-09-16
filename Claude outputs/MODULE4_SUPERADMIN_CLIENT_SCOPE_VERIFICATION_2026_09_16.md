# MODULE 4 FIX — Super Admin "Any Client" Analytics Scope & Selected Client Module Gating
**Date:** 2026-09-16
**Scope Lock honored:** `AnalyticsController.php` ONLY.
**Outcome: this is a verification task, and verification found both objectives already correctly implemented as a direct consequence of the Module 3 (Hierarchical Analytics Rollup) work done earlier the same day. No behavior change was needed or made — only two additive, comment-only clarifications were added at the exact two points this task's audit asked me to trace, so the "selected client, never the caller" invariant is now explicit and self-documenting rather than something a future reader has to re-derive.**

---

## 1. AUDIT FINDING (How selected client module gating currently behaves)

Traced `$groupModuleEnabled`'s two call sites (`summary()` line 228, `charts()` line 583 post-edit) end to end:

**[Fact]** `$account` is reassigned near the top of both `summary()` and `charts()` by `resolveHierarchicalScope()` (added in Module 3), **before** `$groupModuleEnabled` is ever computed later in either method. For `?client_id=X`, that method returns `$account = Account::findCached($clientId)` — the **selected Client's own Account object** — regardless of who's asking (Section 2 below covers the Super-Admin-unrestricted path specifically).

**[Fact]** `$groupModuleEnabled = $account ? $account->hasModuleEnabled('contact_groups') : true;` calls `hasModuleEnabled()` **on `$account`**, never on `$request->user()` or any caller-derived object. Confirmed by reading `Account::hasModuleEnabled()`/`effectiveModules()` (`app/Models/Account.php` lines 423-477) in full: neither method takes a user/caller argument, references `$request`, or reads anything off the authenticated session — every branch operates exclusively on `$this` (the Account instance it's called on) and, when that account has a `agent_id`, on `self::findCached($this->agent_id)` — i.e., **that same account's own real parent Agent**, not the calling user's account. It is structurally impossible for this method to evaluate the Super Admin's or an unrelated Agent's context, because it has no way to see who's calling.

**[Fact]** In `charts()`, `$groupModuleEnabled` is computed **inside** the `Cache::remember(...)` closure, which captures `$account` via `use ($account, ...)`. Verified the closure literal is defined *after* the `resolveHierarchicalScope()` reassignment (both happen before `$data = $request->validate(...)`, which itself precedes the cache-key/`Cache::remember` call) — so the closure captures the already-reassigned, selected-client `$account`, not a stale pre-reassignment value.

**Conclusion: objective 2 was already fully satisfied before this task started.** No gating logic needed to change.

---

## 2. SUPER ADMIN "ANY CLIENT" SCOPE VERIFICATION

Re-read `resolveHierarchicalScope()`'s `?client_id=` branch directly:

```php
if ($request->filled('client_id')) {
    $clientId = (int) $request->query('client_id');
    $clientAccount = Account::findCached($clientId);
    abort_if(! $clientAccount, 404, 'The selected client account was not found.');

    if (! $isSuperAdmin) {
        abort_if(
            $callerAgentScopeId === null || $clientAccount->agent_id !== $callerAgentScopeId,
            403,
            '...'
        );
    }

    return ['account' => $clientAccount, 'accountIds' => [$clientAccount->id], 'scope' => 'account'];
}
```

When `$isSuperAdmin === true`, the entire ownership/tenancy check block is skipped — the target account is returned **unconditionally**, exactly matching "ignoring agent boundaries or tenancy restrictions." `$accountIds` is set to the single-element `[$clientAccount->id]`.

Traced every field the task names through to this `$accountIds`/`$account`:
- `total_sent`/`total_failed`: computed via `resolveRecipientAwareTotals($accountIds !== null ? MessageDispatchLog::whereIn('account_id', $accountIds) : ...)` — with a 1-element array, this is `WHERE account_id IN (X)`, i.e. exactly that client, nothing else.
- `today_breakdown`: via `recipientTypeBreakdown($account, ..., $accountIds)`, same `whereIn` scoping.
- `daily_by_recipient_type`: via `dailyRecipientTypeSeries($account, ..., $accountIds)`, same scoping.
- `active_contact_groups`: via `$accountIds !== null ? ContactGroup::whereIn('account_id', $accountIds)->count() : ...`, same scoping.

**All five fields the task lists are already strictly scoped to only the selected Client. No change was needed.**

---

## 3. MODULE GATING EVALUATION FOR SELECTED CLIENT

Confirmed the exact required outcome, field by field, for a selected Client with `contact_groups` disabled (`$groupModuleEnabled === false`):

| Field | Current code | Result when disabled |
|---|---|---|
| `recipient_breakdown.group` | `if ($recipientBreakdown && ! $groupModuleEnabled) { $recipientBreakdown['group'] = null; }` | `null` ✓ |
| `today_breakdown.today_group_sent` | `$groupModuleEnabled ? $todayRange['group']['sent'] : null` | `null` ✓ |
| `today_breakdown.today_group_failed` | `$groupModuleEnabled ? $todayRange['group']['failed'] : null` | `null` ✓ |
| `active_contact_groups` | only computed `if ($groupModuleEnabled && Schema::hasTable(...))`, else stays `null` | `null` ✓ |
| `daily_by_recipient_type.group` (charts()) | `if ($dailyByRecipientType && ! $groupModuleEnabled) { $dailyByRecipientType['group'] = null; }` | `null` ✓ |

All five outcomes the task specifies were already produced by the existing Module 2/3 code, evaluated against the **selected Client's** entitlement per Section 1.

**One nuance worth being explicit about, not a bug:** `effectiveModules()` (which `hasModuleEnabled()` delegates to) caps a Sub-Client's own `allowed_modules` against **its own real parent Agent's** `allowed_modules` ("Absolute Super Admin Control" — a Sub-Client can never have a module its own Agent doesn't have). This could be misread as "using the parent Agent's context" in the sense the task warns against, but it isn't the same thing: it's the selected Client's own actual entitlement chain (its own Agent, looked up fresh via `findCached($this->agent_id)` off the Client's own `agent_id` column), never the calling Super Admin's or an unrelated Agent's context substituted by mistake. I traced this precisely so as not to either miss a real bug or "fix" a deliberate, already-correct, already-documented business rule. Flagging it here rather than silently agreeing there was an issue.

**Cache-staleness check (relevant to whether gating reflects a just-changed entitlement):** `Account::findCached()` caches for 3600s, but `Account`'s model boot method registers `static::saved(fn ($account) => Cache::forget(...))` and `static::deleted(...)` — so a Super Admin toggling a Client's `contact_groups` module (`AccountController::updatePermissions()`, which saves the Account) invalidates that exact cache key immediately. Verified this is real, existing, working cache-invalidation wiring, not an assumption — a Super Admin's very next analytics request for that client reflects the change instantly, no up-to-1-hour staleness window.

---

## 4. FILES MODIFIED

| File | Change |
|---|---|
| `backend-api/app/Http/Controllers/Api/AnalyticsController.php` | Modified — **comment-only**, zero logic change. Two doc-comment additions at the exact `$groupModuleEnabled` assignment in `summary()` and in `charts()`, making explicit that `$account` there is always the post-`resolveHierarchicalScope()` value and that `hasModuleEnabled()` takes no caller argument. +22 lines, 0 deletions, 0 logic lines altered (both `$groupModuleEnabled = $account ? $account->hasModuleEnabled('contact_groups') : true;` lines are byte-identical to their pre-task text — verified by direct grep). |
| Every other file | **Not touched** (verified via `git status`/`git diff --stat`: `GroupController.php`, `Account.php`, `routes/api.php` all show zero new changes from this task). |

---

## 5. PAYLOAD INTEGRITY CHECK (Module enabled vs disabled client)

Traced both scenarios through the code (no live database access from this environment, so this is a code-path trace, not an executed HTTP test — disclosed, not assumed):

**Selected Client WITH `contact_groups` enabled**, Super Admin calling `?client_id=X`: `$groupModuleEnabled = true` → `recipient_breakdown.group`, `today_breakdown.today_group_*`, `active_contact_groups`, `daily_by_recipient_type.group` all populate with that client's real figures (via the `$accountIds = [X]` scoping from Section 2). `individual`/overall figures populate identically either way.

**Selected Client WITHOUT `contact_groups` enabled**, same caller: `$groupModuleEnabled = false` → all four group-specific fields become `null` (table above), while `total_sent`/`total_failed`/`recipient_breakdown.individual`/`today_breakdown.today_individual_*` remain populated and correct — matching this file's established "absent, not fabricated" convention (used identically for `quota` when there's no subscription) rather than omitting the keys or returning zeros that could be mistaken for real zero-activity data.

**Recommend** (cannot execute from this environment): an actual authenticated request as a Super Admin with `?client_id=` pointed at one client with the module on and one with it off, diffing the two JSON responses to confirm exactly the five fields above differ and nothing else does.

---

## 6. DATABASE SAFETY CONFIRMATION

No migration, no `migrate:fresh`/`db:wipe`/`TRUNCATE`/`DROP TABLE`, no destructive command of any kind — none executed, none needed. This task made a documentation-only change to one existing PHP file.

---

## 7. SYNTAX / CODE INTEGRITY CHECK

**PHP CLI remains unavailable in this environment** (`which php` → not found) — disclosed, not assumed passing, same as every prior task this session. In its place:

- Brace/paren/bracket balance verified after the edit: `{}` 62/62, `()` 643/643, `[]` 118/118 — balanced.
- Both `$groupModuleEnabled = ...` logic lines confirmed byte-identical to their pre-task text via direct grep (only the preceding comment blocks changed).
- Confirmed via `git diff --stat` that this task's diff added **0 deletions** beyond what Module 3 already had (67, unchanged) — proof the edit was pure insertion, not a modification of any existing line.

---

## 8. GIT DIFF SUMMARY

```
$ git diff -w --stat -- backend-api/app/Http/Controllers/Api/AnalyticsController.php
 backend-api/app/Http/Controllers/Api/AnalyticsController.php | 543 ++++++++++++++++++---
 1 file changed, 476 insertions(+), 67 deletions(-)
```

(454 insertions / 67 deletions were already present after Module 3; this task added exactly 22 insertions / 0 deletions — the two comment blocks — confirmed via `grep -c "Module 4 Fix"` → 2 tagged locations, one per method.)

`git status --short` on `GroupController.php`, `Account.php`, and `routes/api.php` shows zero changes attributable to this task (`routes/api.php`'s existing +33/-0 is unchanged carry-over from an earlier session, as in every prior report this session).

---

## 9. REMAINING OBSERVATIONS

- The "parent Agent's context" phrasing in this task's objective 2 could, on a shallower read, be mistaken for a bug in `effectiveModules()`'s existing Agent-capping behavior. It isn't — that capping is a deliberate, already-shipped, already-documented business rule ("Absolute Super Admin Control"), and it operates on the *selected Client's own* real Agent, not an unrelated one. I traced this explicitly (Section 3) rather than silently assuming agreement with the task's framing, since removing that capping would be a real regression to an intentional feature, not a fix.
- No new gap was found. If you have a specific reproduction where a Super Admin viewing `?client_id=` actually saw group data for a module-disabled client (or vice versa), that would point to something this code trace didn't surface — cache staleness was checked and ruled out (Section 3), so I'd want the exact request/response pair to keep investigating rather than guess further.
- As in the Module 3 report: PHP CLI and live database access remain unavailable from this environment, so this and Section 5's checks are code-path traces backed by direct reads of the actual source, not executed tests.

---

## 10. FINAL VERIFICATION

- [x] Audited `$groupModuleEnabled` resolution in both `summary()` and `charts()` — confirmed it operates on the resolved (post-hierarchical-scope) `$account`, never the caller.
- [x] Confirmed `hasModuleEnabled()`/`effectiveModules()` take no caller/user argument and cannot structurally evaluate the wrong context.
- [x] Confirmed Super Admin `?client_id=` scope is unrestricted (no agent-boundary/tenancy check) and that all five named fields (`total_sent`, `total_failed`, `today_breakdown`, `daily_by_recipient_type`, `active_contact_groups`) are strictly scoped to the selected Client via `$accountIds = [$clientId]`.
- [x] Confirmed all four group-specific fields correctly null out when the selected Client lacks `contact_groups`.
- [x] Checked and ruled out a cache-staleness concern (`Account::findCached()` is invalidated on every Account save).
- [x] Disambiguated the "parent Agent's context" phrasing from `effectiveModules()`'s legitimate, unrelated Agent-capping design, so as not to misreport a non-bug as fixed or flag a working feature as broken.
- [x] Made the invariant explicit via two additive, comment-only edits — zero behavior change, verified byte-identical logic lines.
- [x] No database mutation; no file outside `AnalyticsController.php` touched.
- [ ] `php -l` / live HTTP test — **not executed, PHP CLI and DB access unavailable in this environment**; a concrete before/after JSON diff is recommended in Section 5 as the way to close this out on your end.

**Bottom line:** Both of this task's objectives were already fully satisfied by the Module 3 work completed earlier — no functional bug was found. I made a small, purely additive documentation change so the "selected client, never the caller" guarantee is explicit in the code itself rather than something that has to be re-derived by tracing five files, and I flagged one place where the task's own wording could be misread as describing a bug that doesn't actually exist.
