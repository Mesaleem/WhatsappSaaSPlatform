# Phase 6 — CRM Task 7: Lead Tags & Segmentation Foundation

**Date:** 2026-09-23 · **Status:** ✅ PASS · **Suite after:** 1170 passed, 0 failed, 0 skipped (MariaDB 10.11.14)

Tenant-scoped CRM tags: CRUD, idempotent attach/detach, tag filtering on the shared lead
filter (list + pipeline), and tags in the canonical lead serialization. Additive only —
`crm_leads.status`, `STATUSES`, `STATUS_TRANSITIONS`, `canTransitionTo()` and
`pipelineStatuses()` are unchanged.

---

## 1. Inspection result

- **No existing tag system.** Searched `app/`, `database/`, `routes/`, `frontend-app/src`,
  `composer.json` and the `wa_saas_platform.sql` dump for tag tables/models/packages. Only
  hits: an AI-copywriter CTA string and a Task 1 docblock saying "no tag". Nothing reused,
  nothing duplicated.
- **No CRM frontend** (`frontend-app/src/pages` has no CRM directory) → backend/API only.
- **No bulk-operation convention** in the CRM → no bulk endpoint (see §9).
- **Contacts merge moves leads between contacts; leads are never merged.** Tag
  assignments live on leads, so a merge cannot create duplicate `(lead, tag)` pairs.

## 2. Files

**Created**

| File | Purpose |
|---|---|
| `database/migrations/2026_09_23_100000_add_id_account_id_unique_to_crm_leads_table.php` | `unique(id, account_id)` on `crm_leads` — referenced by the pivot's composite FK |
| `database/migrations/2026_09_23_100001_create_crm_tags_table.php` | `crm_tags` |
| `database/migrations/2026_09_23_100002_create_crm_lead_tags_table.php` | `crm_lead_tags` pivot |
| `app/Models/CrmTag.php` | tag model: name rules, duplicate guard, `leads()`, `forAccount`, `withLeadCount`, `orderedByName` |
| `app/Models/CrmLeadTag.php` | assignment model (audited, tenant-guarded, immutable) |
| `app/Services/Crm/CrmTagService.php` | the only write path: create / rename / delete / attach / detach |
| `app/Http/Controllers/Api/CrmTagController.php` | tag CRUD |
| `app/Http/Requests/StoreCrmTagRequest.php`, `UpdateCrmTagRequest.php` | `name` rules |
| `tests/Feature/CrmLeadTagTest.php` | 93 tests / 469 assertions |

**Modified (additive)**

| File | Change |
|---|---|
| `app/Models/CrmLead.php` | `tags()` relation; `filterRules(?int $accountId = null)` gains `tag_id` / `tag_ids[]`; `filterMessages()`; `TAG_FILTER_MAX`; tag leg in `scopeFilter()` |
| `app/Models/Account.php` | `crmTags()` |
| `app/Http/Controllers/Concerns/PresentsCrmLeads.php` | `tags` in `presentCrmLead()`; `'tags'` in `crmLeadRelations()` |
| `app/Http/Controllers/Api/CrmLeadController.php` | `attachTag()` / `detachTag()`; `index()` passes the account to `filterRules()` |
| `app/Http/Controllers/Api/CrmPipelineController.php` | passes the account to `filterRules()` |
| `routes/api.php` | 7 routes inside the existing CRM group |

No seeder, permission, capability, plan or frontend file changed.

## 3. Schema

```sql
crm_tags
  id               bigint unsigned PK
  account_id       bigint unsigned NOT NULL  FK -> accounts(id) ON DELETE CASCADE
  name             varchar(50) NOT NULL       -- display form, trimmed + whitespace-collapsed
  normalized_name  varchar(50) NOT NULL       -- lower(name); utf8mb4_bin on MySQL/MariaDB
  created_at, updated_at
  UNIQUE (account_id, normalized_name)        -- duplicate rule (also list order / prefix search)
  UNIQUE (id, account_id)                     -- referenced by the pivot's composite FK

crm_lead_tags
  crm_lead_id  bigint unsigned NOT NULL
  crm_tag_id   bigint unsigned NOT NULL
  account_id   bigint unsigned NOT NULL
  created_at   timestamp NULL DEFAULT CURRENT_TIMESTAMP
  PRIMARY KEY (crm_lead_id, crm_tag_id)
  KEY (crm_tag_id, account_id)                -- tag -> leads, counts, tag FK
  KEY (crm_lead_id, account_id)               -- required by the lead FK
  FK (crm_lead_id, account_id) -> crm_leads(id, account_id) ON DELETE CASCADE
  FK (crm_tag_id,  account_id) -> crm_tags(id,  account_id) ON DELETE CASCADE

crm_leads
  + UNIQUE (id, account_id)
```

**No slug.** Nothing routes or keys on one; `normalized_name` already is the stable
account-scoped identifier. **Why `normalized_name` + binary collation instead of relying on
`utf8mb4_unicode_ci`:** unicode_ci would also fold accents (`Café` = `Cafe`) on MariaDB
while SQLite's BINARY collation folds nothing — the engines would disagree about what a
duplicate is. The PHP-defined normalization gives one rule on both.

## 4. Tenant-safe constraints

A single `account_id` column must match **both** parents, so a lead from Account A can
never be paired with a tag from Account B. Proven on MariaDB and SQLite by raw
`DB::table('crm_lead_tags')->insert()` attempts claiming either account (both refused).
The FKs are declared inside `CREATE TABLE`, which SQLite supports — so, unlike the Round 2
capture-link FK, they are enforced on both engines (confirmed via `PRAGMA foreign_key_list`).
CASCADE follows the proven §7 finding (composite FK + CASCADE works; RESTRICT breaks
`DELETE FROM accounts`).

Layers, outermost first: `forAccount()` lookups (404) → `CrmTagService::assertSameAccount()`
→ `CrmLeadTag` creating guard (422 `The selected tag is not available.`) → composite FKs.

## 5. Name rules

- `required|string|max:50` in the request (TrimStrings + ConvertEmptyStringsToNull run
  first, so `""` and `"   "` fail `required`).
- Model: whitespace runs collapsed (`"  Follow   Up "` → `"Follow Up"`), re-checked
  non-empty and ≤ 50, `normalized_name = mb_strtolower(clean(name))`.
- Case-insensitive (incl. multibyte `Été`/`ÉTÉ`) duplicate within an account → **422**
  `{"errors":{"name":["A tag with this name already exists."]}}`. Never renamed/merged.
- Same name in different accounts: allowed.
- Case-only rename of the same tag (`hot` → `HOT`): allowed.
- Concurrent duplicate creates: the unique index rejects the loser; the service converts
  the `UniqueConstraintViolationException` into the same 422.
- A tag can never change `account_id`.

## 6. API

All routes sit in the existing CRM group:
`auth:sanctum → tenant.isolation → subscription.guard → module.guard:lead_crm →
permission:manage-crm → capability.guard:crm` (verified with `route:list -v`).

### Tag CRUD

| Verb | Route | Body | Success |
|---|---|---|---|
| GET | `/api/crm/tags?search=&per_page=` | — | 200 paginator, `data[]` = `{id,name,lead_count,created_at,updated_at}`, ordered by name |
| POST | `/api/crm/tags` | `{"name":"Hot"}` | 201 `{message:"Tag created.", data}` |
| GET | `/api/crm/tags/{id}` | — | 200 `{data}` |
| PUT/PATCH | `/api/crm/tags/{id}` | `{"name":"Very Hot"}` (required on both) | 200 `{message:"Tag updated.", data}` |
| DELETE | `/api/crm/tags/{id}` | — | 200 `{message:"Tag deleted."}` |

- `search` = case-insensitive **prefix** match (index-served). `%` and `_` are escaped.
  Substring search is intentionally not offered (a leading wildcard can't use the index).
- `per_page` default 50, capped at 100; `per_page=0` → 422.
- Foreign or missing id → identical 404 `{"message":"Tag not found."}`. Non-numeric id → 404.
- `account_id` / `agent_id` / `tenant_id` in the body or query are ignored (not rules).

### Assignment

| Verb | Route | Result |
|---|---|---|
| POST | `/api/crm/leads/{id}/tags/{tag}` | 200 `Tag attached.` · 200 `Tag already attached.` (no-op) |
| DELETE | `/api/crm/leads/{id}/tags/{tag}` | 200 `Tag detached.` · 200 `Tag was not attached.` (no-op) |

Both return the canonical lead (`data`, including `tags`). Foreign/missing lead → 404
`Lead not found.`; foreign/missing tag → 404 `Tag not found.` (identical bodies for
foreign vs missing). Attaching never detaches other tags. Neither action writes to
`crm_leads` (no status, owner, contact or `updated_at` change; proven with a `saving`
listener and an attribute snapshot). The general `PATCH /api/crm/leads/{id}` and
`POST /api/crm/leads` ignore any `tags`/`tag_ids` input.

### Filtering

`GET /api/crm/leads` and `GET /api/crm/pipeline` accept:

- `tag_id=3` — leads carrying tag 3
- `tag_ids[]=3&tag_ids[]=5` — **AND**: leads carrying **both** 3 and 5 (max 10, distinct)
- `tag_id` and `tag_ids[]` together are also AND-ed

A foreign or nonexistent tag id → 422 `The selected tag is not available.` (identical).
Combines with `status`, `source`, `assigned_user_id` (incl. `none`), `search`, pagination.
On the pipeline the tag filter narrows both the grouped COUNT and every column; the column
set is still `pipelineStatuses()`. `GET /api/crm/contacts/{id}/leads` keeps its own
(status/source) filter vocabulary and does not accept tag filters (unchanged).

### Serialization

Every CRM lead response gains `"tags": [{"id":3,"name":"Hot"}, …]`, ordered
case-insensitively by name, `[]` when untagged. Same presenter everywhere (show, list,
pipeline card, contact leads, write responses) — asserted identical.

## 7. Delete / merge behaviour

| Event | Result |
|---|---|
| Tag deleted | its assignments removed (explicit set-based DELETE in the service, plus FK cascade); leads, statuses, contacts and capture rows unchanged (snapshot-compared) |
| Lead deleted | its assignments cascade away; tags remain; no orphans |
| Account deleted | tags and assignments cascade; other tenants untouched |
| Contact merge | moved leads keep their tags; no duplicate pairs; all rows in-tenant; counts correct |
| Lead contact reassignment | tags kept |

## 8. Audit

`CrmTag` and `CrmLeadTag` use `LogsActivity` (module `CRM`). **Row-level verified** outside
PHPUnit: a throwaway MariaDB DB + `php artisan serve` + real HTTP calls produced:

| action | route | old → new |
|---|---|---|
| create | `api/crm/tags` | `{account_id:1,name:"Hot",id:1}` |
| update | `api/crm/tags/1` | `{name:"Hot"}` → `{name:"Very Hot"}` |
| create | `api/crm/leads/1/tags/1` | `{crm_lead_id:1,crm_tag_id:1,account_id:1}` |
| delete | `api/crm/leads/1/tags/1` | `{crm_lead_id:1,crm_tag_id:1,account_id:1}` |
| delete | `api/crm/tags/1` | `{id:1,account_id:1,name:"Very Hot",lead_count:0}` |

The idempotent re-attach wrote no row; no `crm_leads` update row was written by any tag
action. In PHPUnit (where rows are never written — §7 finding) the model events and
`Auth::id()` are asserted instead. **Not audited per-lead:** assignments removed by a tag
delete (bulk) — the tag's own delete row records it.

## 9. Scope decisions

- **Bulk:** not built (no CRM bulk convention). Bulk later = loop/set-insert of the same
  `(lead, tag, account)` rows — no schema change.
- **Developer API:** unchanged. `/api/v1/crm/tags` → 404; `/api/v1/crm/leads` ignores
  `tag_ids`/`tags` and its response shape is unchanged.
- **Plan/capability/seeders:** no change required. Tagging uses `crm` + `manage-crm`;
  no `%tag%` permission or capability exists (asserted).

## 10. Query / EXPLAIN (MariaDB 10.11.14)

Seed: 110k leads (100k in one tenant), 60 tags, 220k assignments (~6.7k leads per tag).

| Query | Access path | Time |
|---|---|---|
| tag lookup + count | `crm_tags` const PK; pivot ref `crm_lead_tags_tag_account_index` (index-only) | 1.5 ms |
| tag list + counts + prefix search | range on `crm_tags_account_normalized_name_unique`; pivot ref (index-only) | 9 ms |
| duplicate-name check | unique `(account_id, normalized_name)` const | 0.3 ms |
| lead → tags (eager load, 20 leads) | range on `crm_lead_tags_lead_account_index` + `crm_tags` eq_ref | 0.5 ms |
| tag → leads | pivot ref `tag_account_index` + `crm_leads` eq_ref | 0.4 ms |
| tag-filtered list page | semi-join from pivot `tag_account_index` → `crm_leads` eq_ref, filesort | 18 ms |
| tag-filtered list total | same, no sort | 7.5 ms |
| AND filter (2 tags) page | + second probe on pivot PRIMARY eq_ref | 21 ms |
| tag-filtered pipeline grouped count | same semi-join | 10 ms |
| tag-filtered pipeline column | same semi-join | 10 ms |
| tag `exists` validation | `crm_tags` const PK | 0.3 ms |

`lead_count` is a correlated COUNT on the pivot only (`CrmTag::scopeWithLeadCount()`);
the first implementation (`withCount('leads')`) joined `crm_leads` and cost 78 ms for the
list vs 9 ms now. The tag-filtered list sorts the tagged set (bounded by tag size, not
tenant size). No new speculative index.

N+1: the list, pipeline, contact-leads and both filtered surfaces keep a constant query
count from 2 → 14 tagged leads (tags loaded once per result set) — asserted.

## 11. Verification

| Check | Result |
|---|---|
| Baseline before change | SQLite 1073 + 4 skipped · MariaDB 1077 — matches PROJECT_STATE |
| `CrmLeadTagTest` | 93 passed / 469 assertions on both engines |
| All CRM suites (MariaDB, throwaway DB) | 507 passed |
| **Full suite, MariaDB 10.11.14 (authoritative)** | **1170 passed, 4362 assertions, 0 failed, 0 skipped** |
| Full suite, SQLite | 1166 passed + 4 skipped (pre-existing FK skips), 4353 assertions |
| Migration forward → rollback (`--step=3`, over live rows) → forward, MariaDB | ✅; post-rollback: tag tables and `crm_leads_id_account_id_unique` gone, `crm_leads` rows intact; re-forward schema md5-identical to a fresh migrate |
| Same on SQLite | ✅ |
| `php -l` on every changed file | ✅ |
| `route:list --path=crm` | 25 routes; middleware chain verified with `-v` |
| Existing tests changed | **none** |

Not performed: load testing; frontend checks (no frontend change).
