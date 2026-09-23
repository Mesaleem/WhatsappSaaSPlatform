# Phase 6 — CRM Task 9: Bulk Operations & Productivity

**Date:** 2026-09-23 · **Status:** ✅ PASS · Backend 1220/1220 (MariaDB 10.11.14) · Frontend 523/523

Bulk assign / unassign / status / tag attach / tag detach for up to 100 CRM leads, built on the
existing CRM domain. No new permission, capability, plan, migration or Developer API surface.

---

## 1. Existing infrastructure inspected

- **Backend:** no generic bulk-mutation framework exists. The bulk code that does exist is
  messaging-specific (`BulkMessageDispatcher`, `SendBulkTemplateMessageRequest`, queued per
  recipient). Two conventions were reused: a FormRequest `MAX_*` constant with
  `array|min:1|max:N` (SendBulkTemplateMessageRequest), and "re-verify every id server-side"
  (AgentPayoutService / `commission_ids`).
- **Frontend:** no row-selection or bulk-action component exists anywhere; the CRM list used
  the Task 8 `useCrmQuery` hook, `TagPicker`, `ConfirmModal`, inline toast and
  `describeApiError`, all reused here.

## 2. Files

**Backend — created:** `app/Services/Crm/CrmBulkLeadSelection.php`,
`app/Http/Controllers/Api/CrmLeadBulkController.php`, `app/Http/Requests/BulkCrmLeadRequest.php`,
`BulkAssignCrmLeadsRequest.php`, `BulkChangeCrmLeadStatusRequest.php`, `BulkCrmLeadTagRequest.php`,
`tests/Feature/CrmLeadBulkOperationsTest.php` (50 tests).

**Backend — modified:**

| File | Change |
|---|---|
| `Services/Crm/CrmLeadService.php` | `bulkChangeStatus()`, `bulkChangeAssignee()` (reuse `applyStatus`/`applyAssignee`) |
| `Services/Crm/CrmTagService.php` | `bulkAttach()`, `bulkDetach()`, `assertTagBelongs()` |
| `Models/CrmLead.php` | eligibility lookup moved into `assigneeIsEligibleById()` (same query + same `assigneeIsEligible()`), with a memo active only inside `withAssigneeEligibilityMemo()`; `$auditIdentity = ['id']` |
| `Models/CrmLeadTag.php` | `withVerifiedPairs()` — lets the creating guard skip re-querying pairs a bulk call has just proven |
| `Traits/LogsActivity.php` | opt-in `auditIdentityAttributes()` — `update` rows of opted-in models include the record id |
| `routes/api.php` | 4 bulk routes; `whereNumber` on every lead/contact `{id}` |

**Frontend — created:** `components/crm/CrmBulkActionBar.tsx`, `pages/crm/CrmLeadsBulk.test.tsx` (19 tests).
**Frontend — modified:** `pages/crm/CrmLeadsPage.tsx` (selection + bar), `services/crmService.ts` (4 methods), `types/crm.ts` (`CrmBulkSummary`, `CRM_BULK_MAX`).

## 3. API

| Route | Body |
|---|---|
| `POST /api/crm/leads/bulk/assignee` | `{"lead_ids":[…], "assigned_user_id": 55 \| null}` |
| `POST /api/crm/leads/bulk/status` | `{"lead_ids":[…], "status":"contacted", "not_converted_reason"?: "…"}` |
| `POST /api/crm/leads/bulk/tags/attach` | `{"lead_ids":[…], "tag_id": 12}` |
| `POST /api/crm/leads/bulk/tags/detach` | `{"lead_ids":[…], "tag_id": 12}` |

Middleware: `auth:sanctum → tenant.isolation → subscription.guard → module.guard:lead_crm →
permission:manage-crm → capability.guard:crm` (verified with `route:list -v`).

**Success (200):** `{"message":"Lead statuses updated.","data":{"operation":"status","requested":25,"changed":22,"unchanged":3}}`
(`operation` ∈ `assign`, `unassign`, `status`, `tag_attach`, `tag_detach`).

**Rejections (422, nothing changed):**
`lead_ids` → "One or more selected leads are not available." (foreign and missing ids identical) ·
`tag_id` → "The selected tag is not available." · `assigned_user_id` → "The selected assignee is
not available." (foreign / missing / inactive / no manage-crm identical) · `status` → "One or
more selected leads cannot move to X." · shape errors (`lead_ids` missing, empty, not an array,
non-integer, ≤0, duplicate, >100). 403 for module / permission / capability / expired
subscription, 401 unauthenticated.

**Batch limit:** 100 (`CrmBulkLeadSelection::MAX_LEADS`) — the largest page the CRM list can
show, so a full page is always one request. Enforced by validation server-side.

## 4. Behaviour

- **Tenant isolation:** leads are loaded with `WHERE account_id = ? AND id IN (…) FOR UPDATE`;
  if the count differs from the number requested, the whole request is refused before any write.
  A 99-valid + 1-foreign batch mutates nothing (tested for all four operations). The account comes
  only from `TenantIsolationMiddleware` (Super Admin `?account_id=`, Agent sub-clients) — tested.
- **Transactions:** one transaction per request. Status transitions are all checked before the
  first write. Tests throw inside the 3rd/4th save or the 2nd pivot insert and assert that every
  lead and pivot row is byte-identical to before.
- **Concurrency:** the selected lead rows (only those rows, by primary key) are locked
  until commit on MySQL/MariaDB, so overlapping single/bulk writes to the same leads serialize.
  Beyond that the existing behaviour is last-write-wins (documented in Task 4); no version
  column added.
- **Assignment:** same `applyAssignee()`; the target is checked once up front with the same
  predicate, and the per-save guard still runs for every lead (answer memoized for this call
  only; a test proves the memo does not leak into later individual writes).
- **Status:** same `applyStatus()` + `STATUS_TRANSITIONS`; outcome bookkeeping
  (`converted_at` / `not_converted_*`) identical to the individual endpoint (tested row-for-row).
- **Tags:** attach creates only missing pairs (one query to find existing pairs); detach deletes
  existing pairs; leads, tag, contacts, status and owner untouched; no duplicate pivots.
- **No-ops:** unchanged leads/pairs are not written and not audited; they count as `unchanged`.
- **Audit:** one `activity_logs` row per changed lead or pivot — `user_id`, `account_id`,
  `module_name = CRM`, `route_path = api/crm/leads/bulk/...`, `old_values`/`new_values` with
  the lead id (new opt-in `$auditIdentity`). **Verified on real rows** both in PHPUnit (the test
  switches the app out of console mode for its requests) and over real HTTP (`php artisan serve`
  on a throwaway MariaDB DB).

## 5. Performance (MariaDB 10.11.14, 110k leads, 220k assignments)

| Query | Plan |
|---|---|
| bulk selection `FOR UPDATE` (100 ids) | range on `crm_leads_id_account_id_unique`, rows=100 |
| existing-pair lookup (attach/detach) | range on `crm_lead_tags` PRIMARY, rows=100 |

Timed service calls on 100 leads (rolled back): status 50 ms / 76 queries (1 select + 75
updates), attach 27 ms / 95 queries (2 selects + 93 inserts), detach 5 ms. Tests assert that the
SELECT count does not grow with batch size (2 vs 20 leads): one lead selection, one assignee
lookup, no per-row tag/lead ownership queries. Writes are per row by design (per-lead audit).
No index added. No load testing performed.

## 6. Frontend

- Checkbox per row + "select all N leads on this page" (indeterminate when partial); bar shows
  "N selected · on this page only". Selection is page-scoped by construction: it is keyed to the
  view (client + filters + page + page size) and dropped when any of them changes or the page
  unmounts; only ids of rows on screen can be sent (max 100).
- Bar: Assign (modal, eligible assignees from `/crm/assignees`), Unassign (confirm), Change
  status (4 statuses, optional not-converted reason, confirmation stating count + target and
  that the server may reject all), Add tag / Remove tag (server-searched `TagPicker`), Clear.
  Disabled while in flight and when read-only. No delete / contact actions.
- Success: server counts in the toast ("Status set to Converted: 1 changed, 2 unchanged."),
  selection cleared, list re-read. Failure: the server message as an alert, no local mutation,
  selection kept, list re-read. 401/403/404/409/422/429/5xx/network handled via
  `describeApiError()` (no raw server text).
- Pipeline fetches on mount, so it reflects bulk changes; no client-side status mutation.

## 7. Verification

| Check | Result |
|---|---|
| Backend full suite, MariaDB 10.11.14 | **1220 passed**, 4711 assertions, 0 failed, 0 skipped |
| Backend full suite, SQLite | 1216 passed + 4 skipped (pre-existing FK skips) |
| `CrmLeadBulkOperationsTest` | 50 tests; a mutation check (tenant count check disabled) made 6 fail |
| `php -l` on changed files · `route:list --path=crm` | clean · 33 CRM routes (4 bulk) |
| Frontend `tsc -b` / `npm run build` | clean / success |
| `npm run lint` (oxlint) | 64 warnings, 0 errors — unchanged baseline, none in CRM files |
| `npm test` | **16 files, 523 tests** (504 + 19); a mutation check (selection not view-keyed) made 2 fail |
| Real-stack E2E (headless Chromium) | select 3 → bulk status → DB `converted×3`, toast "1 changed, 2 unchanged"; bulk add tag → 3 pairs; capability revoked in DB → bulk 403 shown; exactly one POST per action; audit rows present |

## 8. Known limitations

1. Selection is page-scoped only (max 100 per action); no "select all matching the filter".
2. Bulk actions exist on the lead list only, not on the pipeline or contact-leads views.
3. Writes are per lead (needed for per-lead audit); fine at ≤100, not a mass-update path.
4. After a successful bulk action the list reloads; the pipeline refreshes when opened.
5. No load testing.

## 9. Scope boundary

No bulk delete / merge / contact reassignment, no drag-and-drop, no Developer API bulk, no new
permission/capability/plan/migration, no automation/Journeys/AI/analytics.
