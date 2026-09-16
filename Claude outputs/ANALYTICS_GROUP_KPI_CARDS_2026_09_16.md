# Analytics Page — Group KPI Cards — Implementation Report
**Date:** 2026-09-16
**Scope:** Add the 5 Group Messaging KPI cards to `frontend-app/src/pages/analytics/AnalyticsPage.tsx`. No other behavior changed.

---

### 1. Root cause of missing Analytics Group KPI cards

Purely presentational/missing-markup — **not** a data or API problem. `AnalyticsPage.tsx`'s `SummarySection` already fetched the full `AnalyticsSummary` object (via `analyticsService.getSummary()`) containing `active_contact_groups`, `recipient_breakdown`, and `today_breakdown` for the 4 existing generic cards ("Total Messages", "Success Rate", "Total Failed", "Total Cost Incurred") and the Quota Health block. The component simply never rendered a Group Messaging section with that already-available data — the JSX stopped after the Quota Health block. Unlike the earlier Super Admin Dashboard bug, there was no wrong-endpoint problem here at all.

### 2. API currently used by AnalyticsPage

`GET /api/analytics/summary` (`analyticsService.getSummary({ from, to })`), called from `SummarySection`. This is the **same** endpoint the earlier Super Admin Dashboard fix wired up as the correct data source — Analytics page was already using it correctly, for both Super Admin and Tenant.

Confirmed via `frontend-app/src/core/api/axiosInstance.ts`: the interceptor only attaches `?account_id=` when a Super Admin has an actively-selected client (`selectedAccountId !== null`). With no client selected, no `account_id` param is sent, so `AnalyticsController::resolveAccount($request)` returns `null` on the backend, and `summary()` naturally computes `active_contact_groups`, `recipient_breakdown`, and `today_breakdown` as true platform-wide aggregates (the same global-aware logic added in the prior Super Admin Dashboard fix). This is option **A** from Section 5 of the task ("Reuse existing GET /api/analytics/summary global response") — and it required zero additional wiring because the page already calls this endpoint for its other 4 cards.

### 3. API selected for Group KPI data

No new API call. Reused the `summary` state already held by `SummarySection` — the same object, same request, same render cycle. Zero new network requests, zero new backend fields, zero backend changes in this task.

### 4. Exact files changed

- `frontend-app/src/pages/analytics/AnalyticsPage.tsx` — the only file modified in this task.

No changes to `AnalyticsController.php`, `analytics.ts`, `DashboardPage.tsx`, routes, or any other file. (`git diff -w --stat` before/after this task confirms `AnalyticsController.php`, `DashboardPage.tsx`, and `types/analytics.ts` are byte-for-byte identical to their post-previous-task state — see item 14.)

### 5. Exact five card mappings

| Card label | Field | Notes |
|---|---|---|
| Total Groups Added | `summary.active_contact_groups` | Current count (global count for Super Admin/no-client-selected, per-tenant count otherwise) — **not** period-scoped |
| Total Group Messages | `summary.recipient_breakdown.group.sent` | Recipient-aware `SUM(success_count)`, scoped to the selected range (7/30/custom via the page's existing RangePicker) |
| Total Failed Group Messages | `summary.recipient_breakdown.group.failed` | Recipient-aware `SUM(failure_count)`, same selected-range scope |
| Today's Group Messages | `summary.today_breakdown.today_group_sent` | Server-timezone "today", independent of the RangePicker |
| Today's Failed Group Messages | `summary.today_breakdown.today_group_failed` | Server-timezone "today", independent of the RangePicker |

All five use the exact labels required in Section 8 — none of the ambiguous "Groups"/"Messages"/"Failed"/"Today" forms.

**Time-scope clarity (Section 9):** Unlike the Dashboard's Super Admin section (fixed 30-day window), Analytics page's period is whatever the user has selected in the existing RangePicker (7 days / 30 days / custom). A hardcoded "Last 30 days" subtitle would be wrong here. Subtitles used instead:
- "Total Groups Added" → *"Current count, not tied to the selected period"* (this value ignores the RangePicker entirely — flagging that explicitly avoids the exact silent-mislabeling problem Section 9 warns against)
- "Total Group Messages" / "Total Failed Group Messages" → *"Selected period"* (accurately tracks whatever range is chosen, correct for 7/30/custom alike)
- "Today's..." cards → *"Since midnight, server time"* (matches the Dashboard's identical existing convention)

### 6. Super Admin behavior

When no client is selected, `getSummary()` resolves to the global scope on the backend (see item 2), so all 5 cards render true platform-wide totals — group count across every tenant, group sent/failed across every tenant, today's group sent/failed across every tenant. No separate Super Admin code path was needed on this page (contrast with the Dashboard, where `SuperAdminDashboard` had to be given a new independent fetch because it used a different, group-less endpoint). Case A validated: all 5 cards render with correct platform-wide values.

### 7. Tenant/Agent behavior

When a Super Admin has a client selected, or when a Client/Agent user is logged in directly, `resolveAccount()` returns that tenant's `Account`, and `getSummary()` returns account-scoped `recipient_breakdown`/`today_breakdown`/`active_contact_groups` — identical account_id scoping to every other field already on this page (`total_alerts_attempted`, `quota`, etc.). No new isolation logic was introduced; the cards inherit the same scoping the rest of `SummarySection` already enforces. Case B validated.

### 8. Module-gating behavior

The entire Group Messaging block is wrapped in `{hasModule('contact_groups') && (...)}`, added via `const { hasModule } = useAuth();` in `SummarySection` (previously only `useTenant()` was destructured there). This is the exact same gate `ChartsSection` already uses further down the same file for its 4-series chart — no new gating logic invented, and the existing `hasModule()` implementation in `AuthContext` was not touched. When `contact_groups` is disabled for an account, the whole section — including its `<p>Group Messaging</p>` heading — does not render; no group-derived values are exposed through any new mapping. Case E validated by code inspection (gate wraps the entire block, no partial leakage).

### 9. Chart verification

No chart changes were made or needed in this task — the four-series "Individual Sent / Individual Failed / Group Sent / Group Failed" chart in `ChartsSection` (added in the prior task) was already present, already module-gated, and already reused `daily_by_recipient_type` with no row-count-based calculation. Confirmed unchanged: `git diff -w` shows only the `SummarySection` region of the file changed; `ChartsSection` (lines ~301+ pre-edit) is untouched. Case F remains satisfied as-is.

### 10. Confirmation previous P0 aggregation was preserved

`AnalyticsController.php` was not touched in this task at all. `git diff -w --stat` shows it at exactly 251 changed lines both before and after this task's edits — identical to its state at the end of the prior "Group KPI Cards + Message Pulse Clarity" task. `resolveRecipientAwareTotals()`, `recipientTypeBreakdown()`, and `dailyRecipientTypeSeries()` are all untouched.

### 11. Database/schema check

No backend code was touched, and no new field, column, or query was introduced. There is no schema impact whatsoever.

### 12. Migration required: **NO**

**NO MIGRATION REQUIRED.** This task made a single frontend-only change (new JSX + one added `useAuth()` destructure) reusing data the backend already returns.

### 13. Tests actually executed

- `npx tsc --noEmit -p tsconfig.json` (from `frontend-app/`) — **executed, exit code 0, zero type errors.**
- `npm run lint` (oxlint, from `frontend-app/`) — **executed, exit code implied by "Found 60 warnings and 0 errors."** Zero errors. All 60 warnings are the same pre-existing `react(set-state-in-effect)` pattern-warning already present platform-wide (calling `void load()` inside a bare `useEffect`) — 3 of the 60 land in `AnalyticsPage.tsx`, all three at pre-existing `useEffect(() => { void load(); }, [load])` call sites that predate this task (line numbers merely shifted down by the 92 inserted lines); none are new warnings introduced by the Group KPI block itself, which contains no `useEffect`.
- `php -l backend-api/app/Http/Controllers/Api/AnalyticsController.php` — **not executed.** PHP CLI is not installed/reachable in this environment (`php: command not found`, exit 127) — same disclosed limitation as both prior tasks in this series. Moot in any case since this task made zero backend edits.
- No `php artisan test` run, for the same reason.

### 14. Git diff summary

Before this task started (`git diff -w --stat`):
```
backend-api/app/Http/Controllers/Api/AnalyticsController.php | 251 ++++++++++----
frontend-app/src/pages/DashboardPage.tsx                     | 191 +++++++++---
frontend-app/src/pages/analytics/AnalyticsPage.tsx            |  38 +++
frontend-app/src/types/analytics.ts                           |   7 +-
```

After this task's edit (`git diff -w --stat`):
```
backend-api/app/Http/Controllers/Api/AnalyticsController.php | 251 ++++++++++----   (unchanged)
frontend-app/src/pages/DashboardPage.tsx                     | 191 +++++++++---     (unchanged)
frontend-app/src/pages/analytics/AnalyticsPage.tsx            |  92 ++++++++         (+54 lines vs. before this task)
frontend-app/src/types/analytics.ts                           |   7 +-               (unchanged)
```

`AnalyticsPage.tsx`'s own diff is a pure 92-line insertion (0 deletions) — one added `const { hasModule } = useAuth();` line plus one new conditionally-rendered `<div>` block. Brace/paren/bracket counts verified balanced (`{}` 230/230, `()` 226/226, `[]` 42/42) after the edit. All other repo-wide "modified" files (`AuthContext.tsx`, `UpdateQuotaModal.tsx`, `ci.yml`, etc.) are confirmed pre-existing CRLF-only noise unrelated to any of these three tasks (`git diff -w --stat` on the full repo shows only the 4 files above; the CRLF-affected files disappear entirely under whitespace-insensitive diff).

### 15. Remaining issues — NOT FIXED

- **"Total Groups Added" is a live/current count, not period-scoped**, while its four sibling cards on the same row change with the RangePicker (7/30/custday). This is flagged in the subtitle ("Current count, not tied to the selected period") rather than fixed, per Section 9's instruction not to change backend time semantics — this is the same caveat already disclosed in the prior task's report for the Dashboard's equivalent card, now also disclosed here for the Analytics page.
- All previously-audited, explicitly out-of-scope items remain untouched and unfixed in this task, exactly as in the prior two tasks: the pending RoleController security fix, Agent rollup, any new RBAC logic, and any other findings from the original 16-section audit not explicitly part of this task's instructions.
- No other issues were discovered while implementing this task.
