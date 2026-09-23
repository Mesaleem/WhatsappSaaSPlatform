# Phase 6 — CRM Task 8: CRM Frontend Foundation

**Date:** 2026-09-23 · **Status:** ✅ PASS · **Scope:** `frontend-app` only — no backend change

The first CRM UI in `frontend-app`, built only on the existing Task 1–7 CRM API.

---

## 1. Inspection summary

- **Stack reused:** React 19 + react-router 7 + axios (`core/api/axiosInstance`) + Tailwind 3; the
  existing `Card`/`TableCard`, `DataTableControls` (SearchInput, StatusFilterSelect,
  ClearFiltersButton, Pagination), `TableSkeletonRows`, `ConfirmModal`, `PromptModal`, the
  inline-toast pattern, `describeApiError()`, `ProtectedRoute`, `AppLayout` nav, `TenantContext`
  (Super Admin / Agent client switcher appended as `?account_id=` by the axios interceptor).
- **No query/cache library** exists in the app → none added. A 60-line keyed-request hook
  (`useCrmQuery`) gives stale-response protection and quiet refetch after mutations.
- **Access data:** `/auth/me` already returns `permissions`, `account.effective_modules` and
  `capabilities` (`AccessControlService::capabilityMap`). Nothing new was needed from the backend.
- **Every endpoint and field used was verified against the Laravel controllers** (CrmLeadController
  + PresentsCrmLeads, CrmContactController, CrmTagController, CrmAssigneeController,
  CrmPipelineController, the Store/Update/Assign/ChangeStatus request classes). No backend contract
  defect was found.

## 2. Files

**Created (frontend-app/src)**

| File | Purpose |
|---|---|
| `types/crm.ts` | CRM types mirroring the API; the 4 statuses and 5 sources |
| `services/crmService.ts` | One method per existing `/api/crm/*` endpoint |
| `components/crm/crmFilters.ts` | URL ⇄ filter state, shape-checked; date formatting |
| `components/crm/crmHooks.ts` | `useCrmContext`, `useCrmUrlState`, `useCrmQuery`, `useCrmAssignees`, `useCrmTagIndex`, `useCrmToast` |
| `components/crm/CrmUi.tsx` | StatusBadge, TagChips, StatusControl, AssigneeControl, TagPicker, LeadTagEditor, empty/error/no-client/toast/spinner |
| `components/crm/CrmLeadFilterBar.tsx` | The shared lead filter bar (Leads + Pipeline) |
| `components/crm/CreateLeadModal.tsx` | POST /crm/leads form |
| `components/crm/ContactFormModal.tsx` | POST/PATCH /crm/contacts form |
| `pages/crm/CrmLeadsPage.tsx` | `/crm/leads` |
| `pages/crm/CrmLeadDetailPage.tsx` | `/crm/leads/:id` |
| `pages/crm/CrmPipelinePage.tsx` | `/crm/pipeline` |
| `pages/crm/CrmContactsPage.tsx` | `/crm/contacts` |
| `pages/crm/CrmContactDetailPage.tsx` | `/crm/contacts/:id` |
| `pages/crm/CrmTagsPage.tsx` | `/crm/tags` |
| `test/crmFixtures.ts` | Test fixtures shaped like the API |
| 7 test files | see §9 |

**Modified**

| File | Change |
|---|---|
| `App.tsx` | 6 CRM routes, each `<ProtectedRoute permission="manage-crm" module="lead_crm" capability="crm">` |
| `components/layout/AppLayout.tsx` | 4 nav items (CRM Leads / Pipeline / Contacts / Tags); `requiresCapability` nav gate |
| `core/guards/ProtectedRoute.tsx` | optional `capability` prop (reads the existing `/auth/me` map) |
| `pages/errors/UnauthorizedPage.tsx` | "Not included in your plan" message for a capability block |

## 3. Behaviour

- **Lead list** — `GET /crm/leads`; columns: lead/contact name (+email, "Open contact"), phone,
  status (inline control), source (+capture provider), assignee (inline control), tags (inline
  attach/detach), created. Filters: search, status, source, assignee incl. **Unassigned**, tags
  (multi, **AND**, max 10). Pagination 10/20/50/100.
- **Lead detail** — `GET /crm/leads/{id}`; status (4 statuses; "Not Converted" asks for the
  optional reason), assignee (eligible list; a no-longer-eligible current owner is shown but not
  selectable), tags, source, created/updated/converted/closed timestamps, capture origin, contact
  card with link + "Move to another contact" (`PATCH …/contact`), delete with confirmation.
- **Pipeline** — `GET /crm/pipeline`; the server's 4 columns, labels and totals; per-column
  "Load more" (`status=<column>&page=n+1`); status change on a card re-reads the pipeline.
  Horizontal scroll on narrow screens. No drag-and-drop.
- **Contacts** — list with search/pagination/lead count/create; detail with edit, delete (the
  backend's 409 "still has related records" is shown), merge (explicit phone-discard
  checkbox → `confirm_phone_discard`), leads with the endpoint's own status/source filters.
- **Tags** — list with prefix search, lead counts (link to `/crm/leads?tags=<id>`), create,
  rename, delete. Names are sent as typed; trimming, case-insensitive duplicates and length are
  the backend's rules and its 422 is shown. Delete confirmation states the tag is removed from N
  leads and that leads/contacts are not deleted or changed.
- **Mutations** — every one uses its dedicated endpoint; controls are disabled while in flight
  (no double submit); the server's returned row replaces the local one; lists/pipeline re-read
  quietly afterwards so totals and filter membership stay server-authoritative; success/error via
  the existing inline toast.
- **States** — skeleton/spinner loading; distinct empty states (none yet vs. none matching
  filters); errors through `describeApiError()` (401 handled globally, 403/404/409/422 show the
  server message, 5xx/network show generic text — raw Laravel exception text is never shown).

## 4. Access, tenant and security

- UI gates = the backend's: `manage-crm` + `lead_crm` module + `crm` capability. No new
  permission, capability, plan or entitlement. Super Admin bypasses the UI gates and must select
  a client (existing switcher); until then CRM pages make **no** API call.
- Expired subscription → all CRM mutations disabled with the existing "Action disabled:
  Subscription expired." tooltip; reads still work.
- No CRM form sends `account_id`/`agent_id`/`tenant_id`; the only tenant parameter is the
  existing interceptor's `account_id` for a Super Admin/Agent selection. URL filter values are
  shape-checked (an invented status/source/id never reaches the API).

## 5. Verification

| Check | Result |
|---|---|
| `tsc -b` | clean |
| `npm run lint` (oxlint) | 64 warnings, 0 errors — identical to baseline; **0 in any new/changed file** |
| `npm run build` | success; bundle 2,096 → 2,156 kB raw (538.7 → 549.8 kB gzip); the >500 kB chunk warning is pre-existing |
| `npm test` (vitest) | **15 files, 504 tests, 0 failures** (baseline 8 files / 417) |
| Backend CRM suites, MariaDB 10.11.14 | 507 passed |
| Backend full suite, MariaDB 10.11.14 | **1170 passed, 0 failed** (unchanged from Task 7) |
| Backend files changed | none (md5-verified against the device) |

**End-to-end against the real stack** (throwaway MariaDB DB, `php artisan serve`, production
`vite build` + `vite preview`, headless Chromium): login → CRM nav visible; lead list rendered 12
seeded leads; tag filter produced `?tags=3` and 3 rows; inline status change persisted; tag
attach and assignment on the detail page persisted; pipeline showed the 4 columns with server
totals; duplicate tag "hot" showed the backend's 422; new tag "  Interested  " stored trimmed;
contact detail showed its leads. Plan revoked in the DB → `/crm/leads` redirected to "Not
included in your plan" and the CRM nav disappeared; re-granted → restored; subscription expired
→ mutations disabled. Super Admin: no CRM request until a client was chosen in the header
switcher, then every CRM request carried `account_id`. At 820 px and at 390 px (sidebar
collapsed) no page had horizontal page overflow; tables and the Kanban scroll inside their cards.
Only failed network requests were Google Fonts / Stripe.js (sandbox egress), unrelated.

## 6. Known limitations

1. The app shell's sidebar is fixed-width and does not auto-collapse on phones (pre-existing,
   app-wide); at 390 px CRM is usable after pressing "Collapse".
2. Pipeline: no drag-and-drop; a status change re-reads page 1 of every column (cards loaded via
   "Load more" are reloaded from page 1).
3. Tag names for tag ids in a filter come from the first 100 tags (one request); others show as
   "Tag #id" (filtering still works). The tag picker searches the server (20 results).
4. The assignee filter lists only currently-eligible assignees (the backend has no "all
   assignees ever" endpoint); a URL with another user id still filters and shows "User #id".
5. The contact-leads view offers status/source only — that endpoint accepts nothing else.
6. Pre-existing, found during E2E, not fixed (not CRM): a Super Admin's selected client is
   cleared on a hard page reload (`TenantContext`); re-selecting works.
7. No load testing performed.

## 7. Scope boundary

CRM frontend only. No backend, Developer API, plan/seeder, Journey, automation, AI, scoring,
analytics, custom field/stage, QR engine or Meta provider change.
