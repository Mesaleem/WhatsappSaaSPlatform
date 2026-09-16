# Fix Report — Group KPI Cards + Message Pulse / Analytics Chart Clarity (2026-09-16)

**Scope executed:** exactly the two problems in this task (Super Admin group KPI cards; ambiguous Message Pulse / Analytics chart). The P0 undercount fix (`resolveRecipientAwareTotals()`, `summary()`'s/`charts()`'s/`computeGlobalSummary()`'s total_sent/total_failed logic) was **not touched** — verified below. No RoleController change, no Agent rollup, no new RBAC logic, no module-guard removal, no schema change.

---

## 1. Root cause — missing Super Admin group KPI cards

`SuperAdminDashboard` (the component rendered for Super Admin's platform-wide view, `frontend-app/src/pages/DashboardPage.tsx`) fetches its data **exclusively** from `analyticsService.getGlobalSummary()` → `GET /api/analytics/global-summary` → `AnalyticsController::computeGlobalSummary()`. That method has **zero group-messaging fields of any kind** — no group count, no group sent/failed, today or otherwise (confirmed by direct read of the method before making any change).

The correct, already-existing recipient-aware group data (`recipient_breakdown`, `today_breakdown`, `active_contact_groups`) lives on a **different** endpoint, `GET /api/analytics/summary` (`AnalyticsController::summary()`), which `SuperAdminDashboard` never calls at all — it's only ever called by `TenantDashboard` (the per-tenant/impersonated view), where the group KPI cards already render correctly and were never broken.

So this was not a frontend rendering bug and not a broken `hasModule()` check — it was a **data-source gap**: the Super Admin global view was simply never wired to the endpoint that already had the numbers. One partial exception: `active_contact_groups` specifically was *also* hard-coded to `null` whenever `summary()` is called with no account selected (the exact condition Super Admin's global view is in), so even wiring up that endpoint wouldn't have produced a real "Total Groups Added" figure — this one field needed a small, deliberate backend widening (below).

---

## 2. Exact files changed

| File | What changed |
|---|---|
| `backend-api/app/Http/Controllers/Api/AnalyticsController.php` | One field's logic widened: `active_contact_groups` in `summary()` now returns a true platform-wide `ContactGroup::count()` when scope is global (`$account === null`), instead of a hard `null`. Everything else in this file — including every P0-fix line — untouched. |
| `frontend-app/src/pages/DashboardPage.tsx` | `SuperAdminDashboard`: added a second, independent fetch of `GET /api/analytics/summary` (global scope) and a new "Group Messaging" card section (5 cards). `DailyPulseChart`: replaced the ambiguous "all/individual/group" toggle + generic Sent/Failed labels with four simultaneous, explicitly-labeled series. |
| `frontend-app/src/pages/analytics/AnalyticsPage.tsx` | `ChartsSection`'s "Daily Message Volume" chart: same four-series treatment as `DailyPulseChart`. |
| `frontend-app/src/types/analytics.ts` | Updated the `active_contact_groups` doc comment to describe its new (global-aware) behavior. No type shape changed — still `number \| null`. |

No other file was touched. `git status` before and after shows the same pre-existing, whitespace-only (CRLF) modified files this repository has carried since before this session (`ci.yml`, several unrelated frontend files, `qr-engine-service/src/services/backendClient.js`) — confirmed unrelated via `git diff -w --stat` returning empty for all of them, exactly as in the prior P0 fix's own verification.

---

## 3. Exact components/API fields involved

- Backend: `AnalyticsController::summary()` (`GET /api/analytics/summary`) — `recipient_breakdown.group.{sent,failed}`, `today_breakdown.today_group_{sent,failed}` (both already correct, reused as-is), `active_contact_groups` (widened).
- Frontend: `SuperAdminDashboard` (new `groupSummary` state/fetch + "Group Messaging" card section), `DailyPulseChart` (rewritten), `ChartsSection` in `AnalyticsPage.tsx` (rewritten). `TenantDashboard`'s own existing group cards were **not modified** — they already worked correctly and were the reference pattern this fix copied.

---

## 4. Root cause — ambiguous Message Pulse chart

`DailyPulseChart` (Dashboard) and `ChartsSection`'s "Daily Message Volume" chart (Analytics page) both plotted `Sent`/`Failed` as two generic series with no indication of recipient type. The Dashboard chart additionally had an "all / individual / group" toggle that only ever showed **one** Sent/Failed pair at a time under those same ambiguous labels — so even after switching to the "group" view, the lines were still just labeled "Sent" and "Failed," with no persistent visual cue for which mode was active. The Analytics page chart didn't even have that toggle — it only ever showed the combined figures, with `daily_by_recipient_type` unused.

---

## 5. Chart series before vs. after

**Before** (both charts): two series, `Sent` and `Failed`, sourced from `charts.daily` (or, on the Dashboard, optionally from `charts.daily_by_recipient_type.individual` or `.group` via a toggle — still labeled just "Sent"/"Failed" either way).

**After** (both charts, when `daily_by_recipient_type` is present and the viewer has the `contact_groups` module): four series shown simultaneously —

| Series | Source (unchanged data) | Color | Line style |
|---|---|---|---|
| Individual Sent | `daily_by_recipient_type.individual[i].sent` | `#4F46E5` / `#10b981` | solid |
| Individual Failed | `daily_by_recipient_type.individual[i].failed` | `#ef4444` | solid |
| Group Sent | `daily_by_recipient_type.group[i].sent` | `#7C3AED` | dashed |
| Group Failed | `daily_by_recipient_type.group[i].failed` | `#F97316` | dashed |

Solid vs. dashed encodes recipient type; color encodes outcome (blue/green-family = sent, red/orange-family = failed) — a two-dimensional encoding so meaning doesn't depend on color alone (per your explicit requirement), reinforced by an explicit text legend under each chart naming all four series by their exact required labels. When `daily_by_recipient_type` is unavailable or the viewer isn't entitled to the module, both charts fall back to **exactly** the original two-series `Sent`/`Failed` view — zero visual change for that case. The toggle buttons were removed since showing all four series at once made per-view switching unnecessary and was the source of the ambiguity.

---

## 6. How individual counts are calculated

Unchanged — still `AnalyticsController::dailyRecipientTypeSeries()`'s `individual` branch, which counts `recipient_type = 'individual'` rows by `status` (one row = one send), exactly as fixed in the P0 pass. Not touched in this task.

---

## 7. How group counts are calculated

Unchanged — still `dailyRecipientTypeSeries()`'s `group` branch, which sums `success_count`/`failure_count` across `recipient_type = 'group'` rows (one row = one batch of many recipients). Not touched in this task. The one field I *did* change, `active_contact_groups`, is a group **count** (number of `ContactGroup` records), not a message count, and uses `ContactGroup::count()` — an unrelated, simple row-count query that was never part of the message-recipient-counting logic the P0 fix addressed.

---

## 8. Confirmation — group counts use success_count/failure_count

Confirmed. Every group message figure shown anywhere in this fix (`recipient_breakdown.group.sent/failed`, `today_breakdown.today_group_sent/failed`, and the four-series chart's `Group Sent`/`Group Failed` lines) is a direct pass-through of `dailyRecipientTypeSeries()`/`recipientTypeBreakdown()`'s pre-existing `SUM(COALESCE(success_count,0))`/`SUM(COALESCE(failure_count,0))` output. No row-count-based group calculation was introduced anywhere in this task.

---

## 9. Confirmation — existing recipient-aware backend logic preserved

Confirmed by diff. The only backend edit in this task is the `active_contact_groups` ternary inside `summary()`'s return array. `resolveRecipientAwareTotals()`, `recipientTypeBreakdown()`, `dailyRecipientTypeSeries()`, and every P0-fix line inside `summary()`/`charts()`/`computeGlobalSummary()` are **byte-for-byte unchanged** — verified by diffing against the file as it stood immediately after the P0 fix and confirming zero matches for those method/variable names in the diff output.

---

## 10. Super Admin verification

`SuperAdminDashboard` now fetches both `getGlobalSummary()` (unchanged, for the existing financial/messaging cards) and `getSummary()` (new call, no `?account_id=` — resolves to the same global scope). The new "Group Messaging" section (5 cards: Total Groups Added, Total Group Messages, Total Failed Group Messages, Today's Group Messages, Today's Failed Group Messages) reads from the second call's `active_contact_groups`/`recipient_breakdown`/`today_breakdown` fields, gated behind `hasModule('contact_groups')` (always true for Super Admin, kept for consistency with the rest of this codebase's gating convention). `DailyPulseChart`'s four-series view also renders on this dashboard, since it's the same shared component `TenantDashboard` uses.

---

## 11. Normal Client/Agent dashboard verification

`TenantDashboard`'s own pre-existing group KPI cards (`Group Messages`, `Active Contact Groups`, `Today's Group Messages`) were **not modified** — they already read `recipient_breakdown`/`today_breakdown`/`active_contact_groups` correctly and continue to do so unchanged (the `active_contact_groups` widening only affects the `$account === null` branch, never the per-tenant branch these cards use). `DailyPulseChart` is shared between `TenantDashboard` and `SuperAdminDashboard`, so a Client/Agent with the `contact_groups` module now also sees the clearer four-series chart instead of the old toggle; one without the module sees the identical two-series chart as before.

---

## 12. Analytics page verification

`ChartsSection`'s "Daily Message Volume" chart now shows the same four clearly-labeled series under the same conditions (data present + module entitled), falling back to the original two-series view otherwise. The adjacent "Engine Usage" pie chart and every other section of this page (`SummarySection`, `LogsSection`) were not touched.

---

## 13. Module-gating verification

Both charts and the new Super Admin card section check `hasModule('contact_groups')` before rendering any group-derived series or card — the same convention `TenantDashboard`'s existing group cards already use. No module check was removed or weakened anywhere; the chart gate is, if anything, a net *addition* of a check that wasn't there before (the old toggle only checked for data presence, not entitlement) — disclosed as a deliberate, in-scope improvement, not a new RBAC feature.

---

## 14. Tenant-isolation verification

The one backend change only alters behavior for the `$account === null` branch of `summary()`, which — per `ResolvesTenantAccount::resolveAccount()` and `TenantIsolationMiddleware` (unchanged, not touched) — is reachable only by a genuine Super Admin call with no account selected; an Agent or Client caller always has a resolvable `account_id` and is unaffected by this change. No `account_id`/`agent_id` scoping logic was added, removed, or altered anywhere in this diff.

---

## 15. Database/schema check

No schema change was needed or made. `contact_groups.account_id`, `message_dispatch_logs.recipient_type/success_count/failure_count` — all columns this fix reads — already existed before this task (and before the P0 fix). No migration file was created.

---

## 16. Migration required?

**NO MIGRATION REQUIRED.**

---

## 17. N/A

No migration was created (see #16).

---

## 18. Tests actually executed and results

- **TypeScript compile check** — `npx tsc --noEmit -p tsconfig.json` (frontend-app): **executed, exit code 0, zero errors.** This is a real, complete compile of the entire frontend against its actual `tsconfig.json`, including every file this task touched.
- **Lint** — `npm run lint` (oxlint, frontend-app): **executed, exit code 0** — "Found 60 warnings and 0 errors" across 130 files. All 60 warnings are the same pre-existing `react(set-state-in-effect)` style warning already present throughout this codebase (e.g. `ChatbotPage.tsx`, `AuditLogsPage.tsx`) for the exact same `useEffect(() => { void load(); }, [load]); ` pattern used everywhere in this file already; the one new instance from `loadGroupSummary`'s effect is the same pre-existing pattern, not a new category of issue. Zero lint errors.
- **PHP syntax check** — `php -l` on the changed PHP file: **not executed.** `php` is not reachable from this sandboxed shell (it's a separate Linux VM from the actual Windows/XAMPP host where this project's PHP runs, and `php.exe` under `C:\xampp\php` is outside the connected folder). This is the same disclosed limitation as the P0 fix report. As a substitute: bracket/brace/paren balance check (`{`/`}` 50/50 both before and after; the widened block adds no braces/parens of its own beyond the ternary, confirmed by direct count) plus a full manual proofread of the changed block.
- **PHP test suite** (`php artisan test`): not executed, for the same reason; also, as noted in the P0 report, this repository's only PHP tests are stock example files with no real assertions relevant to this code path.
- **Manual trace of Cases B/C/D** (Individual only / Group only / Mixed): the frontend mapping (`recipientData`) is a pure 1:1 field rename with no arithmetic — `individual.sent → 'Individual Sent'`, etc. — so it inherits whatever correctness the already-verified P0-fixed backend produces; traced by hand and confirmed no transformation could introduce an error (there is none to introduce).
- **Case E/F** (single day and 7-day mapping): confirmed by code inspection that `daily_by_recipient_type.individual` and `.group` are both gap-filled over the identical date range by the same backend call, so indexing them together (`byRecipientType.individual.map((day, index) => ... byRecipientType.group[index] ...)`) is safe and correctly aligned per calendar day.

**Disclosed gap, same as the P0 report**: I could not execute this against a live database or the project's real PHP runtime from this shell. I'd recommend running `php -l` yourself on `AnalyticsController.php`, and visually confirming in the running app that the Super Admin Dashboard's new "Group Messaging" section and the four-series Message Pulse/Analytics charts render with real data.

---

## 19. Git diff summary

```
 backend-api/app/Http/Controllers/Api/AnalyticsController.php | 329 ++++++++++++++++-----
 frontend-app/src/pages/DashboardPage.tsx                     | 197 +++++++++---
 frontend-app/src/pages/analytics/AnalyticsPage.tsx            |  76 +++--
 frontend-app/src/types/analytics.ts                            |   7 +-
 4 files changed, 463 insertions(+), 146 deletions(-)
```

(The `AnalyticsController.php` and `DashboardPage.tsx` line counts include the P0 fix's earlier, already-reported diff, since `git diff` compares against the last commit, not against the P0 fix's own checkpoint — this session's diff *on top of* the P0 fix is the 21-line `active_contact_groups` change described in §2, confirmed isolated by diffing directly against the post-P0-fix file.)

`git status`/`git diff` both ran successfully throughout. One environmental note, unrelated to correctness: `git status` left behind a `.git/index.lock` file that this session's connected-folder permissions don't allow deleting (deletion is disabled here by default until you grant it). `git status`/`git diff` still ran fine despite it, but if you run `git commit` yourself and it complains about `index.lock` already existing, that lock is pre-existing from this session's own read-only `git status` calls, not a sign of a bad state — safe to delete it yourself.

---

## 20. Remaining issues — not fixed (per your instruction, reported not implemented)

- **"Total Groups Added" is a current count, not a cumulative "added" counter.** `ContactGroup::count()` (and its per-tenant equivalent) counts groups that exist right now — a deleted group would no longer be counted. If you want a true lifetime "added" counter, that would need a new column/event (e.g. incrementing a counter on creation, unaffected by later deletion), which is a schema-level decision outside this task's scope.
- **"Total Group Messages" on the Super Admin card is period-scoped (last 30 days by default), not all-time**, unlike its sibling "Messages Sent" card (which is a true lifetime figure from `computeGlobalSummary()`). This is disclosed in the card's own sub-text ("last 30 days") rather than hidden, but the two cards sitting side-by-side with different time scopes could still read as inconsistent at a glance. Making it a true all-time figure would require either a new all-time query in `computeGlobalSummary()` or accepting `summary()`'s existing period default — a product decision, not something I decided unilaterally to change.
- Everything already flagged in the earlier audit report (`AUDIT_ROLE_TEMPLATE_ANALYTICS_GROUP_2026_09_16.md`) that this task explicitly excluded — the `RoleController` cross-tenant escalation, the missing `module.guard:contact_groups` on the two analytics *routes* themselves (as opposed to the frontend-side gating this task added), the Agent-wise/Super-Admin-wise rollup gap, and the unclear `whatsapp.*` granular-permission enforcement — remains exactly as documented, untouched.
