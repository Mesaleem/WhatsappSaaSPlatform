# Phase 6 — CRM Module: Task Log

Running index of Phase 6 work. One row per task, plus the standing findings that
later tasks depend on.

> **Note on completeness.** Tasks 1–5 and the two hardening rounds were reported in
> full at the time they were done, but no `.md` was written for them. The entries below
> are **condensed retrospective entries** reconstructed from this session's verified run
> records — each figure below was actually observed, but these are not the full reports.
> **Task 6 onward has its own full report file**, and that is the convention going forward.

---

## Task Index

| # | Task | Outcome | Migration | Suite total after | Full report |
|---|---|---|---|---|---|
| 1 | CRM Domain & Database Foundation | ✅ PASS (14/15 criteria; one unsatisfiable, reported) | 12 CRM migrations created | 693 | chat only |
| 2 | Lead Creation & Source Management | ✅ PASS | — | 752 | chat only |
| H1 | CRM Hardening Round 1 (10 issues) | ✅ PASS — Issue 7 deferred, 8 limitations recorded | — | 791 | chat only |
| H2 | CRM Hardening Round 2 (Issue 7 + all 8 limitations closed) | ✅ PASS | FK moved to `crm_leads.capture_lead_id` | 857 | chat only |
| 3 | Contact ↔ Lead Linking | ✅ PASS | none | 909 | chat only |
| 4 | Lead Assignment & Ownership | ✅ PASS | none | 960 | chat only |
| 5 | Lead Status & Lifecycle | ✅ PASS | none | 1031 | chat only |
| 6 | Lead Pipeline & Kanban Foundation | ✅ PASS (20/20 criteria) | none | 1077 | [`PHASE6_TASK6_LEAD_PIPELINE_KANBAN.md`](./PHASE6_TASK6_LEAD_PIPELINE_KANBAN.md) |
| 7 | Lead Tags & Segmentation Foundation | ✅ PASS | 3 (`crm_tags`, `crm_lead_tags`, `crm_leads` unique(id, account_id)) | **1170** | [`PHASE6_TASK7_LEAD_TAGS.md`](./PHASE6_TASK7_LEAD_TAGS.md) |
| 8 | CRM Frontend Foundation (frontend only) | ✅ PASS | none | 1170 (backend unchanged) · frontend 504 | [`PHASE6_TASK8_CRM_FRONTEND.md`](./PHASE6_TASK8_CRM_FRONTEND.md) |
| 9 | CRM Bulk Operations & Productivity | ✅ PASS | none | 1220 · frontend 523 | [`PHASE6_TASK9_CRM_BULK_OPERATIONS.md`](./PHASE6_TASK9_CRM_BULK_OPERATIONS.md) |
| 10 | Meta / Ads Lead Capture → CRM Integration | ✅ PASS | none | 1252 · frontend 523 | [`PHASE6_TASK10_META_ADS_CRM_INTEGRATION.md`](./PHASE6_TASK10_META_ADS_CRM_INTEGRATION.md) |
| 11 | CRM Entitlement, RBAC & Developer API | ✅ PASS | none | 1301 · frontend 524 | [`PHASE6_TASK11_CRM_ENTITLEMENT_RBAC_API.md`](./PHASE6_TASK11_CRM_ENTITLEMENT_RBAC_API.md) |
| 12 | CRM Analytics & Phase 6 closure audit | ✅ PASS | none | **1337** · frontend 535 | [`PHASE6_TASK12_CRM_ANALYTICS_AND_CLOSURE.md`](./PHASE6_TASK12_CRM_ANALYTICS_AND_CLOSURE.md) |
| — | **Phase 6 final closure** — CRM Notes descoped (backlog); real DB verified at 110/110 migrations; CRM suite also green on MySQL 8 | ✅ **PHASE 6 CLOSED** | none | 1337 · frontend 535 | same file, §6 |
| — | CRM UI fix: "Add lead" for Super Admin once a client is selected (disabled in global view), modal names the target client, list reloads after create; TenantContext no longer drops the stored client on reload | ✅ | none | 1350 · frontend 542 | chat only |
| — | CRM: Super Admin's own CRM account "Platform (Super Admin)" (account_type super_admin, crm granted) is the CRM target with no client selected; the target account's lead_crm + crm now apply to Super Admin in /api/crm (`crm.target`); `/auth/me` exposes `platform_crm_account` | ✅ code · ⏳ real DB needs `php artisan migrate` | 1 data migration (2026_09_23_130000) | 1362 · frontend 544 | chat only |
| — | CRM: manual vs automated lead creation verified independent (all CRM-capable roles create manually; Meta Lead Ads / CTWA / Journey keep their sources) — tests only, no code change | ✅ | none | **1370** · frontend 544 | chat only |
| — | CRM: manual lead source locked to `manual` (UI source picker removed; `StoreCrmLeadRequest` accepts only `manual`; controller forces it). Automated writers unchanged | ✅ | none | **1372** · frontend 545 | chat only |

Suite totals are the authoritative MariaDB figures. Current baseline: **1337 passed,
0 failures, 0 skipped** on MariaDB 10.11.14; **1333 passed + 4 skipped** on SQLite
(the 4 skips are foreign-key tests SQLite cannot express).

---

## Current CRM Surface

| Route | Verb | Purpose |
|---|---|---|
| `/api/crm/contacts` | GET POST | Contact list & creation |
| `/api/crm/contacts/{id}` | GET PATCH DELETE | Contact detail |
| `/api/crm/contacts/{id}/leads` | GET | A contact's leads |
| `/api/crm/leads` | GET POST | Lead list & creation; list accepts `?tag_id=` / `?tag_ids[]=` (AND) (Task 7) |
| `/api/crm/leads/{id}` | GET PATCH DELETE | Lead detail |
| `/api/crm/leads/{id}/assignee` | PATCH | **The** ownership mutation API (Task 4) |
| `/api/crm/leads/{id}/status` | PATCH | **The** lifecycle mutation API (Task 5) |
| `/api/crm/assignees` | GET | Eligible assignees |
| `/api/crm/pipeline` | GET | **Read-only** Kanban view (Task 6); `?tag_id=` / `?tag_ids[]=` (Task 7) |
| `/api/crm/tags` | GET POST | Tag list (`?search=` prefix, `lead_count`) & creation (Task 7) |
| `/api/crm/tags/{id}` | GET PUT PATCH DELETE | Tag detail / rename / delete (Task 7) |
| `/api/crm/leads/{id}/tags/{tag}` | POST DELETE | Idempotent attach / detach (Task 7) |
| `/api/crm/leads/bulk/assignee` | POST | Bulk assign / unassign, ≤100 leads, all or nothing (Task 9) |
| `/api/crm/leads/bulk/status` | POST | Bulk status, ≤100 leads, all or nothing (Task 9) |
| `/api/crm/leads/bulk/tags/attach` | POST | Bulk tag attach, idempotent (Task 9) |
| `/api/crm/leads/bulk/tags/detach` | POST | Bulk tag detach, idempotent (Task 9) |

All carry the same gate chain:

```
auth:sanctum → tenant.isolation → subscription.guard
            → module.guard:lead_crm → permission:manage-crm → capability.guard:crm
```

`/api/v1/crm/leads` is the separate Developer API surface. It was deliberately not extended
by Tasks 4–10. **Task 11** added single-lead read, status, assignee and tag attach/detach
(`GET /{id}`, `PATCH /{id}/status`, `PATCH /{id}/assignee`, `POST|DELETE /{id}/tags/{tag}`)
under `subscription.apikey → module.apikey:lead_crm → capability.apikey:crm`. Still no list,
delete, contacts, pipeline, tag CRUD or bulk on `/v1`.

### CRM frontend (Task 8)

| Frontend route | Screen |
|---|---|
| `/crm/leads` | Lead list — server-side filters (search, status, source, assignee/unassigned, tags AND), pagination, inline status / assignee / tag actions, create; **Task 9:** page-scoped selection + bulk bar (assign, unassign, status, add/remove tag) |
| `/crm/leads/:id` | Lead detail — status, assignee, tags, capture origin, contact link + reassignment, delete |
| `/crm/pipeline` | Kanban — 4 server columns + totals, per-column load more, shared filters |
| `/crm/contacts` | Contact list — search, pagination, lead counts, create |
| `/crm/contacts/:id` | Contact detail — edit, delete (409 surfaced), merge, leads (status/source filters) |
| `/crm/tags` | Tag management — create, rename, delete, prefix search, lead counts |

Gated in the UI by `manage-crm` + `lead_crm` module + `crm` capability (existing
`/auth/me` data); the backend chain above remains the boundary. No backend change.

---

## Standing Findings

These were established experimentally during Phase 6 and constrain future work.

### MariaDB 10.11.14 foreign-key behaviour (proven, not assumed)

| Attempt | Result |
|---|---|
| Composite FK + `ON DELETE SET NULL` where any child column is `NOT NULL` | **Refused** — ERROR 1005 / errno 150 |
| `CHECK` constraint over a `SET NULL` column | **Refused** — ERROR 1901 |
| Composite FK + `RESTRICT` | Breaks `DELETE FROM accounts` — ERROR 1451 |
| Composite FK + `CASCADE` | **Works**; account deletion cascades cleanly |

This is why the capture-lead link lives on `crm_leads.capture_lead_id` with a composite
`CASCADE` FK rather than the originally proposed shape.

### SQLite cannot `ALTER` foreign keys

All FK work is driver-guarded (`in_array($driver, ['mysql', 'mariadb'])`). A migration
that creates an FK on SQLite makes the later drop fail with *"unknown column in foreign
key definition"*.

### `LogsActivity` is not assertable in PHPUnit

`recordActivity()` returns early under `app()->runningInConsole() || !Auth::check()`, so
`activity_logs` rows are **never** written during a test run. Established workaround:
assert the `updated` event payload plus `Auth::id()` inside the listener.

### Laravel global middleware affects validation semantics

`ConvertEmptyStringsToNull` means `''` ≡ `null` everywhere; `TrimStrings` means
`' contacted '` ≡ `'contacted'`. Several Task 5 tests had to be rewritten to test actual
behaviour rather than the assumed behaviour.

### Pre-existing Spatie guard bug (documented, not fixed)

All permission rows carry `guard_name: 'web'` while a Sanctum request resolves `'sanctum'`.
Consequence: `POST /api/roles` returns 500 for **every** permission — including pre-existing
ones such as `manage-social-leads`, which is how it was proven pre-existing rather than
CRM-introduced. Tests must build roles **before** the first `actingAs()`.

### Composite FKs declared in `CREATE TABLE` are enforced on SQLite too (Task 7)

SQLite cannot *ALTER* a foreign key, but it does enforce one declared inside
`CREATE TABLE`. `crm_lead_tags`' two composite FKs are therefore enforced on both engines
(confirmed with `PRAGMA foreign_key_list` and raw-insert tests), unlike the Round 2
capture-link FK, which is MySQL-only because it was added by ALTER.

### Row-level audit can be verified over real HTTP (Task 7)

`activity_logs` rows are not written under PHPUnit, but they are under `php artisan serve`
against a throwaway MariaDB DB. Task 7 used that to verify actual audit rows for tag
create/update/delete and attach/detach.

### `source` is unindexed on `crm_leads`

A `source`-filtered grouped COUNT costs ~116 ms at 120k leads in one tenant. Below the
bar for a new index today; revisit if source-filtered dashboards become a primary access
path.

---

## Schema Invariants

- `crm_leads.status` is the **only** status field. No `pipeline_status`, `pipeline_stage`,
  `kanban_status`, `custom_status` or `stage` column exists — re-verified each task against
  `information_schema.COLUMNS`.
- `crm_leads.assigned_user_id` is the **only** ownership field. No `owner_id` or
  `sales_owner_id`.
- Tags are metadata, never status: no `tag_status`, `lead_stage`, `pipeline_tag` or
  `custom_status` column. Tags live only in `crm_tags` / `crm_lead_tags`; every assignment
  row carries `account_id` bound to **both** parents by composite FKs (Task 7).
- 15 CRM migrations: `2026_09_22_170000` → `2026_09_22_190004` (Tasks 1–H2), plus
  `2026_09_23_100000` → `2026_09_23_100002` (Task 7). Tasks 3–6 added none.

---

## Verification Pipeline (applied to every task)

1. Targeted suite for the new work
2. Full SQLite suite (secondary)
3. Fresh throwaway MariaDB database → full suite (**authoritative**)
4. Migration forward → rollback → forward, on a throwaway database only
5. `EXPLAIN` on MariaDB with realistically seeded data, where query shape changed
6. `php -l` on every changed file + `php artisan route:list`
7. md5 drift-compare container vs. device before committing
8. `expectedMtimeMs`-guarded commit, then md5 re-verification on the device

**State protection:** the real `wa_saas_platform` database has never been migrated,
seeded or written to at any point in Phase 6. All work used throwaway databases inside
the container (`wa_test`, `wa_test2`, `wa_t3`…`wa_t6b`, `wa_explain`).
