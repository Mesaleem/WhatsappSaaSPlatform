# Phase 6 — CRM Task 12: CRM Analytics & Phase 6 Closure Audit

**Date:** 2026-09-23 · **Analytics:** ✅ PASS · **Phase 6 closure:** ✅ YES after the final closure step (§6); §5 records the state before it
Backend 1337/1337 (MariaDB 10.11.14) · SQLite 1333 + 4 skipped · Frontend 535/535

## 1. Analytics — what was built

No CRM analytics or CRM export existed (verified: `AnalyticsController`/`ExportController` never
touch CRM tables; no frontend CRM analytics).

| File | Change |
|---|---|
| `app/Services/Crm/CrmAnalyticsService.php` (new) | 4 aggregate queries + 1 name lookup; bucketing in PHP |
| `app/Http/Controllers/Api/CrmAnalyticsController.php` (new) | validation (`CrmLead::filterRules()` + `from`/`to`), `requireAccount()` |
| `routes/api.php` | `GET /api/crm/analytics` inside the CRM group (inherits every gate) |
| `tests/Feature/CrmAnalyticsTest.php` (new) | 36 tests |
| `tests/Feature/CrmEntitlementRbacApiTest.php` | CRM route inventory 28 → 29 |
| `frontend-app/src/pages/crm/CrmAnalyticsPage.tsx` + test (new) | page (Pattern A) + 11 tests |
| `frontend-app/src/{types/crm.ts, services/crmService.ts, App.tsx, components/layout/AppLayout.tsx}` | type, `analytics()`, route + nav with the 3 CRM gates |
| `frontend-app/src/pages/crm/CrmAccess.test.tsx` | analytics route/nav added to the access matrix |

**Formulas** (dataset = account's `crm_leads` ∩ shared filters ∩ `created_at` ∈ [from 00:00, to+1 00:00) UTC):
`total = COUNT(*)`; `new/contacted/converted/not_converted = COUNT(*)` by current status;
`conversion_rate = round(converted / total × 100, 2)`, `null` if total = 0; `by_status`,
`by_source`, `by_assignee` = single `GROUP BY`s (sum to total; non-canonical values kept);
`trend` = `GROUP BY DATE(created_at)` folded to day (≤ 92 d) / ISO week (≤ 731 d) / month.

**Performance** (300k leads, 100k tenant, MariaDB): all-time 155 ms, 30 days 45 ms, 5 queries
constant (tested). No index added (see PROJECT_STATE §7).

## 2. Verification

| Run | Result |
|---|---|
| MariaDB full suite (throwaway DB) | **1337 passed**, 5918 assertions, 0 failed, 0 skipped |
| SQLite full suite | 1333 passed + 4 skipped, 5909 assertions |
| `--filter=Crm` / `Meta\|Social\|Lead\|Ads\|Ctwa` / `Api\|Developer\|V1\|Idempot\|Webhook` / `Qr\|Baileys\|Engine\|Group` / `Analytics` (MariaDB) | 679 / 664 / 224 / 119 / 37 passed |
| Phase 6 migrations: migrate → rollback 15 → migrate (throwaway MariaDB) | 15 rolled back, 5 CRM tables gone, 15 re-applied, 110 ran |
| Analytics mutation checks | no `forAccount` → 5 fail; inclusive next midnight → 1 fails; tags ignored → 2 fail |
| Frontend tsc / tests / build / lint | clean / 17 files 535 passed / built / 64 warnings 0 errors (baseline) |

## 3. Phase 6 audit — area by area

| Area | Evidence | Result |
|---|---|---|
| Schema | 15 Phase 6 migrations; composite tenant FKs on `crm_leads`(contact, capture), `crm_lead_tags`(lead, tag), `contact_group_members`(contact); rollback/re-migrate clean | ✅ |
| Contacts ↔ Leads | `CrmContactLeadLinkingTest` 51, `CrmContactResolutionTest` 29 | ✅ |
| Lifecycle | `CrmLeadStatusLifecycleTest` 71 | ✅ |
| Assignment | `CrmLeadAssignmentTest` 51 | ✅ |
| Notes / activity | Activity: `LogsActivity` on CrmLead, Contact, CrmTag, CrmLeadTag, CrmCaptureLinkFailure, ContactGroupMember (Activity Logs, module "CRM"). **Notes: no entity, never specified in Tasks 1–12** | ⚠️ notes absent |
| WhatsApp association | contact-group members linked to CRM Contacts (composite FK); CTWA → CRM lead (`meta_ad`) | ✅ (no per-contact message history — never specified) |
| CRM UI | 8 CRM frontend test files, 111 tests | ✅ |
| Bulk | `CrmLeadBulkOperationsTest` 50 | ✅ |
| Meta/Ads capture | `CrmMetaAdsCaptureIntegrationTest` 32 + Hardening suites 102 | ✅ |
| Entitlement / RBAC / Developer API | `CrmEntitlementRbacApiTest` 49 | ✅ |
| Analytics | `CrmAnalyticsTest` 36 | ✅ |
| Tenant isolation | forAccount everywhere + composite FKs + per-route tests in every CRM suite | ✅ |
| Plan / seeder / reconciliation | starter ✗ / growth ✓ / business ✓ crm; revoke closes UI + API (tested) | ✅ |
| Audit logging | as above; API-key writes attributed via `api_request_logs` only | ✅ (documented limitation) |
| API security | 401/403/404/409/422/429 fail-closed matrix (Task 11) | ✅ |

No P0/P1 **code** defect found.

## 4. Pre-existing issues outside Phase 6 (not fixed)

1. `subscription.guard` checks the acting user's own account, so an Agent with an active subscription can write to an expired sub-client (platform-wide).
2. Pre-existing `POST /api/roles` Spatie guard bug (PROJECT_STATE §7).
3. Module retrofit gap for older routes (routes/api.php ARCHITECTURE ENFORCER note).
4. Super Admin's selected client lost on hard reload (frontend).

## 5. Why closure was NO before the final closure step (superseded by §6)

1. **CRM notes do not exist** — the closure checklist names "notes/activity"; building it was out of this task's scope. Decide: descope explicitly, or implement as its own task.
2. **Real DB state unknown** — Task 7's 3 migrations must be applied to `wa_saas_platform` by the owner (`php artisan migrate`); the CTWA `source` backfill from Task 10 is optional and also needs authorization.

If (1) is descoped and (2) is confirmed done, every Phase 6 area above passes.

## 6. Final closure (2026-09-23)

1. **CRM Notes — OUT OF SCOPE for Phase 6** (owner decision). The Activity Log (`activity_logs`
   via LogsActivity on every CRM model) is the implemented activity/history capability. No
   notes code was added. Recorded as backlog (PROJECT_STATE §8.4).
2. **Real DB verified, read-only** (MySQL Workbench, "Local Instance" root@127.0.0.1:3306 —
   the `.env` target; server MySQL 8.0.41): 110 migrations recorded = 110 files, last
   `2026_09_23_100002_create_crm_lead_tags_table` (batch 47); 12/12 pre-Task-7 and 3/3 Task 7
   Phase 6 migrations present; `crm_tags` (PK, UNIQUE(account_id, normalized_name),
   UNIQUE(id, account_id), FK account CASCADE) and `crm_lead_tags` (PK(crm_lead_id, crm_tag_id),
   composite FKs to crm_leads and crm_tags) exist; `crm_leads_id_account_id_unique` =
   UNIQUE(id, account_id). Nothing was pending, so **no migration was run and no schema changed.**
3. **Regression:** SQLite 1333 + 4 skipped; MariaDB 1337/1337 (CRM 679, analytics 36); MySQL
   8.0.46 throwaway 1335/1337 — all 679 CRM tests pass; the 2 failures are pre-existing Journey
   tests (JSON key order), outside Phase 6, not fixed. One Task 9 audit assertion was made
   key-order independent (it failed on MySQL 8 for the same reason). Frontend tsc clean, build
   OK, 535 tests, lint 64 warnings / 0 errors.
4. No P0/P1 issue open in Phase 6.
