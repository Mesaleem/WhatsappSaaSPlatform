# Phase 6 — Task 6: Lead Pipeline & Kanban Foundation

**Date:** 2026-09-22
**Status:** ✅ COMPLETE — all 20 acceptance criteria satisfied
**Regression:** 1077/1077 MariaDB · 1073 + 4 skipped SQLite
**Migration added:** none
**Frontend touched:** none

---

## 1. Scope Delivered

One read-only endpoint, `GET /api/crm/pipeline`, returning CRM leads grouped into the
four canonical lifecycle columns, with per-column pagination, database-level per-column
totals, and the existing filter vocabulary.

No schema change, no new permission, no new entitlement, no frontend.

---

## 2. Files Changed (6)

| File | Status | Purpose |
|---|---|---|
| `app/Models/CrmLead.php` | MODIFIED | `pipelineStatuses()`, `filterRules()`, `scopeFilter()`, `scopeOrderedForList()` |
| `app/Http/Controllers/Api/CrmPipelineController.php` | **NEW** | The endpoint |
| `app/Http/Controllers/Api/CrmLeadController.php` | MODIFIED | Refactored onto the shared scopes; removed then-unused `Rule` import |
| `app/Http/Controllers/Api/CrmContactController.php` | MODIFIED | Refactored onto the shared scopes |
| `routes/api.php` | MODIFIED | One route registration |
| `tests/Feature/CrmPipelineTest.php` | **NEW** | 46 tests |

No migration file created. No file deleted.

### Committed md5 (verified byte-identical on the device after commit)

```
52812b53c68406cc274acc7de1ffa3a9  app/Models/CrmLead.php
091dffaa639385dac7390fe126645c4e  app/Http/Controllers/Api/CrmPipelineController.php
bef24b8bf8b25abaa52d652c3f04fb67  app/Http/Controllers/Api/CrmLeadController.php
2e770cdcfc1136931dacb7ab4be1ea52  app/Http/Controllers/Api/CrmContactController.php
fbdd0780d4a1b3df47f41d16a3ff5bab  routes/api.php
d05e6e84f670a93f83f36005af77f50e  tests/Feature/CrmPipelineTest.php
```

---

## 3. Single Source of Truth for Statuses

`CrmLead::pipelineStatuses()` derives the column set from the existing `CrmLead::STATUSES`
constant — the same constant `ChangeCrmLeadStatusRequest`, the lifecycle invariants and
`/api/crm/leads` already read.

```
new           → "New"           order 1
contacted     → "Contacted"     order 2
converted     → "Converted"     order 3
not_converted → "Not Converted" order 4
```

Labels are derived (`ucwords(str_replace('_', ' ', $status))`), not stored. Adding or
retiring a status changes one array in one file and the pipeline follows automatically.

**[Fact]** No `pipeline_status`, `pipeline_stage`, `kanban_status`, `custom_status` or
`stage` column exists anywhere in the schema — verified against `information_schema.COLUMNS`,
which returned zero rows for those five names. `crm_leads.status` remains the only status field.

---

## 4. Endpoint Contract

```
GET /api/crm/pipeline

auth:sanctum → tenant.isolation → subscription.guard
            → module.guard:lead_crm → permission:manage-crm → capability.guard:crm
```

Identical to every other `/api/crm/*` route. **No pipeline-specific permission or
entitlement was created** — reading the pipeline is reading CRM leads.

### Parameters (all optional)

| Param | Accepts | Meaning |
|---|---|---|
| `status` | `new` \| `contacted` \| `converted` \| `not_converted` | Narrows to that one column. Absent ⇒ all four. Anything else ⇒ 422 |
| `source` | `manual` \| `whatsapp` \| `meta_ad` \| `api` \| `journey` | Existing source filter |
| `assigned_user_id` | positive integer, or the literal `none` | Owner filter; `none` = unassigned |
| `search` | string, max 255 | Substring on the contact's name or phone |
| `per_page` | integer ≥ 1 | Leads **per column**. Default 20, **hard cap 100** |
| `page` | integer ≥ 1 | Page of **each** returned column. Default 1 |

### Errors

`401` unauthenticated · `403 MODULE_DISABLED` · `403 CAPABILITY_NOT_ENTITLED` ·
`403` missing `manage-crm` · `422` invalid filter · `405` on any write verb

---

## 5. Actual Response

Captured from a live request, not hand-written:

```json
{ "data": { "pipeline": [
  { "status": "new", "label": "New", "order": 1,
    "total": 2, "page": 1, "per_page": 1, "last_page": 2, "has_more": true,
    "leads": [ { "id": 2, "status": "new", "source": "manual",
      "assigned_user_id": null, "assigned_user": null,
      "contact": { "id": 2, "name": "Miss Rebeca Ullrich",
                   "phone_number": "910899018298", "email": null },
      "capture_lead": null, "converted_at": null,
      "not_converted_at": null, "not_converted_reason": null,
      "created_at": "2026-09-22T12:16:23+00:00",
      "updated_at": "2026-09-22T12:16:23+00:00" } ] },
  { "status": "contacted", "label": "Contacted", "order": 2,
    "total": 0, "page": 1, "per_page": 1, "last_page": 1,
    "has_more": false, "leads": [] },
  { "status": "converted",     "label": "Converted",     "order": 3, "total": 1, "...": "..." },
  { "status": "not_converted", "label": "Not Converted", "order": 4, "total": 0, "...": "..." }
] } }
```

An empty column reports `total: 0` with `leads: []` — it never disappears, so a Kanban
always renders four columns.

---

## 6. Pagination Model

Pagination is **per column**, and `page` applies to every column in that request. A
frontend loading more of one column issues `?status=<that column>&page=2`, which returns
just that column in the same shape.

`per_page` is capped at 100 in the controller (`PER_PAGE_MAX`), matching the other CRM
list endpoints. Verified: `per_page=100000` is silently clamped to 100;
`per_page=0`, `per_page=abc` and `page=0` are 422.

---

## 7. Totals Are Database-Level

**[Fact]** Totals come from **one grouped `COUNT`** covering all four columns:

```sql
SELECT status, COUNT(*) FROM crm_leads WHERE account_id = ? GROUP BY status
```

Never from the returned collection, never one count per column. The test asserts both the
value (`total` stays correct as `per_page` changes) and the shape (exactly one `COUNT(*)`
query in the request's query log).

---

## 8. Query Count Does Not Scale

Per request, regardless of lead volume:
**1 grouped COUNT + 4 column selects + ≤3 eager-load selects.**

The `captureLead` eager load is skipped by Eloquent when every FK is null, which is why a
clean run shows 7 rather than 8.

Going from 2 leads to 12 changed the pipeline's query count by **zero**. Asserted two ways:
total count equality, plus `assertCount(1, …)` on both the grouped `COUNT` and the
`contacts` eager load, so a future change that keeps the count flat by accident still fails.

---

## 9. Root Cause of the One Test Failure (and its fix)

The first run failed `test_the_query_count_does_not_grow_with_the_number_of_leads`
with `9 !== 14`.

**[Fact]** The count went *down*, not up. Dumping both query logs showed the 5 extra
queries in the **first** request were Spatie's role and permission loads, the account
resolution and the current-subscription lookup — all process-level caches that warm on
the first request of a test. The pipeline's own queries were byte-identical in both
requests, with no per-lead select in either.

**Root cause:** the assertion was measuring framework warm-up, not the endpoint.
**Fix:** one throwaway request before the baseline measurement, so both samples come from
a warm process. The endpoint was not changed — it was never wrong.

---

## 10. Ordering

`scopeOrderedForList()` = `ORDER BY created_at DESC, id DESC`.

The `id DESC` tiebreak is new (the old code used `->latest()` alone) and makes a paginated
list deterministic when timestamps collide — otherwise a lead can be skipped or repeated
across pages. Tested with identical timestamps. Determinism across repeated identical
requests is asserted directly.

---

## 11. Reuse, Not Duplication

Nothing about statuses, filtering, ordering or serialization is restated in the pipeline
controller:

| Concern | Single definition |
|---|---|
| Columns | `CrmLead::pipelineStatuses()` |
| Validation | `CrmLead::filterRules()` |
| Predicates | `CrmLead::scopeFilter()` |
| Ordering | `CrmLead::scopeOrderedForList()` |
| Each card | `PresentsCrmLeads::presentCrmLead()` + `crmLeadRelations()` |

`/api/crm/leads` and `/api/crm/contacts/{id}/leads` were refactored onto the same scopes,
so the three surfaces cannot drift. `/crm/contacts/{id}/leads` deliberately keeps its
narrower rule set (status/source/per_page only) — `scopeFilter()` applies only the keys
present, so this is not a behaviour change.

Serialization parity is asserted by `assertSame` between a pipeline card and the same lead
from both `GET /crm/leads/{id}` and `GET /crm/leads`.

---

## 12. Read-Only, Deliberately

The endpoint never writes. Moving a lead between columns is a lifecycle change, and
`PATCH /api/crm/leads/{id}/status` remains the single mutation API — it owns the
transition matrix, the terminal-outcome bookkeeping, the no-op guard and the audit trail
from Task 5. A second status writer here would be a second copy of those rules.

No `$lead->update(['status' => …])` exists in the pipeline controller. Asserted three ways:

1. No `updated` model event fires during a pipeline read.
2. The lead and contact rows are byte-identical before and after.
3. `POST` / `PATCH` / `PUT` / `DELETE` on `/api/crm/pipeline` all return **405**.

---

## 13. Tenant Isolation

Every query is `forAccount($account->id)` from `requireAccount()`, which reads the
authenticated context only. Tested:

- Foreign leads never appear **and never inflate a column's total**
- `search` cannot reach another account's contact
- A `status` filter cannot expose a foreign lead
- A foreign `assigned_user_id` leaks nothing
- `account_id` / `agent_id` / `tenant_id` in the query string are ignored

---

## 14. Authorization

Tested: 401 unauthenticated; 403 `CAPABILITY_NOT_ENTITLED` (with `data` null);
403 `MODULE_DISABLED`; 403 without `manage-crm`.

Revoking the `crm` capability denies the pipeline while leaving lead rows untouched, and
re-enabling restores access. Entitlement reconciliation does not modify lead status.
A plan can carry and drop `crm`.

---

## 15. Developer API Unaffected

**[Fact]** `php artisan route:list | grep "v1.*pipeline"` → no match.
A test asserts `/api/v1/crm/pipeline` returns **404**.

---

## 16. Query Performance — EXPLAIN on Real MariaDB

Sandbox `wa_explain`: **50,000 contacts, 160,000 crm_leads, 5 tenants, 30 users**, then one
tenant scaled to **120,000 leads** with a deliberately pathological skew — a rare status of
200 rows whose rows are the *oldest* in the tenant, the worst case for a `created_at`-ordered
scan. `ANALYZE TABLE` run before measuring.

| Query | Index chosen | Extra | Time |
|---|---|---|---|
| Grouped COUNT (10k tenant) | `account_id_status` | **Using index** (covering) | 2.5 ms |
| Grouped COUNT (120k tenant) | `account_id_status` | **Using index** | 25 ms |
| Dominant column, page 1 (120k) | `account_id_created_at` | Using where | 0.40 ms |
| Second column, page 1 (120k) | `account_id_created_at` | Using where | 0.23 ms |
| **Rare oldest column, page 1 (120k)** | `account_id_status` | filesort over 200 rows | **0.53 ms** |
| Deep offset, page 500 at the 100 cap (120k) | `account_id_created_at` | Using where | 88 ms |
| Column + assignee / + unassigned | `account_id_created_at` | Using where | 0.29 ms |
| Column + source | `account_id_created_at` | Using where | 0.43 ms |
| Search (`whereHas` contact) | `account_id_created_at` + `eq_ref` PRIMARY on contacts | Using where | 5.6 ms |
| Grouped COUNT + source filter | index_merge | temporary; filesort | 116 ms |

**[Fact]** The optimizer adapts by selectivity: a dense status uses `(account_id, created_at)`
and avoids a filesort entirely; a sparse status switches to `(account_id, status)` and
filesorts a tiny result. Both paths are index-supported on tenant, and every measured query
is under 120 ms at 120k leads in one tenant.

**No missing index was demonstrated, so no migration was created.** The existing
`(account_id, status)`, `(account_id, created_at)` and `(account_id, assigned_user_id)`
indexes cover all pipeline access paths. A `(account_id, status, created_at, id)` covering
index would remove the filesort on the sparse path — but that path already costs 0.53 ms,
so the index would be redundant under this task's explicit *"only create a migration if an
actual missing index is demonstrated"* constraint.

**[Inference]** The 116 ms outlier is the `source`-filtered grouped COUNT: `source` is
unindexed. That is pre-existing behaviour of the `source` filter from Task 2, unchanged by
Task 6, and 116 ms at 120k rows does not meet the bar for a new index. Flagged, not acted on.

---

## 17. Migrations

**None added.** Migration count unchanged at 12 CRM migrations; the last is
`2026_09_22_190004`. Forward → rollback (12 steps) → forward verified clean on a
throwaway MariaDB database.

---

## 18. Tests

`tests/Feature/CrmPipelineTest.php` — **46 tests, 154 assertions**, covering:

- **Structure** — four columns, order, labels, derived from `pipelineStatuses()`, no
  invented stage strings in the body, determinism, no schema column
- **Data** — a lead appears only in its own column, accurate totals, empty column reports 0,
  totals independent of page size
- **Pagination** — independent per column covering every lead without repeat or skip, cap
  enforcement, 422s, query count flat
- **Filters** — status narrowing, six invented statuses rejected via data provider, assignee,
  `none`, source, search by name, search by phone, combination
- **Ordering** — newest first, stable `id DESC` tiebreak
- **Serialization parity** — `assertSame` against show and index
- **Tenant isolation** — 5 tests
- **Authorization** — 401 / capability / module / permission
- **Plan & capability lifecycle** — revoke, preserve, re-enable, reconciliation
- **Read-only guarantees** — no events, identical rows, 405 on write verbs
- **`/api/v1` absence**

**No existing test was deleted, weakened or skipped.** The full existing CRM suite (8 files)
passes unchanged: **415 passed, 4 skipped** across all CRM suites.

---

## 19. MySQL/MariaDB Authority

| | SQLite (secondary) | **MariaDB (authoritative)** |
|---|---|---|
| Engine | SQLite (in-memory) | MariaDB **10.11.14**-0ubuntu0.24.04.1, InnoDB |
| Database | — | `wa_t6b` (fresh, throwaway) |
| Tests | 1073 passed, 4 skipped | **1077 passed** |
| Assertions | 3878 | **3887** |
| Failures | **0** | **0** |
| Skipped | 4 (SQLite-incompatible FK tests) | **0** |
| Duration | 53.4 s | 111.0 s |

Both runs were executed against the **exact bytes that were committed** — both suites were
re-run after removing the unused `Rule` import, rather than reporting numbers from before
that edit.

Baseline before Task 6 was 1031 on MariaDB. **+46**, exactly the new suite. No regression.

---

## 20. State Protection

**The real database `wa_saas_platform` was never touched.** All migrations and tests ran
only inside the container against throwaway databases: `wa_t6`, `wa_t6b`, `wa_explain`.
Nothing was deleted.

---

## 21. Frontend

**[Fact]** `frontend-app/src/pages` contains no `crm` directory. No CRM frontend exists, so
per the task constraint none was created. Zero frontend files were touched.

---

## 22. Static Verification

- `php -l` clean on all six files
- `php artisan route:list --path=crm` → `GET|HEAD api/crm/pipeline → Api\CrmPipelineController@index`
- grep for `view-crm-pipeline`, `manage-crm-pipeline`, `crm_pipeline`, `crm-pipeline` across
  `app/`, `database/`, `routes/`, `config/` → no match

---

## 23. Commit Verification

Drift check before committing: all four pre-existing files on the device diffed against the
container, and **every difference was a Task 6 edit** — nothing was overwritten.
`expectedMtimeMs` guards were passed for all four. All six files committed and md5-verified
identical on the device afterwards (hashes in §2).

---

## 24. Known Limitations

1. **[Fact]** `page` applies uniformly to all columns returned in one request. Fetching
   page 2 of only one column requires `?status=<column>&page=2`. This is the documented
   contract, not a defect, but a frontend must know it.
2. **[Fact]** The `source`-filtered grouped COUNT costs 116 ms at 120k leads because
   `source` is unindexed. Pre-existing, unchanged by this task, below the bar for a new index.
3. **[Fact]** `activity_logs` rows remain unassertable in PHPUnit (`recordActivity()` returns
   early under `runningInConsole()`). Irrelevant here — the pipeline writes nothing — but it
   is why read-only-ness is asserted via model events and row bytes rather than an absent
   audit row.
4. **[Inference]** Deep offsets degrade linearly (88 ms at offset 49,900). Bounded by the
   `per_page` cap and acceptable for a Kanban, where deep paging is not the access pattern.
   Cursor pagination would be the fix if that assumption ever breaks.
5. **[Unknown]** No load testing was performed on the HTTP layer — only database-level
   timing. Application-level throughput under concurrency is unmeasured.

---

## 25. Acceptance Criteria

| # | Criterion | Status |
|---|---|---|
| 1 | `GET /api/crm/pipeline` returns leads grouped by status | ✅ |
| 2 | Single canonical pipeline definition | ✅ `CrmLead::pipelineStatuses()` |
| 3 | Reuses `crm_leads.status` as sole source of truth | ✅ |
| 4 | No `pipeline_status` / `stage` / `kanban_status` column | ✅ verified in `information_schema` |
| 5 | Bounded per-column pagination | ✅ cap 100 |
| 6 | DB-level counts independent of page size | ✅ one grouped COUNT |
| 7 | Deterministic ordering | ✅ `created_at DESC, id DESC` |
| 8 | Reuses existing filters | ✅ shared `filterRules()` / `scopeFilter()` |
| 9 | Reuses `PresentsCrmLeads` | ✅ parity asserted by `assertSame` |
| 10 | Tenant isolation | ✅ 5 isolation tests |
| 11 | Existing gates, no new permission / entitlement | ✅ grep-verified |
| 12 | No N+1 | ✅ query count flat, asserted |
| 13 | Read-only; status mutation stays on the lifecycle endpoint | ✅ 405s + no events |
| 14 | EXPLAIN validated on MariaDB | ✅ 10 queries at 120k leads |
| 15 | No migration unless a missing index is demonstrated | ✅ none demonstrated, none created |
| 16 | No frontend | ✅ none exists, none created |
| 17 | Not added to `/api/v1` | ✅ 404 asserted |
| 18 | Comprehensive tests | ✅ 46 tests |
| 19 | No existing test weakened | ✅ 415 CRM tests unchanged |
| 20 | Full regression, MariaDB authoritative | ✅ 1077/1077 |

**20 of 20 satisfied.**

---

**TASK 7 READY: YES**
