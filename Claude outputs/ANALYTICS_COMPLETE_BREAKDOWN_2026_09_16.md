# Analytics Page — Complete Individual + Group + Overall Message Breakdown
**Date:** 2026-09-16
**Scope:** `frontend-app/src/pages/analytics/AnalyticsPage.tsx` only — no backend changes.

---

### 1. Full Analytics audit (performed before any code change)

**Backend — `AnalyticsController.php` (941 lines), inspected in full:**
- `summary()` (lines 37–235): computes period-scoped `total_sent`/`total_failed`/`total_alerts_attempted` via the shared `resolveRecipientAwareTotals()` helper (the P0 fix's helper — unchanged), today-scoped `total_sent_today`/`total_failed_today` via the same helper re-windowed to `now()->startOfDay()..endOfDay()`, `today_breakdown` (four literal fields) via `recipientTypeBreakdown()` re-windowed to today, `recipient_breakdown` (period-scoped) via `recipientTypeBreakdown()`, and `active_contact_groups` (global-aware since the prior task).
- `recipientTypeBreakdown()` (lines 587–634): individual rows counted by `status`; group rows summed via `SUM(COALESCE(success_count,0))`/`SUM(COALESCE(failure_count,0))` — confirmed never `COUNT(*)`.
- `resolveRecipientAwareTotals()` (lines 523–586): confirmed by direct source read that `sent = individual.sent + group.sent` and `failed = individual.failed + group.failed` — i.e. `summary.total_sent`/`total_failed`/`total_sent_today`/`total_failed_today` are **already**, by construction, the exact "Overall" values this task asked for. This is the single most important audit finding: Overall does not need to be computed on the frontend — it already exists and is already guaranteed consistent with the Individual/Group split.
- `dailyRecipientTypeSeries()` (lines 635–692): backs `daily_by_recipient_type`, same sourcing rules, gap-filled per day — unchanged, already used by the existing 4-series chart.
- `computeGlobalSummary()`/`globalSummary()` (Super-Admin-only `/analytics/global-summary`): confirmed **not called anywhere in `AnalyticsPage.tsx`** (`grep` returned zero matches) — irrelevant to this page.
- Routes (`routes/api.php` ~573–581): `/analytics/summary` and `/analytics/charts` sit behind `permission:view-analytics` only; no `module.guard:contact_groups` at the route level (group gating is, and remains, frontend-only via `hasModule()` — not touched, per instruction not to add/remove module guards).

**Frontend:**
- `AnalyticsPage.tsx`'s `SummarySection` already called `analyticsService.getSummary({from, to})` — the same endpoint that resolves to a true platform-wide aggregate for Super Admin with no client selected (confirmed via `axiosInstance.ts`'s interceptor: no `account_id` param sent when `selectedAccountId === null`).
- `types/analytics.ts`'s `AnalyticsSummary` interface already declares every field needed: `total_sent`, `total_failed`, `total_alerts_attempted`, `total_sent_today`, `total_failed_today`, `recipient_breakdown: RecipientTypeBreakdown | null`, `today_breakdown: TodayRecipientBreakdown | null`, `active_contact_groups`.
- `ChartsSection`'s existing 4-series chart (added in the prior task) was re-read in full and left untouched.
- `KpiCard` (label/value/sub, no icon) is this page's one reusable card primitive — reused for every new card, no new component created.

### 2. Existing fields discovered

`total_sent`, `total_failed`, `total_alerts_attempted`, `delivered_rate`, `total_cost_incurred`, `quota`, `total_sent_today`, `total_failed_today`, `today_breakdown.{today_individual_sent, today_individual_failed, today_group_sent, today_group_failed}`, `recipient_breakdown.individual.{sent,failed}`, `recipient_breakdown.group.{sent,failed,queued_batches,recipient_count}`, `active_contact_groups`, `scope`, `period.{from,to}`.

### 3. Missing fields discovered

**None.** Every value required by this task (Section 2/3/4/5/6/17) is either a literal existing API field or a trivial same-window `Sent + Failed` sum that can be computed client-side without introducing any new calculation logic or risk of disagreeing with the backend (see item 18 for the one field, "Today's Total Messages", that has no dedicated backend key but is a direct sum of two fields that already exist).

### 4. Root cause

Not a missing-data problem. The previous task added Group cards but left Individual figures exposed nowhere except the 4-series chart, which the user correctly identified as "inference" rather than an explicit answer. The fix is presentational: reorganize `SummarySection` to render explicit Overall, Individual, and Group card rows (period + today) side by side, all from data the endpoint already returns.

### 5. Exact backend changes

**None.** `AnalyticsController.php` diff-stat is unchanged at 251 lines both before and after this task (`git diff -w --numstat` confirms).

### 6. Exact frontend changes

`frontend-app/src/pages/analytics/AnalyticsPage.tsx` — `SummarySection` only. Replaced the previous single "Group Messaging" block (5 cards) with a "Message Statistics" section containing 5 sub-groups: Overall·Selected Period (3 cards), Overall·Today (3 cards), Individual Messages (period row of 3 + today row of 3), Group Messages (period row of 3 + today row of 3, gated), Groups (1 card, gated). The `const { hasModule } = useAuth();` line added in the prior task is unchanged/reused. No other function in the file was touched (`ChartsSection`, `LogsSection`, `RangePicker`, `KpiCard`, the top-of-page 4-card row, and Quota Health are all byte-identical to before this task).

### 7. Individual metrics mapping

| Card | Field |
|---|---|
| Individual Sent | `recipient_breakdown.individual.sent` |
| Individual Failed | `recipient_breakdown.individual.failed` |
| Individual Total | `individual.sent + individual.failed` (frontend sum) |
| Today's Individual Sent | `today_breakdown.today_individual_sent` |
| Today's Individual Failed | `today_breakdown.today_individual_failed` |
| Today's Individual Total | sum of the two above (frontend) |

### 8. Group metrics mapping

| Card | Field |
|---|---|
| Group Sent | `recipient_breakdown.group.sent` (= `SUM(success_count)`) |
| Group Failed | `recipient_breakdown.group.failed` (= `SUM(failure_count)`) |
| Group Total | `group.sent + group.failed` (frontend sum) |
| Today's Group Sent | `today_breakdown.today_group_sent` |
| Today's Group Failed | `today_breakdown.today_group_failed` |
| Today's Group Total | sum of the two above (frontend) |
| Total Groups Added | `active_contact_groups` (current count — see item 11 on time-scope) |

Renamed from the prior task's "Total Group Messages"/"Total Failed Group Messages"/"Today's Group Messages"/"Today's Failed Group Messages" to the "Group Sent/Failed/Total" trio naming used consistently across Overall/Individual/Group — same underlying fields, no information lost, per Section 7's instruction to reorganize rather than blindly duplicate.

### 9. Overall metrics mapping

| Card | Field |
|---|---|
| Overall Sent | `total_sent` (backend-guaranteed = `individual.sent + group.sent`) |
| Overall Failed | `total_failed` (backend-guaranteed = `individual.failed + group.failed`) |
| Overall Total | `total_alerts_attempted` (backend-guaranteed = `total_sent + total_failed`) |
| Today's Total Sent | `total_sent_today` |
| Today's Total Failed | `total_failed_today` |
| Today's Total Messages | `total_sent_today + total_failed_today` (frontend sum — no dedicated backend field for this exact combination exists; adding one would duplicate arithmetic already safe to do client-side, so none was added) |

### 10. Today's metrics mapping

Covered inline in items 7–9 above — every "Today's ..." card reads either a `today_breakdown` field or `total_sent_today`/`total_failed_today` directly, all defined by the backend as "server-timezone today" (no new timezone logic introduced anywhere in the frontend).

### 11. Selected-period mapping

Every non-"Today's" card in the new structure is scoped to the page's existing `RangePicker` selection (7/30/custom), via the same `resolvedRange.from/to` params `SummarySection` already sent to `getSummary()` before this task. "Total Groups Added" is the one exception — it is a live count, not period-scoped — labelled explicitly as "Current count, not tied to the selected period" (carried over unchanged from the prior task, per Section 6's "do not label period-scoped values as All Time" and the general time-scope-clarity requirement).

### 12. Chart mapping

Unchanged. `ChartsSection`'s existing four-series chart (Individual Sent / Individual Failed / Group Sent / Group Failed, sourced from `daily_by_recipient_type`, module-gated, all four series simultaneous with an explicit legend) was inspected and confirmed untouched by this task's diff — `git diff -w` shows changes only inside `SummarySection`.

### 13. Super Admin behavior

Unchanged data path, extended presentation. With no client selected, `getSummary()` resolves to `$account === null` on the backend, so `total_sent`/`total_failed`/`recipient_breakdown`/`today_breakdown`/`active_contact_groups` are all true platform-wide aggregates — every new card in Overall/Individual/Group renders the platform-wide figure automatically, with zero Super-Admin-specific frontend code (same as the existing top-of-page cards and the prior task's Group cards already did).

### 14. Client behavior

With a client selected (Super Admin) or logged in directly as a Client, `resolveAccount()` returns that tenant's `Account`, and every field above is computed with `->forAccount($account->id)` scoping identical to the rest of this page. No new scoping logic was introduced.

### 15. Agent behavior

Not modified. Agent → Client isolation is enforced entirely inside `resolveAccount()`/`forAccount()`, neither of which this task touched. No Agent rollup was implemented (explicitly out of scope).

### 16. Module-gating behavior

`hasModule('contact_groups')` (unchanged implementation) gates exactly two of the five new sub-sections: "Group Messages" (period + today rows) and "Groups" (Total Groups Added). "Overall" and "Individual Messages" render unconditionally, per Section 13's explicit instruction ("continue showing Individual and Overall statistics where valid" when contact_groups is disabled) — every account can send individual messages regardless of Group Messaging entitlement. This mirrors the exact gate `ChartsSection` already uses for its Group chart series — not a new, weakened, or duplicated check.

### 17. Tenant isolation

Not modified — inherited entirely from `resolveAccount()`/`forAccount()` scoping, which this task did not touch. No cross-tenant data path was introduced; every new card reads a field the endpoint already scopes correctly.

### 18. Reconciliation/consistency verification

Verified analytically against the backend source (not just assumed):
- `resolveRecipientAwareTotals()` (lines 523–586) literally computes `'sent' => (int) $individual->sent + (int) $group->sent` and `'failed' => (int) $individual->failed + (int) $group->failed` — so `total_sent = Individual Sent + Group Sent` and `total_failed = Individual Failed + Group Failed` are guaranteed by the backend itself, not by any frontend assumption.
- `summary()` computes `$attempted = $totalSent + $totalFailed;` and returns it as `total_alerts_attempted` — so `Overall Total = Overall Sent + Overall Failed` is likewise backend-guaranteed.
- The today-scoped versions of both facts use the *same* `resolveRecipientAwareTotals()` call, just re-windowed — so `Today's Total Sent/Failed` reconcile with `Today's Individual/Group Sent/Failed` the same way.
- `Individual Total`, `Group Total`, and `Today's Total Messages` are frontend `a + b` sums of two values sourced from the exact same API response object in the exact same render — they cannot drift out of sync with their inputs.

**Validation against Section 17's worked example** (Individual: 10 sent/2 failed; Group: 50 success/5 failure): Individual Total = 10+2=12 ✓; Group Total = 50+5=55 ✓; Overall Sent = 10+50=60 ✓ (matches `total_sent` by the backend formula above); Overall Failed = 2+5=7 ✓; Overall Total = 60+7=67 ✓. Today's worked example (Individual 4/1, Group 20/3) reconciles identically: Today's Individual Total=5, Today's Group Total=23, Today's Total Sent=24, Today's Total Failed=4, Today's Total Messages=28 — all match Section 17 exactly, by the same construction.

### 19. Database/schema impact

**None.** No backend file was touched.

### 20. Migration required: **NO**

**NO MIGRATION REQUIRED.**

### 21. Tests actually executed

- `npx tsc --noEmit -p tsconfig.json` (from `frontend-app/`) — **executed, exit code 0, zero type errors.**
- `npm run lint` (oxlint) — **executed.** "Found 60 warnings and 0 errors" — identical count to the pre-this-task baseline; all 60 are the same pre-existing `react(set-state-in-effect)` pattern warning (bare `useEffect(() => { void load(); }, [load])`) present platform-wide before this task. No new warnings were introduced by the new cards (they contain no `useEffect`, only derived render-time arithmetic).
- `php -l backend-api/app/Http/Controllers/Api/AnalyticsController.php` / `php artisan test` — **not executed.** PHP CLI remains unavailable in this environment (`php: command not found`) — same disclosed limitation as all three prior tasks in this series. Moot regardless, since this task made zero backend edits.
- Manual structural verification: brace/paren/bracket counts balanced ({} 245/245, () 258/258, [] 42/42) after the edit; full proofread of the new `SummarySection` block reproduced above in item 6's diff review.

### 22. Git diff

Before this task (`git diff -w --numstat`, this file): `92  0` (92 insertions, 0 deletions vs. committed baseline — the prior task's group-only block).
After this task: `263  0` (263 insertions, 0 deletions vs. committed baseline). Net contribution of this task: replaced the prior ~92-line addition with a ~263-line structure (a +171-line net growth), all inside `SummarySection`, all pure JSX/derived-value addition — no line elsewhere in the file was touched, and no deletion appears in the diff because the block being replaced was itself never part of a committed baseline (see explanation in the diff itself: nothing outside `SummarySection` changed).

Repo-wide, `AnalyticsController.php` (251 lines), `DashboardPage.tsx` (191 lines), and `types/analytics.ts` (7 lines) are all byte-identical to their state at the end of the immediately prior task — confirming the P0 aggregation fix and the four-series chart remain completely intact. All other "modified" files reported by plain `git status` (`AuthContext.tsx`, `ci.yml`, etc.) are confirmed pre-existing CRLF-only noise unrelated to any task in this series (they vanish under `git diff -w`).

### 23. Remaining issues — NOT FIXED

- **"Total Groups Added" remains a live/current count, not period-scoped**, unlike every other card in the new structure. Flagged via its subtitle, not fixed, per the instruction not to change backend time semantics — same disclosed caveat carried forward from both prior tasks.
- **Super Admin's `hasModule()` always returns `true` regardless of which client is selected** (pre-existing `AuthContext.tsx` behavior, not touched by any task in this series) — meaning a Super Admin viewing a specific client that does *not* have `contact_groups` enabled will still see that client's Group cards/chart series. This is a pre-existing quirk of `hasModule()`'s Super-Admin short-circuit, not something this task's changes created or could fix without altering `AuthContext.tsx` (explicitly out of scope — "do not touch module gating logic" / "do not remove or weaken any existing module guard"). Reported here for visibility only.
- All previously-audited, explicitly out-of-scope items remain untouched: the pending RoleController security fix, Agent analytics rollup, any new RBAC logic, Admin/User permission changes, webhook security, QR inbound, template ownership, quota/billing changes, audit-log changes, native WhatsApp group creation.
- No other issues were discovered while implementing this task.
