# MODULE 2 FIX — Runtime Graph/Count Data & API-Level Group Module Protection
**Date:** 2026-09-16
**Scope Lock honored:** `AnalyticsController.php`, `Api/V1/GroupController.php`, Analytics Services (helper methods inside `AnalyticsController.php`). No other file was edited.

---

## 1. AUDIT FINDING (root cause of 0/blank graph data)

Two distinct findings, evidence-ranked:

**[Fact] Primary, already-fixed root cause.** Earlier in this same session (task: "Group Messaging Undercount Fix (P0)"), `AnalyticsController.php`'s `summary()`/`charts()`/`computeGlobalSummary()` were counting `message_dispatch_logs` **rows** as "1 message" each. A group-send batch is stored as a **single row** representing an entire batch (see `MessageDispatchLog::resolveGroupDispatch()`), so any account whose traffic was predominantly or entirely group messages would show severely undercounted — in some cases effectively zero — sent/failed figures on every KPI card and chart, despite the underlying rows existing. This was fixed by introducing `resolveRecipientAwareTotals()` (sums `success_count`/`failure_count` for group rows instead of counting rows) and is confirmed still intact and correct as of this task (re-read in full before making any new change). If the "0 or blank" symptom you're seeing predates that fix being deployed, it is the most direct, evidence-backed explanation and should already be resolved once this code is live.

**[Hypothesis] Secondary, NOT fixed — flagged only.** `config/database.php`'s `mysql`/`mariadb` connection arrays have no `'timezone'` key (confirmed by direct read and by an exhaustive grep across `app/`, `config/`, `bootstrap/` for `time_zone`, `DB_TIMEZONE`, `setTimezone` — zero hits). `.env` sets `APP_TIMEZONE=UTC`. Both `message_dispatch_logs` and `contact_groups` use Laravel's default `$table->timestamps()`, i.e. MySQL `TIMESTAMP` columns, which MySQL converts to/from UTC using the **session's** `time_zone` variable — a variable Laravel never pins here. If the live MySQL server's session/system timezone is not UTC, date-range boundary queries (`whereBetween('created_at', [$from, $to])` in `resolveRange()`/`charts()`) can silently miscount rows near day boundaries. **[Unknown]:** I cannot confirm the live server's actual `time_zone` setting — no `mysql` client or `php` CLI is reachable from this environment. This is reported as a candidate, not implemented, because the fix lives in `config/database.php`, which is outside this task's explicit Scope Lock ("Analytics Controller, Group Controller, and Analytics Services ONLY"). Recommended fix if you confirm this is still occurring: add `'timezone' => '+00:00',` to the `mysql`/`mariadb` connection arrays in `config/database.php`.

**[Fact] Not a bug:** Explicit-zero gap-filling for empty daily slots was audited and is already correct — `dailyRecipientTypeSeries()` fills every day in range with `'sent' => 0, 'failed' => 0` when no row exists (never `null`/missing). Overall totals (`Overall = Individual + Group`) are correct by construction via the shared `resolveRecipientAwareTotals()` helper. No change was needed for either of these.

---

## 2. TIMEZONE/DATE RANGE FIX APPLIED

**None applied to `config/database.php`** — see the reasoning in Section 1: that file is outside this task's Scope Lock, and I could not empirically confirm the live server's timezone from this environment to justify an in-scope-adjacent change. No date/timezone logic inside `AnalyticsController.php` itself was found to be defective (`resolveRange()`'s `Carbon::parse()->startOfDay()/endOfDay()` construction is correct application-level logic; the risk, if any, is purely at the MySQL session-timezone layer, outside this controller). **This is the one open item from your objectives I did not implement — flagging it explicitly rather than silently skipping it.**

---

## 3. MODULE GATING IMPLEMENTED IN GROUP CONTROLLER

**No change made — already correct.** `Api/V1/GroupController.php`'s `create()` method (the only action in this controller, backing `POST /api/v1/whatsapp/groups/create`) already contains:

```php
if (! $account->hasModuleEnabled('contact_groups')) {
    return response()->json([
        'success' => false,
        'error_code' => 'GROUP_MODULE_DISABLED',
        'message' => 'Group Messaging is a paid feature. Please upgrade your subscription plan to unlock custom contact groups.',
    ], 403);
}
```

Two corrections to the task's stated premises, both verified by direct source inspection:
- There is no `whatsapp_group` module slug anywhere in this codebase. The real, only slug is **`contact_groups`** (`Account::MODULES`, `app/Models/Account.php`). Using `contact_groups` here (not inventing a second, parallel slug) is the correct choice — it's the same slug `ContactGroupController`'s routes and this file's existing check already use.
- There is no `account_modules` table. Entitlement is resolved via `Account::hasModuleEnabled(string $module): bool`, backed by the `allowed_modules`/`effective_modules` JSON attributes on `accounts`.

The app's *other* group surface, `ContactGroupController.php` (Sanctum-session, `/api/groups/*`), is also already fully gated — its routes carry `module.guard:contact_groups` middleware (`routes/api.php`), which returns the identical 403/`GROUP_MODULE_DISABLED` shape. Both group surfaces were verified, not assumed.

**git status confirms zero lines changed in `Api/V1/GroupController.php` or `ContactGroupController.php` by this task.**

---

## 4. MODULE GATING IMPLEMENTED IN ANALYTICS CONTROLLER

This is the one real, previously-unprotected gap this audit found, and the one code change this task made. Before this fix, `summary()` and `charts()` returned group-specific figures (`recipient_breakdown.group`, `today_breakdown.today_group_sent/failed`, `active_contact_groups`, `daily_by_recipient_type.group`) **unconditionally** — regardless of whether the resolved account has the `contact_groups` module enabled. The frontend already hides this data behind `hasModule('contact_groups')`, but a direct API call bypassing the UI could still read it. That's a real group-data-leak gap, squarely inside this task's Scope Lock.

Fix, applied identically in both `summary()` and `charts()`:

```php
$groupModuleEnabled = $account ? $account->hasModuleEnabled('contact_groups') : true;
```

- Global/no-account scope (Super Admin with no client selected) is treated as enabled — consistent with every other field in this file that already renders a true platform-wide aggregate for `$account === null`.
- In `summary()`: `today_breakdown.today_group_sent`/`today_group_failed` become `null` (individual fields untouched); `recipient_breakdown.group` becomes `null` (individual untouched); `active_contact_groups` becomes `null`.
- In `charts()`: `daily_by_recipient_type.group` becomes `null`. The combined `daily` series (overall sent/failed, individual+group summed) is deliberately left untouched — it's an overall figure, not a group-specific one, so hiding it isn't required and would break the primary Message Pulse chart for every account.

Response shape is preserved either way (keys are always present; values become `null` rather than being omitted), matching this file's existing "absent, not fabricated" convention already used for `quota` and other optional fields.

---

## 5. FILES MODIFIED

| File | Change |
|---|---|
| `backend-api/app/Http/Controllers/Api/AnalyticsController.php` | Modified — module-gating added to `summary()` and `charts()` (net +55 lines, 3 surgical insertions, 0 lines of pre-existing logic altered or removed) |
| `backend-api/app/Http/Controllers/Api/V1/GroupController.php` | **Not modified** — audited, already correct |
| `backend-api/app/Http/Controllers/Api/ContactGroupController.php` | **Not modified** — audited, already correct (route-level gating) |
| `backend-api/config/database.php` | **Not modified** — timezone hypothesis flagged in Section 1/2, outside Scope Lock, not implemented |

No RoleController, template ownership, or authentication-logic files were touched.

---

## 6. API PAYLOAD STRUCTURE VERIFICATION

The task requested Recharts-style camelCase fields (`individualSent`, `individualFailed`, `groupSent`, `groupFailed`, `overallSent`, `overallFailed`, `overallTotal`). **I did not rename any fields to match these**, and want to be explicit about why: the existing, already-implemented, already-frontend-consumed API fields are:

- `recipient_breakdown.individual.sent` / `.failed` (≈ `individualSent`/`individualFailed`)
- `recipient_breakdown.group.sent` / `.failed` (≈ `groupSent`/`groupFailed`)
- `total_sent` / `total_failed` (≈ `overallSent`/`overallFailed`)
- `total_alerts_attempted` (≈ `overallTotal`)

Renaming these to the requested camelCase names would be a breaking API-contract change and would regress the frontend work already built against the current snake_case shape (`AnalyticsPage.tsx`, `DashboardPage.tsx`, `types/analytics.ts`) in prior tasks this session. Per the Minimal Change Principle and Regression Prevention constraints, I left field names as-is rather than silently breaking the frontend or silently ignoring the request — flagging it here so you can decide (e.g. a frontend-side mapping layer, if the camelCase names are needed for a specific external consumer, would be the safer place for this).

Every field the task asked to see present is present in the actual response: individual sent/failed, group sent/failed (now module-gated), and overall sent/failed/total — just under their existing names.

---

## 7. DATABASE SAFETY CONFIRMATION

- **No migrations were run or created.** No `migrate:fresh`, `db:wipe`, `TRUNCATE`, or `DROP TABLE` was executed or considered.
- **No index is missing that this task's change requires.** The fix is pure PHP-side response-shaping (conditionally nulling out already-computed values) — it adds no new query, so there is nothing new to index.
- No destructive operation of any kind was performed. All work was read-only audit followed by additive/conditional logic changes to one file.

---

## 8. SYNTAX/RUNTIME VALIDATION RESULTS

**PHP CLI is not installed/reachable in this environment** (`which php` → not found, checked common paths `/usr/bin/php`, `/usr/local/bin/php`, `/opt/php/bin/php`, `php8`, `php8.2`, `php8.3` — none present). This is the same disclosed limitation as every prior task in this session; I did not claim `php -l` passed. In its place:

- Brace/paren/bracket balance verified programmatically after the edit: `{}` 53/53, `()` 543/543, `[]` 104/104 (all balanced).
- Full manual proofread of the diff (reproduced in Section 9) against the original code — confirmed no pre-existing line was altered other than the three targeted insertion points, and every new branch has both a truthy and falsy path assigning the variables it introduces (`$groupModuleEnabled`, `$recipientBreakdown`, `$activeContactGroups`).
- Static-shape check: every response key that existed before this change still exists after it (only values conditionally become `null`), so no consumer expecting a given key to always be present will break.

I was not able to run `php artisan test` or hit the live endpoint for the same PHP-unavailability reason. **Recommend running `php -l` and an actual authenticated request against `/api/analytics/summary` (with a test account that has `contact_groups` disabled) on your end before considering this fully verified in production.**

---

## 9. GIT DIFF SUMMARY

```
$ git diff -w --stat
backend-api/app/Http/Controllers/Api/AnalyticsController.php | 306 +++++++++++++++++----
backend-api/app/Models/WhatsAppFlow.php                       |  17 ++
backend-api/app/Models/WhatsAppFlowSession.php                |  13 +
backend-api/routes/api.php                                    |  33 +++
frontend-app/src/components/layout/AppLayout.tsx               |  26 +-
frontend-app/src/pages/DashboardPage.tsx                       | 191 ++++++++++---
frontend-app/src/pages/admin/ActivityLogsPage.tsx               |  20 +-
frontend-app/src/pages/analytics/AnalyticsPage.tsx              | 263 ++++++++++++++++++
frontend-app/src/types/analytics.ts                             |   7 +-
9 files changed, 776 insertions(+), 100 deletions(-)
```

Only `AnalyticsController.php` changed **in this task**; every other file in that list is unchanged carry-over from prior tasks this session (confirmed by isolating this task's edits with `grep -n "Module 2 Fix"` against the diff — all three new insertions are tagged and locatable). `AnalyticsController.php` grew from 251 to 306 changed-lines-vs-HEAD (net +55 lines from this task specifically: 3 insertions, 0 deletions of pre-existing code).

`git status --short app/Http/Controllers/Api/V1/GroupController.php` returned empty — confirms zero changes to that file, consistent with Section 3's "already correct" finding.

---

## 10. FINAL VERIFICATION

- [x] Read-only audit performed before any edit (`Account.php`, `EnsureModuleEnabledMiddleware.php`, `routes/api.php`, both Group controllers, both relevant migrations, `.env`, `config/database.php`).
- [x] Task's incorrect premises (`account_modules` table, `whatsapp_group` slug, "GroupController lacks protection") identified and corrected with evidence rather than assumed.
- [x] Group Controller (`Api/V1/GroupController.php`) — verified already 403-gated on `contact_groups`; no change made.
- [x] Analytics Controller — module-gating added for `recipient_breakdown.group`, `today_breakdown.today_group_*`, `active_contact_groups` (in `summary()`), and `daily_by_recipient_type.group` (in `charts()`); overall/individual metrics render unconditionally.
- [x] No database mutation, migration, or destructive command executed.
- [x] No RoleController, template-ownership, or authentication-logic file touched.
- [x] `git diff -w --stat` confirms only `AnalyticsController.php` changed in this task.
- [ ] `php -l` — **not executed, PHP CLI unavailable in this environment; disclosed, not assumed passing.**
- [ ] Timezone/date-range root cause — **flagged as an unconfirmed [Hypothesis], not fixed, `config/database.php` change outside Scope Lock; needs your confirmation against the live MySQL server before I (or you) touch it.**
- [ ] Requested camelCase field renaming — **declined with reasoning (Section 6), to avoid an API-breaking regression of already-shipped frontend work.**

**Bottom line:** The Group Controller requirement was already satisfied before this task (verified, not re-implemented). The Analytics Controller had one real, in-scope group-data-leak gap, now closed. The "0/blank graph data" symptom's most likely, already-fixed cause is documented in Section 1; a secondary, unconfirmed timezone hypothesis is flagged but not acted on, pending your decision since fixing it requires touching a file outside this task's stated scope.
