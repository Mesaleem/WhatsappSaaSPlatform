# P0 Fix Report — Group Message Count & Analytics Graph Undercount (2026-09-16)

**Scope executed:** exactly P0 Fix #1 from the prior audit. No other fix (role escalation, module-gating, Agent rollup, Admin/User permissions, webhooks) was touched, per your explicit instruction. No database migration, no schema change, no frontend file was modified.

---

## 1. ROOT CAUSE

**File**: `backend-api/app/Http/Controllers/Api/AnalyticsController.php`

**Methods**: `summary()`, `charts()`, `computeGlobalSummary()`

**Exact aggregation causing the undercount** (four call sites, all using the identical pattern before this fix):

```sql
SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent,
SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed
```

run directly against `message_dispatch_logs` with no `recipient_type` branching.

**Why this undercounts group batches**: a group send writes exactly **one** `message_dispatch_logs` row per batch, regardless of how many recipients it targeted — the true per-recipient outcome is stored separately in that row's `success_count`/`failure_count` columns (see `MessageDispatchLog::resolveGroupDispatch()`). The `SUM(CASE WHEN status='sent'...)` pattern counts **rows**, so a 100-recipient group batch with `status='sent'` contributes exactly `1` to `total_sent`, never `100`. Individual sends are unaffected (one row genuinely does equal one recipient there), which is why the bug is visible specifically and only on group sends, matching your report.

The four affected call sites, confirmed by direct code read before any change was made:
1. `summary()` — period-scoped `total_sent`/`total_failed` (was lines ~78–96)
2. `summary()` — today-scoped `total_sent_today`/`total_failed_today` (was lines ~137–145)
3. `charts()` — the primary `daily` series feeding the Message Pulse chart (was lines ~245–275)
4. `computeGlobalSummary()` — Super Admin's platform-wide `total_messages_sent`/`total_messages_failed` and their `_today` variants (was lines ~648–670)

The correct, recipient-aware logic **already existed** in the same file (`recipientTypeBreakdown()` and `dailyRecipientTypeSeries()`, feeding `recipient_breakdown`/`today_breakdown`/`daily_by_recipient_type`) — it was simply never wired into the four primary fields above. This was a partial fix left unfinished, not a missing feature.

---

## 2. FILES CHANGED

| File | What changed |
|---|---|
| `backend-api/app/Http/Controllers/Api/AnalyticsController.php` | Added one new private helper (`resolveRecipientAwareTotals()`); rewired the four call sites above to use it; restructured `charts()`'s daily-series computation to derive the primary `daily` field from the already-correct `dailyRecipientTypeSeries()` output instead of a second row-counting query; corrected one doc-comment left stale by that restructure. **236 insertions, 74 deletions**, confirmed via `git diff --stat`. |

No other file was touched. `git status` was checked before and after: every other file already showing as modified in this working tree (`ci.yml`, several frontend files, `qr-engine-service/src/services/backendClient.js`) is pre-existing, whitespace-only (CRLF) noise unrelated to this session — confirmed with `git diff -w --stat`, which returns empty for all of them. This is the same recurring, previously-documented `.gitattributes` gap from earlier analyses, not something this fix introduced.

---

## 3. EXACT LOGIC CHANGED — OLD vs NEW

**Old behavior** (all four call sites): one flat aggregate over every row in range, blind to `recipient_type`. Group batches counted as 1 row each.

**New behavior**: a new private helper, `resolveRecipientAwareTotals($baseQuery, ?Carbon $from, ?Carbon $to, bool $recipientTypeAvailable, bool $resolutionCountsAvailable): array`, added once and called from all four sites (plus a fifth, `charts()`'s daily series, handled by reuse rather than a duplicate call — see below):

- Takes the **same already-scoped** query builder each call site was already building (`MessageDispatchLog::forAccount($account->id)` or the unscoped `::query()` for global) — tenant/account scoping is passed in unchanged, never touched by the helper itself.
- Individual rows (`recipient_type = 'individual'`): counted by `status`, byte-identical to the old logic — **no behavior change** for individual sends.
- Group rows (`recipient_type = 'group'`): summed via `SUM(COALESCE(success_count, 0))` / `SUM(COALESCE(failure_count, 0))` instead of counting rows — the same formula `recipientTypeBreakdown()` already used for `recipient_breakdown`, so the two can never numerically disagree.
- Falls back to the old row-counting behavior entirely when `$recipientTypeAvailable` is false (schema not yet migrated) — self-healing, matching every other guard already present in this file.
- `$from`/`$to` are optional together: `null, null` gives an all-time aggregate (needed for `computeGlobalSummary()`'s platform-wide total, which has no period of its own); a real `Carbon` pair scopes by date exactly like every other query in this file.

For `charts()`'s primary `daily` series specifically: rather than adding a second SQL query, the fix moved the **already-existing** `dailyRecipientTypeSeries()` call earlier in the method and derives `daily[day]` by summing that call's `individual[day]` + `group[day]` figures. This means the primary chart series and `daily_by_recipient_type` are now **structurally guaranteed** to agree — they come from the same query result, not two independent calculations that merely happen to use the same formula.

---

## 4. GROUP COUNT EXAMPLE

One group batch, 100 recipients, all successful (`success_count=100`, `failure_count=0`, `status='sent'`, `recipient_type='group'`):

- **Old result**: `total_sent` contribution = **1** (one row, `status='sent'`)
- **New result**: `total_sent` contribution = **100** (`SUM(COALESCE(success_count,0))`)

Same batch with 40 successful / 10 failed (`success_count=40`, `failure_count=10`):
- **Old**: `total_sent` = 1, `total_failed` = 0 (a `status='failed'` batch would instead contribute 0/1 — either way, never 40/10)
- **New**: `total_sent` = 40, `total_failed` = 10

---

## 5. INDIVIDUAL MESSAGE BEHAVIOR

Unchanged and confirmed correct. The individual branch of `resolveRecipientAwareTotals()` is the exact same `SUM(CASE WHEN status = 'sent'/'failed' THEN 1 ELSE 0 END)` pattern the file always used, scoped to `recipient_type = 'individual'`. Ten successful individual sends still produce `total_sent = 10`, traced by hand against the new code.

---

## 6. SUMMARY KPI VERIFICATION

`total_sent`, `total_failed`, `total_sent_today`, `total_failed_today` (in `summary()`) are now all recipient-aware — each is computed via `resolveRecipientAwareTotals()`, which sums individual (by status) + group (by `success_count`/`failure_count`). Field names in the JSON response are **unchanged**, so no frontend change is required (confirmed — see §9).

---

## 7. CHART VERIFICATION

Confirmed recipient-aware. The primary `daily` series in `charts()` is now derived from the same per-day individual/group figures that power `daily_by_recipient_type`, summed per date. A 200-recipient day across three group batches now shows `sent: 200` on that date in the primary chart, not `sent: 3`.

---

## 8. GLOBAL SUMMARY VERIFICATION

Confirmed fixed. `computeGlobalSummary()` previously had no `recipientTypeAvailable`/`resolutionCountsAvailable` guards at all — these were added (mirroring `summary()`/`charts()`'s existing pattern), and both `total_messages_sent`/`total_messages_failed` (all-time) and `total_messages_sent_today`/`total_messages_failed_today` now route through `resolveRecipientAwareTotals()`. This means the Super Admin platform-wide dashboard was undercounting group sends across **every tenant**, not just per-account — now fixed at the same root.

---

## 9. EXISTING BREAKDOWNS

Confirmed intact and untouched. `recipientTypeBreakdown()` and `dailyRecipientTypeSeries()` — the methods feeding `recipient_breakdown`, `today_breakdown`, and `daily_by_recipient_type` — were **not edited** (per your explicit instruction not to touch already-correct logic unless absolutely required). The new helper is a sibling, not a modification of either method. `git diff` confirms zero changed lines inside either method's body.

**Frontend verification** (per your instruction to prove, not assume, whether frontend changes are needed): grepped `DashboardPage.tsx` and `AnalyticsPage.tsx` directly. Both consume `summary?.total_sent`, `summary?.total_failed`, `summary?.total_sent_today`, `summary?.total_failed_today`, and `charts?.daily` by interpolating the values straight into template strings/chart data — no client-side transformation logic exists that could itself be wrong. Field names are unchanged by this fix, so **no frontend file needed to change, and none was changed.**

---

## 10. TENANT ISOLATION

Confirmed unchanged. Every call site passes `resolveRecipientAwareTotals()` the **same already-scoped** query builder it was already constructing before this fix (`$account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query()` for tenant-scoped calls; the unscoped `MessageDispatchLog::query()` only in `computeGlobalSummary()`, which was already unscoped and is gated by `abort_unless(is_super_admin)` before any query runs, unchanged). The new helper only clones that builder and adds `whereBetween`/`where('recipient_type', ...)` — it never removes, replaces, or bypasses the account scope it's handed. No `account_id`/`agent_id` logic was added, removed, or altered anywhere in this diff.

---

## 11. DATABASE

Confirmed: no migration created, no schema change made, no database write of any kind performed. The existing `success_count`/`failure_count`/`recipient_type` columns (already present per the prior audit's schema check) were sufficient for the entire fix.

---

## 12. TEST RESULTS

**Commands run and results:**

- `which php` / `php --version` → **not available**. This device_bash shell is an isolated Linux VM with the project folder mounted; it is separate from the actual Windows/XAMPP host where this project's PHP runs, and `php.exe` (under `C:\xampp\php`) is outside the connected folder and not reachable from here. I could not run `php -l` or `php artisan test` against this codebase's real PHP runtime from this environment.
- Attempted a Python-based PHP parser (`phply`) as a substitute — it failed on `private const MAX_RANGE_DAYS = 366;` at line 25 **on the original, unmodified file too** (confirmed by testing the pre-change backup), proving `phply` is simply too old to parse this codebase's PHP 8 syntax and is not a valid check here. Discarded.
- **Bracket/brace/paren balance check** (Python, whole-file character count): `{`/`}` = 50/50, `(`/`)` = 525/525, `[`/`]` = 102/102 — balanced before and after the fix.
- **Structural check**: `grep -n "function "` confirms all 10 original methods plus the 1 new helper are present, correctly signed, in the expected order; the file still opens with `<?php` and closes with the expected `    }\n}` (method + class close).
- **Manual line-by-line proofread** of every changed region (`summary()` in full, `charts()` in full, the new `resolveRecipientAwareTotals()` helper in full, `computeGlobalSummary()` in full) was performed against the live patched file, not from memory of the pre-edit version.
- **Logical trace against all 5 scenarios from your validation section** (no live DB available to execute these against real rows, so this is a manual trace of the new code against each scenario's inputs, not an executed test):
  - *Scenario A* (10 individual, all sent): individual branch sums 10, group branch sums 0 (no group rows) → `total_sent = 10`. ✓.
  - *Scenario B* (1 group batch, 50 recipients, all success): `SUM(COALESCE(success_count,0))` = 50 → `total_sent = 50`, not 1. ✓.
  - *Scenario C* (1 group batch, 40 success / 10 failure): `total_sent = 40`, `total_failed = 10`. ✓.
  - *Scenario D* (10 individual + one batch success_count=50 + one batch failure_count=5): individual sent=10; group sent = 50+0=50, group failed = 0+5=5 → `total_sent = 60`, `total_failed = 5`. ✓ matches your expected values exactly.
  - *Scenario E* (3 group batches: 100/75/25 success): group sent = 200 via `SUM(COALESCE(success_count,0))` across all three rows in range; and because the primary daily chart is now derived directly from the same per-day recipient-type series, the chart for that date shows `sent: 200`, not `sent: 3` — this is a structural guarantee, not a coincidence, since both numbers come from one query result.
- **PHP test suite**: not run — the prior full-project analysis on file (`FULL_PROJECT_ANALYSIS_2026_09_16.md`) already documented that this repository's only PHP tests are two stock Laravel example files with no real assertions, so `php artisan test` would not have exercised this code path even if PHP had been reachable from this shell.
- **TypeScript check**: not run — no frontend file was changed (confirmed in §9), so per your own instruction ("only if frontend code was changed") this was correctly skipped.

**Disclosed gap**: I was not able to execute this code against a live database or a real PHP interpreter from this sandboxed shell. Everything above is static analysis (bracket balance, structural grep, full manual proofread) plus a hand-traced logical walkthrough of your five validation scenarios against the exact new code — not an executed test run. **I recommend you run `php -l app/Http/Controllers/Api/AnalyticsController.php` yourself** (from a shell with access to this project's actual PHP binary) as the one check I could not perform, before treating this as fully verified in the way `php -l` would confirm. If you'd like, send a group message through the running system afterward and compare the Dashboard's "Sent Today" tile against the recipient count you actually sent — that end-to-end check exercises the real database and the real PHP runtime together, which nothing in this session could do.

---

## 13. GIT DIFF SUMMARY

```
 backend-api/app/Http/Controllers/Api/AnalyticsController.php | 310 ++++++++++++++++-----
 1 file changed, 236 insertions(+), 74 deletions(-)
```

Concise summary of the diff's shape:
- `summary()`: the `total_sent`/`total_failed` `if/else` block rewritten to call the new helper (dispatch-logs branch) while leaving the `payment_alerts` fallback branch's SQL untouched; the today-aggregate block rewritten the same way; two return-array lines (`total_sent_today`, `total_failed_today`) updated to reference the new local variables instead of `(int) $todayAgg->total_sent`.
- `charts()`: the `$dailyByRecipientType` computation moved earlier in the closure; the `$daily`/`$attemptedTotal` computation replaced with an `if/else` — derive-from-`$dailyByRecipientType` when available, else the original row-counting loop unchanged; the now-duplicate later `$dailyByRecipientType` assignment removed; one stale doc-comment corrected.
- New private method `resolveRecipientAwareTotals()` inserted between `globalEngineBreakdown()` and `recipientTypeBreakdown()`.
- `computeGlobalSummary()`: added the two `Schema::hasColumn` guards (mirroring `summary()`); the `total_sent`/`total_failed` and today-aggregate blocks rewritten the same way as `summary()`; two return-array lines updated.

No changes outside this one file. Verified via `git status` and `git diff -w --stat` on the full working tree before and after.

---

## 14. REMAINING ISSUES

Two things surfaced during this fix that are **not addressed** here, per your instruction to fix only P0 #1:

1. **60-second cache staleness on deploy.** `charts()` and `globalSummary()` both cache their responses for 60 seconds under keys that don't encode "which version of the aggregation logic produced this." If either endpoint was called (and cached) in the moments just before this fix is deployed, that stale, under-counted response could still be served for up to 60 seconds after deployment. This is minor and self-resolving (the existing cache TTL was already an accepted design tradeoff in this file), but worth knowing if you test immediately after deploying and see an old number for under a minute.
2. Everything else identified in the prior audit report (`AUDIT_ROLE_TEMPLATE_ANALYTICS_GROUP_2026_09_16.md`) — the `RoleController` cross-tenant escalation, the missing `module.guard:contact_groups` on the two analytics endpoints, the Agent-wise/Super-Admin-wise rollup gap, and the unclear `whatsapp.*` granular-permission enforcement — remains exactly as documented, untouched, and not silently fixed here.
