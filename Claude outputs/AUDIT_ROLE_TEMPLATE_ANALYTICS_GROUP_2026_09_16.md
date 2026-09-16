# `wa-saas-platform` — Role Hierarchy, Template Ownership, Analytics & Group-Module Audit (2026-09-16)

**Scope:** AUDIT ONLY, per explicit instruction. No file, database, or configuration was modified. Every finding below cites the exact file, method, and (where applicable) line range read to support it. Classification legend used throughout: **WORKING** (matches the required behavior, evidence-backed) / **PARTIALLY IMPLEMENTED** / **MISSING** / **INCORRECT** / **UNKNOWN — NEEDS RUNTIME VERIFICATION** (code alone cannot settle it — e.g. depends on data currently in the live DB).

**Method:** Direct, on-device source inspection (`sl-laptop-d61`, `C:\xampp\htdocs\wa-saas-platform`) via the connected-folder shell. Backend: full reads of `Account.php`, `User.php`, `MessageTemplate.php`, `MessageDispatchLog.php`, `AccountController.php`, `AccountService.php`, `MessageTemplateController.php`, `TemplateService.php`, `ContactGroupController.php`, `AnalyticsController.php` (all 774 lines), `RolePermissionSeeder.php`, `TenantIsolationMiddleware.php`, `ResolvesTenantAccount.php`, `EnsureModuleEnabledMiddleware.php`, `ActivityLogController.php`, `AdminUserController.php`, `RoleController.php`, plus targeted reads/greps of `TeamController.php`, `BillingController.php`, `QuotaRequestController.php`, `routes/api.php`, and a full-controller-directory scoping survey (39 controllers). Frontend: `AuthContext.tsx`, `AppLayout.tsx` nav config, `DashboardPage.tsx`, `AnalyticsPage.tsx`, `AccountsPage.tsx`. Database: `wa_saas_platform.sql` dump (schema only, no data mutation).

---

## 1. EXECUTIVE SUMMARY

The codebase is materially better built than a "20+ years of SaaS audits" prior would expect from the bug report alone. The **Agent→Client hierarchy, module delegation cap, and Template ownership/approval workflow are WORKING** — correctly scoped, correctly isolated, and should not be touched. The reported count/graph bug is **real, backend-rooted, and fully traced**: `AnalyticsController` aggregates `message_dispatch_logs` by **counting rows**, but a group send writes **one row per batch** (true per-recipient counts live in that row's `success_count`/`failure_count` columns, added specifically to fix this). The top-line KPIs (`total_sent`, `total_failed`, `total_sent_today`, `total_failed_today`, and the `charts()` daily series) never read those two columns — only the newer `recipient_breakdown` / `today_breakdown` / `daily_by_recipient_type` fields do. This is a **partial fix that was never finished**, not a missing feature.

Two gaps are more serious than the user's own bug report suggested:

1. **Analytics group-data leaks past the module gate.** `/api/analytics/summary` and `/api/analytics/charts` are guarded only by `permission:view-analytics` — there is no `module.guard:contact_groups` check. `recipient_breakdown`, `today_breakdown`, and `active_contact_groups` are computed and returned to **any** caller with `view-analytics` regardless of whether their account has the group module enabled. The frontend correctly hides the corresponding cards, but the data is already in the response by the time it does.
2. **Cross-tenant privilege escalation via `RoleController`.** `PUT /api/roles/{id}` is gated only by `permission:manage-roles`, with no tenant scope and no Super-Admin-only check (unlike the deliberately paranoid `AdminUserController::changePassword`). Spatie roles in this schema are **global, not per-tenant** (the codebase's own `TeamController` docblock says so explicitly). `manage-roles` is granted to the **default `admin` role** — meaning **every ordinary Client Admin, on every tenant, already holds this permission** and can rewrite the platform-wide `admin` or `user` role's permission set, altering every other tenant's Admins/Users. This was not asked about directly but falls squarely under "multi-tenant isolation" and is the single highest-severity finding in this audit.

There is **no Agent-level data rollup anywhere** outside Account management itself: Analytics, Message Logs, and Group Messaging have zero `agent_id`/`ownedByAgent` scoping. An Agent can only ever see its own account's numbers or switch into exactly one sub-client at a time — never an aggregated "all my clients" view. Super Admin has the mirror-image gap: no `?agent_id=` filter and no per-agent breakdown anywhere in Analytics (only in Account management).

The five named group KPI fields ("Total Groups Added", "Total Group Messages", "Total Failed Group Messages", "Today's Group Messages", "Today's Failed Group Messages") exist in spirit but not by those names, and three of the five inherit the row-vs-recipient counting bug described above.

---

## 2. CURRENT ROLE/HIERARCHY IMPLEMENTATION

| Requirement | Status | Evidence |
|---|---|---|
| Super Admin: global access, unrestricted | **WORKING** | `TenantIsolationMiddleware` grants Super Admin an unrestricted `?account_id=` override; `AccountController::index/show/store/update` all branch on `is_super_admin` with no account-tree restriction. |
| Super Admin can create Agent/Admin/Client/Client+Admin directly | **WORKING** | `AccountController::store()` trusts a Super-Admin caller's submitted `account_type`/`agent_id` values as-is (incl. `agent_id: null` for a direct client); assigns `admin` role always, `agent` role additionally when `account_type === 'agent'`. |
| Direct-created clients show NO agent ownership | **WORKING** | `agent_id` is nullable with no default-assignment logic anywhere in `store()`; a Super-Admin-created client with no `agent_id` submitted stays `null`. |
| Listings show Agent-vs-direct ownership | **WORKING** | `AccountsPage.tsx` has an `isAgentViewer` flag and a "Filter by Agent" dropdown (frontend); `AccountController::index()` accepts `?agent_id=`/`?account_type=` filters for Super Admin. |
| Agent: created by Super Admin, manages own Clients | **WORKING** | Same `store()` path; `Account::agent()`/`subClients()` relations define the tree. |
| Agent isolated from other Agents' clients | **WORKING** | `AccountController::callerAgentScopeId()` + `assertCallerCanAccessAccount()` (404, not 403, on cross-agent access — deliberately non-disclosing) enforced in `index/show/update`. |
| Agent can create Admin for its Clients per existing permission model | **WORKING** | Agent-caller branch of `store()` force-sets `account_type='client'` and `agent_id=<caller's own id>` regardless of submitted values — cannot be bypassed by a crafted request. |
| Agent cannot create another Agent | **WORKING** | Same force-set logic — an Agent caller can never produce `account_type='agent'`; only a Super-Admin caller's trusted branch can. |
| Agent only accesses modules explicitly granted by Super Admin, sidebar + backend | **WORKING** | `Account::effectiveModules()` (one-hop hierarchical cap) + `AccountService::resolveDelegatedModules()` (write-time cap, an Agent cannot grant a Sub-Client a module it doesn't itself hold) + `EnsureModuleEnabledMiddleware` (`module.guard:<slug>`, backend) + `AuthContext.hasModule()`/`AppLayout.tsx` `requiresModule` (frontend sidebar). Both layers present for every gated module found (`contact_groups`, `chatbot`, `meta_ads`, `social_accounts`, `message_logs`). |
| Admin: belongs to a Client/account, manages Users/marketing per assigned permissions | **WORKING** | `TeamController::MANAGED_PERMISSIONS` (direct per-user Spatie permission grants, not role edits) — `whatsapp.view/create/edit/delete`, `social_ads.view/launch/edit_budget/delete_rules`. Gated to "Only the Client Admin" (`TeamController::permissions()`/`updatePermissions()` explicit role check, lines ~358, ~392). |
| Sidebar/backend both restrict absent permissions for Admin-managed Users | **PARTIALLY IMPLEMENTED** | Backend grant mechanism exists (`givePermissionTo`), but this audit did not find where `whatsapp.view/create/edit/delete` are actually **checked** as route middleware (they don't appear in `routes/api.php`'s `permission:` list — only in `TeamController::MANAGED_PERMISSIONS` and the seeder's `PERMISSIONS` catalog). One entry, `social_ads.delete_rules`, is explicitly disclosed in-code as having **no backend action at all** (`TeamController.php` comment, line ~39). **Needs targeted verification**: confirm whether `whatsapp.*` granular permissions gate any live route, or are UI-only at present. |
| User/Marketing: existing behavior preserved | **UNKNOWN — NEEDS RUNTIME VERIFICATION** | Not in scope of files read this session; no evidence of regression found, but not exhaustively checked either. |

**Section verdict: WORKING**, with one PARTIALLY IMPLEMENTED item (granular per-user permission enforcement for Admin→User delegation) flagged for a follow-up read of the `whatsapp.*` permission checks, and one UNKNOWN item that needs nothing more than confirmation, not a fix.

---

## 3. AGENT→CLIENT OWNERSHIP AUDIT

Worked example from the request (Agent A / Clients A1–A3, Agent B / Clients B1–B2) maps directly onto the mechanism above:

- Ownership is a single FK: `accounts.agent_id → accounts.id`, with a **real DB foreign key** (`ON DELETE CASCADE`, confirmed in `wa_saas_platform.sql` — this contradicts an in-code comment elsewhere claiming no FK exists; the FK is real).
- `AccountController::index()`: a Super Admin caller sees everything, optionally filtered by `?agent_id=`; an Agent caller is **forced** through `Account::scopeOwnedByAgent($callerAgentId)` regardless of query params — cannot widen their own view by request tampering.
- `AccountController::show()`/`update()`: `assertCallerCanAccessAccount()` 404s (not 403s) on any cross-agent id, including client ids belonging to a *different* agent — Agent B genuinely cannot enumerate or distinguish "doesn't exist" from "not yours."
- Update guard: `AccountController::update()` blocks a Super Admin from demoting an Agent to `client` while it still has Sub-Clients — prevents an orphaned-`agent_id` state.

**Classification: WORKING.** No gap found. Do not rebuild.

---

## 4. TEMPLATE REQUEST OWNERSHIP & APPROVAL AUDIT

This is the strongest-built part of the codebase relative to the spec.

- **Ownership is derived, never duplicated**: `message_templates` has no `agent_id` column. `MessageTemplate::$with = ['account:id,company_name,agent_id,account_type']` eager-loads exactly the columns needed to derive ownership through `account.agent_id` at read time — confirmed by reading the model in full.
- **Scoping**: `MessageTemplateController::index()` — an Agent sees `where('account_id', $agentAccount->id) orWhereHas('account', fn agent_id = $agentAccount->id)` — own templates plus every sub-client's, nothing else.
- **Cross-agent block**: `assertAgentOwnsTemplate()` — 404 on any template whose owning account isn't the caller's own or a sub-client's. Applied consistently across `store/update/approve/reject`.
- **Approval authorization**: Agent's `approve()`/`reject()` branch requires both ownership *and* `status === 'pending_agent_review'`; an Agent approval **never** sets `status='approved'` directly — it always routes through `TemplateService::routeAfterAgentApproval()`, which forwards to a mandatory Super-Admin final review (`pending_meta_approval`). Agent A literally cannot unilaterally approve even its own client's template into final `approved` state — only Super Admin (or Meta, upstream) does that.
- **Isolation in notifications too**: `TemplateService::notifyPendingReview()` notifies the specific parent Agent's account owner plus all super_admins — not a broadcast to all agents.
- **Destructive/global-device actions excluded from Agent**: `destroy()` and the Super-Admin-only `/admin/templates/{id}/test` (which fires through `Account::platformDevice()`, Super Admin's own WhatsApp connection) are both explicitly Super-Admin-gated; an Agent cannot delete a template or spend a send on the platform device.
- Worked-example check: Agent A's Client A1 template → `account_id = A1.id`, `A1.agent_id = AgentA.id` → visible only via Agent A's `orWhereHas` clause or Super Admin's unrestricted view. Agent B's identical query never matches A1's row. **Confirmed correct by direct code trace, not assumption.**

**Classification: WORKING.** Answers to the audit's explicit ownership questions: ownership is **derived via relationship** (not a stored `agent_id` column on the template); queries are **properly scoped**; policies **are enforced** at every mutating endpoint; frontend and backend **do match** (no separate frontend-only gate found); **no global query leak** found in this controller.

---

## 5. RBAC/SIDEBAR PERMISSION AUDIT

- **Frontend**: `AuthContext.hasModule()` — Super Admin always `true`; else checks `account.effective_modules` (falls back to raw `allowed_modules`). `AppLayout.tsx` `NAV_ITEMS` carries both a `permission` and (where relevant) a `requiresModule` field per item; `isNavItemVisible()` checks both. Confirmed for `Contact Groups`: `{ permission: 'send-messages', requiresModule: 'contact_groups' }`.
- **Backend mirror**: `EnsureModuleEnabledMiddleware` (`module.guard:<slug>`) applied to the same modules the sidebar gates: `contact_groups` (routes ~537), `chatbot` (~319), `meta_ads` (~373), `social_accounts` (~440), and the newer `message_logs`/`analytics` slugs (~609). Super Admin bypasses this middleware by design (has every module).
- **Not module-gated at all, only permission-gated**: `/api/analytics/summary` and `/api/analytics/charts` — see §7 for why this matters. This is a real asymmetry: the sidebar's `hasModule('contact_groups')` check has no backend equivalent on these two specific endpoints.
- **Granular per-user matrix** (`TeamController::MANAGED_PERMISSIONS`): direct Spatie permission grants scoped to one user id (not a role edit) — correctly avoids the global-role-mutation trap that `RoleController` falls into (§11). One item (`social_ads.delete_rules`) is disclosed dead — no backend action consumes it.
- **RolePermissionSeeder role catalog** confirmed by direct read: `super_admin` = all permissions; `admin` = 15 permissions incl. `manage-roles` (see §11 — this is the escalation vector); `user` = 3 permissions (`send-messages`, `view-analytics`, `view-audit-logs`); `agent` = exactly 2 (`manage-accounts`, `manage-templates`) — deliberately minimal because an Agent's primary user is *additionally* assigned `admin` at creation time, which is where it actually gets `view-analytics`, `manage-team`, etc.

**Classification: WORKING** for the sidebar/module-guard pairing pattern itself (this is a well-designed, consistently-applied mechanism). **INCORRECT** for the two Analytics endpoints specifically (module-guard omitted where the response payload needs it) — see §7/§16. **PARTIALLY IMPLEMENTED** for the Admin→User granular permission layer pending the `whatsapp.*` route-enforcement check flagged in §2.

---

## 6. GROUP MODULE PERMISSION AUDIT

- **Cascade mechanics**: `contact_groups` is a normal entry in `Account::MODULES`. Super Admin → Agent delegation is capped by `Account::effectiveModules()` (one-hop hierarchical cap: an Agent can only ever have a module Super Admin gave it) and enforced at write-time by `AccountService::resolveDelegatedModules()` when an Agent tries to grant it further down to a Sub-Client/Admin. This is the same generic mechanism every other module uses — there is no group-specific special case, which is appropriate.
- **Route-level enforcement**: `ContactGroupController`'s entire route group (`routes/api.php` ~537) is wrapped in `['permission:send-messages', 'module.guard:contact_groups']` — genuinely server-side, not frontend-only, satisfying the request's explicit "do not implement frontend-only permission hiding" requirement for the group CRUD/send surface itself.
- **Sidebar**: confirmed above (`requiresModule: 'contact_groups'` in `AppLayout.tsx`).
- **Dashboard UI cards**: confirmed hidden behind `hasModule('contact_groups')` in `DashboardPage.tsx` (two tiles, ~lines 864 and ~902), with an explicit in-code comment confirming this is deliberate ("a tenant without it sees no group-shaped KPI cards at all rather than a permanent '0'").
- **The gap**: the *data* those cards would render (`recipient_breakdown`, `today_breakdown`, `active_contact_groups`) is present in the `/api/analytics/summary` JSON response **regardless of module entitlement**, because that endpoint's route middleware is `permission:view-analytics` only — no `module.guard:contact_groups`. A tenant without the group module never sees the cards (frontend correctly hides them) but **does receive the underlying group counts in the API payload** if they inspect network traffic or if a future UI change forgets the `hasModule()` wrapper. This directly contradicts the request's explicit requirement: "group counts must not be exposed via API responses unnecessarily."
- Group action buttons: not independently re-verified this session beyond the route-level gate above (which is the effective enforcement point) — no evidence of a frontend-only button gate found.

**Classification: PARTIALLY IMPLEMENTED.** Route-level CRUD/send actions: **WORKING**. Sidebar + Dashboard-card visibility: **WORKING**. Analytics API data exposure: **INCORRECT** (module-guard missing on `/api/analytics/summary` and `/api/analytics/charts`).

---

## 7. DASHBOARD GROUP STATISTICS AUDIT

Mapping the five explicitly required KPI names to what actually exists in `AnalyticsController::summary()` (all in one method, lines 35–200):

| Required card | Backend field | Status |
|---|---|---|
| Total Groups Added | `active_contact_groups` (`ContactGroup::where('account_id', $account->id)->count()`, line ~199) | **PARTIALLY IMPLEMENTED** — this is a *current* group count, not a cumulative "added" counter; functionally adequate as a proxy but not literally the same metric, and not module-gated (see §6). |
| Total Group Messages | `recipient_breakdown.group.sent` (+ `.failed` separately) via `recipientTypeBreakdown()`, line ~452 | **WORKING** (data correctness) / **INCORRECT** (exposure — not module-gated). This is the ONE place in the file that correctly sums `success_count`/`failure_count` for group rows instead of counting batch-rows. |
| Total Failed Group Messages | `recipient_breakdown.group.failed` | Same as above. |
| Today's Group Messages | `today_breakdown.today_group_sent` via a second call to `recipientTypeBreakdown()` scoped to today, line ~155 | **WORKING** (data correctness) / **INCORRECT** (exposure). |
| Today's Failed Group Messages | `today_breakdown.today_group_failed` | Same as above. |

**Root of the confusion the user is seeing**: `DashboardPage.tsx`'s top-line "Messages Sent" / "Messages Failed" / "Sent Today" tiles read `summary.total_sent` / `total_failed` / `total_sent_today` / `total_failed_today` — **not** `recipient_breakdown` — and those four top-line fields are computed by a *different, older* aggregate in the same method (line ~78–96 and ~137–145) that counts **rows**, not recipients:

```
SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent
```

A group send writes exactly one `message_dispatch_logs` row per batch regardless of recipient count (`MessageDispatchLog::recordGroupDispatchQueued()`/`resolveGroupDispatch()`). So a 10-recipient group send: `total_sent` (top-line KPI, what the user is looking at) = **1**; `recipient_breakdown.group.sent` (the newer, correct field, currently unused by the top-line tiles) = **10** (or however many actually succeeded). Both numbers are in the same API response at the same time — the correct number already exists, it's just wired to a field the Dashboard's main counters don't read.

**Classification: PARTIALLY IMPLEMENTED / INCORRECT.** The five required metrics are computable from data that already exists; the specific field names don't literally match the spec; and the two group-message metrics that ARE computed correctly are not the ones driving the visible top-line counters, and are not module-gated.

---

## 8. ANALYTICS GROUP STATISTICS AUDIT

Same underlying data as §7, viewed from the Analytics page rather than the Dashboard:

- `AnalyticsController::charts()` → `daily_by_recipient_type` (via `dailyRecipientTypeSeries()`, line ~508) is the chart-level equivalent of `recipient_breakdown` — correctly `success_count`/`failure_count`-aware for group rows, gap-filled per day. **WORKING** on correctness.
- The **primary** `daily` series in the same response (line ~283, feeds the main "Message Pulse" chart) uses the same row-counting `SUM(CASE WHEN status='sent'...)` as the Dashboard's top-line KPIs — **this is the literal graph the user described** ("sending 10+ messages... still showing in graph... count is 1"). Confirmed: the *chart line itself*, not just a card, undercounts group sends.
- Neither `charts()` nor `summary()` filters `recipient_breakdown`/`daily_by_recipient_type` by `hasModuleEnabled('contact_groups')` — same exposure gap as §6/§7, now confirmed for the chart data too.
- `globalSummary()` (Super Admin platform-wide KPIs, line 616–774): **contains zero group-specific fields of any kind** — no "Total Groups Added," no group message counts, global or otherwise. Its `total_messages_sent`/`total_messages_failed` use the same row-counting aggregate as the tenant-scoped `summary()`, so the Super-Admin-wide dashboard undercounts group sends across the entire platform, not just per-tenant.

**Classification: INCORRECT** (data-flow bug, confirmed in the primary chart series, not just a card) combined with **MISSING** (no group stats at all in the Super Admin global view).

---

## 9. AGENT-WISE ANALYTICS FILTER AUDIT

Exhaustive grep across `AnalyticsController.php`, `MessageDispatchLogController.php`, `MessageLogController.php`, `ContactGroupController.php`, and every file in `app/Services/Groups/*.php` for `agent_id`, `agent_scope_id`, `ownedByAgent`, `callerAgentScopeId`: **zero matches in every one of these files.**

- `AnalyticsController::resolveAccount()` (via the shared `ResolvesTenantAccount` trait) reads only `$request->attributes->get('account_id')` — the single account `TenantIsolationMiddleware` resolved for this request (the caller's own account, or one sub-client an Agent has explicitly switched into via `?account_id=`, or Super Admin's unrestricted override). There is no code path that aggregates "this Agent's own account plus every one of its sub-clients" into one Analytics response.
- Practical consequence: an Agent's Dashboard/Analytics view shows either (a) the Agent's own account's numbers only, or (b) one specific Sub-Client's numbers if they've switched into it — **never** a rolled-up "all my clients combined" figure. The user's own description ("agent wise admin message can view agent and super admin can see the all agent and admin message") is **not currently implemented** for messages/analytics/logs/groups — it IS implemented for Account management itself (`AccountController::index` `ownedByAgent()` scope), which is the one place an Agent-wide aggregate view already exists.

**Classification: MISSING.** This is a genuine feature gap, not a bug — the scoping infrastructure to build it (`callerAgentScopeId()`, `ownedByAgent()`) already exists and is proven correct elsewhere; it has simply never been wired into these four controllers.

---

## 10. SUPER ADMIN GLOBAL FILTER AUDIT

- `AccountController::index()` supports `?agent_id=`/`?account_type=` filtering for Super Admin — **WORKING**, and it's the one place this capability exists.
- `AnalyticsController::globalSummary()` takes **no query parameters at all** (confirmed: the method signature and its single cache key `'analytics_global_summary'` are both static/unparameterized) — Super Admin cannot filter platform analytics by Agent or by Client from this endpoint. The only way to see one Client's numbers is `summary()`/`charts()` with `?account_id=<client>` — a single-tenant view, not a per-Agent rollup.
- No endpoint anywhere computes "Agent A's total messages across A1+A2+A3 combined" for Super Admin's benefit either — same gap as §9, viewed from the other direction.

**Classification: MISSING**, consistent with §9 — this is one gap (no Agent-level rollup), not two, observed from both the Agent's and the Super Admin's side.

---

## 11. MULTI-TENANT ISOLATION AUDIT

Surveyed all 39 controllers in `app/Http/Controllers/Api/` for tenant-scoping usage. Findings beyond what's already covered above:

- **`RoleController` — CRITICAL, confirmed cross-tenant privilege escalation path.** `PUT /api/roles/{id}` and `POST /api/roles` are gated by `permission:manage-roles` only (`routes/api.php` line 236) — no tenant scope, no Super-Admin-only check (contrast with `AdminUserController::changePassword`, which explicitly adds `isSuperAdmin()` defense-in-depth on top of its permission gate, precisely because its blast radius is platform-wide — the same reasoning was not applied here). Spatie roles in this schema are **not** per-tenant (confirmed by the codebase's own `TeamController.php` docblock, which explicitly warns: editing role `admin` here would silently change permissions for every account using it). `manage-roles` is one of the 15 permissions granted to the **default `admin` role** (`RolePermissionSeeder::DEFAULT_ROLES['admin']`) — meaning **every Client Admin, on every tenant in the system, already holds this permission by default.** Any Client Admin can call `PUT /api/roles/{id}` targeting the shared `admin` or `user` role id and grant themselves (and every other tenant's Admins/Users) additional permissions platform-wide, or strip permissions from every other tenant's Admins. Only the literal `super_admin` role name is protected (`RoleController::update()` hardcodes a check against `$role->name === 'super_admin'`); no other role, including the very `admin` role that grants this ability, is protected from being rewritten by a holder of that same role.
- `AdminUserController::changePassword` — intentionally global/unscoped (Super Admin overriding any user's password), correctly double-gated (`permission:manage-accounts` route middleware + explicit `isSuperAdmin()` check). **WORKING as designed**, not a gap.
- `ActivityLogController` — intentionally global/unscoped, but reachable only via `view-activity-logs`, a permission granted **exclusively** to `super_admin` in the seeder (confirmed: no other role's array lists it). **WORKING as designed.**
- `BillingController::clientSummary()` — explicit `abort_unless(is_super_admin)` before any query; when Super Admin passes `?account_id=`, correctly narrows via `resolveAccount()`. **WORKING.**
- `QuotaRequestController` — has its own `callerAgentScopeId()` mirroring `AccountController`'s pattern; `store()` uses `requireAccount()`. Scoping mechanism present and consistent with the audited-correct pattern elsewhere. **WORKING** (not independently traced to the same exhaustive depth as AccountController/MessageTemplateController — flagged as lower-confidence WORKING, not UNKNOWN, since the pattern match is strong).
- `MetaWebhookController`, `PaymentWebhookController`, `SocialWebhookController` — zero tenant-attribute references, but these are inbound webhook endpoints authenticated by signature verification rather than a logged-in user's `account_id`; account is resolved from the webhook payload itself, not `TenantIsolationMiddleware`. Not a gap by the same logic that makes `ActivityLogController` fine — **not independently re-verified this session**, carried forward as **UNKNOWN — NEEDS RUNTIME/CODE VERIFICATION** rather than asserted WORKING, since webhook signature-to-account binding wasn't re-read this turn.
- `TenantIsolationMiddleware`'s Agent "client-switcher" (`?account_id=` restricted to `agent_id === scope || id === scope`) is correctly narrower than Super Admin's unrestricted override — re-confirmed, no change from prior findings.
- Everything else surveyed (`AdCampaignController`, `ContactGroupController`, `ChatbotRuleController`, `WhatsAppFlowController`, `TeamController`, `WebhookSubscriptionController`, `SocialInboxController`, etc.) shows consistent `ResolvesTenantAccount`/`forAccount()` usage at a ratio that matches the already-audited-correct controllers — **not each individually traced to MessageTemplateController's depth**, so classified **WORKING (pattern-consistent)** rather than exhaustively proven; none showed the "zero tenant references + live query" red-flag pattern that `RoleController` did.

**Classification: INCORRECT** — one confirmed, serious, previously-unreported cross-tenant privilege-escalation gap (`RoleController`). Everything else surveyed is **WORKING** or **WORKING (pattern-consistent)**, with webhook controllers carried as **UNKNOWN** pending a dedicated read.

---

## 12. SERVER→API→FRONTEND GRAPH DATA FLOW AUDIT

Full trace, per the explicit requirement not to assume a frontend bug:

1. **Database**: `message_dispatch_logs` — one row per individual send, **one row per group batch** (not per recipient). `success_count`/`failure_count` columns exist and are populated correctly for group rows (`MessageDispatchLog::resolveGroupDispatch(int $successCount, int $failureCount)`). Schema confirmed current in `wa_saas_platform.sql`.
2. **Model**: `MessageDispatchLog` — correct at this layer; `recordGroupDispatchQueued()` writes the queued placeholder row, `resolveGroupDispatch()` fills in true counts on completion. No bug here.
3. **Service/Controller (`AnalyticsController`)** — **this is where the bug lives**. Two parallel aggregation paths exist in the same file:
   - **Row-counting path** (bug): `summary()`'s `total_sent`/`total_failed`/`total_sent_today`/`total_failed_today` (lines ~78–96, ~137–145) and `charts()`'s primary `daily` series (line ~283) — all use `SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END)` against the raw table, uniform across `recipient_type`. A 10-recipient group batch = 1 row = counts as 1.
   - **Recipient-aware path** (correct, added later, under-wired): `recipientTypeBreakdown()` (line 452) and `dailyRecipientTypeSeries()` (line 508) — both correctly branch on `recipient_type`, and for `group` rows sum `success_count`/`failure_count` instead of counting rows. Feeds `recipient_breakdown`, `today_breakdown`, `daily_by_recipient_type` — fields that exist in the same JSON response but are not what the Dashboard's primary counters/chart line read.
4. **API/JSON response**: both the correct and incorrect numbers are present simultaneously in one response (`/api/analytics/summary` and `/api/analytics/charts`). This is not a serialization bug — the payload is internally inconsistent by design gap, not by transmission error.
5. **React service layer** (`analyticsService.ts`, prior-turn read): passes the response through unmodified — no bug here.
6. **React state/component (`DashboardPage.tsx`)**: reads `summary.total_sent_today`, `summary.total_sent`/`total_failed` for its main KPI tiles, and `charts.daily` (via the chart component) for the graph line — i.e., it is wired to the **row-counting** fields, not the recipient-aware ones. This is a legitimate, defensible default (they're the "obviously named" fields) — the frontend did not introduce the bug, it consumed the wrong (but reasonably-named) field from an API that offers two different answers to the same question.

**Root cause, single sentence**: `AnalyticsController`'s primary aggregation functions were never updated to be `recipient_type`-aware when group messaging's batch-row storage model was introduced; a parallel, correct aggregation was added alongside it for the newer `recipient_breakdown` feature instead of being used to fix the original fields, leaving two different truths in one API response.

**Classification: INCORRECT**, root-caused to the backend controller layer exactly as required, with the specific functions and line ranges identified. No frontend chart-transformation bug found — the frontend faithfully renders the number the backend labeled as "the" total.

---

## 13. DATABASE/QUERY AUDIT

- `accounts.account_type` (enum) + `accounts.agent_id` (self-referencing FK, `ON DELETE CASCADE`, confirmed real in the dump) — correctly modeled.
- `message_dispatch_logs` has `recipient_type`, `group_id`, `group_name`, `recipient_count`, `success_count`, `failure_count` — schema is current and sufficient to fix §12 without any migration. **No schema change is needed for the primary fix** — only the aggregation query logic.
- `message_templates` has no `agent_id` column (by design, ownership is derived — see §4). Confirmed correct, not a gap.
- Spatie `roles`/`permissions`/`model_has_roles`/`model_has_permissions` tables are the stock package schema — global by nature, which is precisely why `RoleController`'s lack of scoping (§11) is a real bug rather than a misreading of the schema: there is no tenant column to have missed adding a `where` clause for. Fixing §11 is an authorization-logic problem, not a schema problem.
- `contact_groups` table present and current (used by `active_contact_groups` count and `ContactGroupController`).
- Two parallel logging tables remain (`payment_alerts` and `message_dispatch_logs`) — already-known, disclosed-in-code architecture from prior analysis, not re-litigated here; `AnalyticsController` correctly self-heals between them via `Schema::hasTable()` guards.

**Classification: WORKING** (schema is sound and current); the bugs found in this audit are query/aggregation-logic bugs and an authorization-scoping bug, not schema defects.

---

## 14. BUGS/GAPS FOUND (summary list — full detail and fixes in §16)

1. **[INCORRECT]** `AnalyticsController::summary()`/`charts()` primary KPI/chart fields count `message_dispatch_logs` rows instead of recipients for group sends — the reported "10+ messages, count/graph shows 1" bug. Confirmed in both the Dashboard's top-line counters and the Analytics page's main chart line, and in Super Admin's `globalSummary()` too.
2. **[INCORRECT]** `/api/analytics/summary` and `/api/analytics/charts` lack `module.guard:contact_groups`; group-derived fields (`recipient_breakdown`, `today_breakdown`, `active_contact_groups`, `daily_by_recipient_type`) are computed and returned regardless of module entitlement.
3. **[INCORRECT — CRITICAL]** `RoleController::store()/update()` allow any holder of `manage-roles` (which includes every default Client Admin) to rewrite a shared, non-tenant-scoped role's permissions, affecting every other tenant using that role. Only the literal `super_admin` role name is protected.
4. **[MISSING]** No Agent-level data rollup across sub-clients anywhere in Analytics, Message Logs, or Group Messaging (`AnalyticsController`, `MessageDispatchLogController`, `MessageLogController`, `ContactGroupController`, `app/Services/Groups/*`) — confirmed via exhaustive grep, zero `agent_id`/`ownedByAgent` references.
5. **[MISSING]** No Super-Admin Agent-wise or Client-wise filter in Analytics (`globalSummary()` takes no parameters at all); this capability exists only in `AccountController::index()` for account management, not for messages/analytics/groups.
6. **[PARTIALLY IMPLEMENTED]** The five named Group Dashboard/Analytics KPI cards exist under different field names, are correct at the `recipient_breakdown`/`today_breakdown` layer, but "Total Groups Added" is really "current group count" (not cumulative), and none of the five are module-gated (ties to #2).
7. **[PARTIALLY IMPLEMENTED / UNKNOWN]** Admin→User granular `whatsapp.*` permission grants (`TeamController::MANAGED_PERMISSIONS`) do not appear to gate any live route in `routes/api.php` — needs confirmation whether these are enforced anywhere server-side or are currently UI/data-model-only. One entry (`social_ads.delete_rules`) is disclosed as having no backend action at all.
8. **[UNKNOWN]** Webhook controllers' (`MetaWebhookController`, `PaymentWebhookController`, `SocialWebhookController`) account-binding via signature verification was not re-read this session — carried forward as needing a dedicated check, not asserted safe or unsafe.

---

## 15. WORKING FEATURES THAT MUST NOT BE TOUCHED

- The entire Agent→Client hierarchy: creation, isolation, module-delegation cap (`AccountController`, `AccountService::resolveDelegatedModules()`, `Account::effectiveModules()`).
- The entire Template ownership/approval workflow (`MessageTemplateController`, `TemplateService`) — derived ownership, cross-agent 404 isolation, mandatory Super-Admin final review step, notification scoping.
- The sidebar+backend module-guard pairing pattern itself (`hasModule()`/`requiresModule`/`module.guard:<slug>`) — correct wherever it's actually applied; the gap is two specific missing applications (#2 above), not the pattern.
- `ContactGroupController`'s route-level permission+module gating and per-account scoping.
- `BillingController::clientSummary()`, `AdminUserController::changePassword`, `ActivityLogController` — all correctly Super-Admin-restricted by design.
- The `recipientTypeBreakdown()`/`dailyRecipientTypeSeries()` aggregation logic itself — this is the CORRECT implementation and should be reused (not rebuilt) to fix the primary KPI fields in §16.
- Database schema for `accounts`, `message_dispatch_logs`, `message_templates`, `contact_groups` — current and sufficient for every fix identified above; no migration required.

---

## 16. REQUIRED FIXES — PRIORITIZED

### Fix 1 — Group message count/graph undercounting (the reported bug)
- **Priority**: P0
- **Module**: Analytics
- **Exact file(s)**: `backend-api/app/Http/Controllers/Api/AnalyticsController.php` — `summary()` (lines ~78–96 total_sent/total_failed aggregate, ~137–145 today aggregate), `charts()` (line ~283 daily series aggregate), `computeGlobalSummary()` (line ~648 total_sent/total_failed aggregate)
- **Current behavior**: Counts `message_dispatch_logs` rows via `SUM(CASE WHEN status='sent' THEN 1 ELSE 0)`; a group batch row (any recipient count) contributes exactly 1.
- **Expected behavior**: For `recipient_type='individual'` rows, count by status (unchanged, already correct). For `recipient_type='group'` rows, sum `success_count`/`failure_count` instead of counting rows — exactly the logic `recipientTypeBreakdown()` already implements correctly.
- **Root cause**: Primary aggregation queries were never updated to be `recipient_type`-aware when the batch-row group storage model was introduced; the correct logic was built separately for `recipient_breakdown` instead of being applied here.
- **Minimal required change**: Replace the raw `SUM(CASE...)` aggregate in each of the four locations with a `recipient_type`-branching sum (individual: row-count by status; group: `SUM(success_count)`/`SUM(failure_count)`), reusing the existing `$resolutionCountsAvailable` guard already present in the file for backward compatibility with pre-migration deployments.
- **Dependencies**: None — schema already supports this (§13).
- **Risk**: Low if implemented as a query change only; must preserve the existing `Schema::hasTable()`/`Schema::hasColumn()` self-healing guards already in the file so older deployments don't break.
- **Verification method**: Send a group message to 10 recipients (5 succeed, 5 fail); confirm `total_sent`/`total_sent_today` increase by 5 (not 1) and `total_failed`/`total_failed_today` by 5; confirm the `daily` chart series for today's date shows `sent: 5, failed: 5` combined with any individual sends that day, not `sent: 1`.

### Fix 2 — Group analytics data exposed without module entitlement
- **Priority**: P1
- **Module**: Analytics / Group Messaging
- **Exact file(s)**: `backend-api/routes/api.php` (~line 575, the `analytics` route group)
- **Current behavior**: `/api/analytics/summary` and `/api/analytics/charts` gated only by `permission:view-analytics`; group-derived response fields computed unconditionally.
- **Expected behavior**: Group-derived fields (`recipient_breakdown`, `today_breakdown`, `active_contact_groups`, `daily_by_recipient_type`) must be `null`/absent for any account without the `contact_groups` module enabled, matching the frontend's existing `hasModule()` gate.
- **Root cause**: These fields were added under the existing `permission:view-analytics` route group without a corresponding `module.guard:contact_groups` check or in-controller `hasModuleEnabled()` check.
- **Minimal required change**: In `AnalyticsController::summary()`/`charts()`, gate the group-derived fields behind `$account?->hasModuleEnabled('contact_groups')` in addition to the existing table/column-availability guards (do not add `module.guard:contact_groups` to the whole route — that would also block `total_sent`/`total_failed`/individual-only data for accounts without the group module, which they're still entitled to see).
- **Dependencies**: None.
- **Risk**: Low — purely additive conditional, mirrors an existing pattern (`'quota' => null` for global scope) already used in the same file.
- **Verification method**: Call `/api/analytics/summary` as an account with `contact_groups` disabled; confirm `recipient_breakdown`/`today_breakdown`/`active_contact_groups`/`daily_by_recipient_type` are `null` in the raw JSON, not just hidden by the frontend.

### Fix 3 — Cross-tenant role-permission escalation
- **Priority**: P0
- **Module**: RBAC / Multi-tenant isolation
- **Exact file(s)**: `backend-api/app/Http/Controllers/Api/RoleController.php` (`store()`, `update()`), `backend-api/routes/api.php` (~line 236)
- **Current behavior**: Any user holding `manage-roles` (includes every default Client Admin, platform-wide) can create/rename roles and rewrite any role's permission set except the literal `super_admin` role name — with no tenant scope, since Spatie roles are global in this schema.
- **Expected behavior**: Either (a) restrict `RoleController::store/update` to Super Admin only (matching `AdminUserController`'s defense-in-depth pattern), if dynamic role creation is meant to be a Super-Admin-only platform capability, or (b) if Client Admins are meant to manage roles, introduce tenant-scoped roles (a schema change) so one tenant's role edit cannot affect another tenant. Given the disclosed global-role architecture and the existing precedent of `TeamController`'s per-user direct-permission grants avoiding this exact trap, **(a) is the minimal, non-breaking fix**.
- **Root cause**: `manage-roles` permission was granted to the default `admin` role (likely intended for a Client Admin to manage their own *team's* role assignments) without recognizing that Spatie roles are platform-global, not per-tenant, in this schema — so the permission unintentionally grants platform-wide role-editing power.
- **Minimal required change**: Add an explicit `abort_unless($request->attributes->get('is_super_admin'), 403, ...)` check at the top of `RoleController::store()` and `update()`, mirroring `AdminUserController::changePassword()`'s and `BillingController::clientSummary()`'s existing pattern. (A broader, non-minimal alternative — tenant-scoped roles — is a schema/architecture change and should be a separate decision, not bundled into this fix.)
- **Dependencies**: None for the minimal fix. Confirm with the user/product owner first whether any current legitimate workflow relies on a non-Super-Admin editing roles via this endpoint (e.g. does the frontend's Role Management UI need to also become Super-Admin-only, or already is).
- **Risk**: Medium — if any existing Client Admin workflow currently depends on `PUT /api/roles/{id}`, this fix would break it; needs a quick frontend check (which pages call this endpoint and who can reach them) before applying, not just a backend-only change.
- **Verification method**: As a Client Admin (non-Super-Admin) with `manage-roles`, attempt `PUT /api/roles/{admin-role-id}`; confirm 403. As Super Admin, confirm the same call still succeeds.

### Fix 4 — No Agent-level analytics/message/group rollup
- **Priority**: P2
- **Module**: Analytics / Multi-tenant isolation
- **Exact file(s)**: `backend-api/app/Http/Controllers/Api/AnalyticsController.php`, `MessageDispatchLogController.php`, `MessageLogController.php`, `ContactGroupController.php`
- **Current behavior**: No endpoint aggregates an Agent's own account plus its sub-clients into one view; Super Admin has no `?agent_id=` filter for these same endpoints.
- **Expected behavior**: Per the business scenario — Agent sees own+sub-client aggregate data; Super Admin can filter/aggregate by Agent.
- **Root cause**: These controllers were built before (or without reference to) the 3-tier hierarchy's `callerAgentScopeId()`/`ownedByAgent()` pattern that `AccountController`/`MessageTemplateController`/`QuotaRequestController` already use.
- **Minimal required change**: This is a genuine new feature, not a one-line fix — reuse the proven `callerAgentScopeId()` pattern to add an "include sub-clients" aggregation mode to each controller's account-resolution step, and a `?agent_id=` filter parameter for Super Admin. Scope and design should be its own implementation phase (see Implementation Plan below) rather than estimated here in audit-only terms.
- **Dependencies**: Should follow Fix 1 (fixing the underlying count logic first, so a new rollup feature isn't built on top of the same counting bug).
- **Risk**: Medium — touches four controllers; needs its own test plan per controller.
- **Verification method**: As Agent A, call the new rollup endpoint/parameter; confirm totals equal the sum of Agent A's own account plus A1+A2+A3, and exclude B1/B2 entirely.

### Fix 5 — Admin→User granular permission enforcement unclear
- **Priority**: P2
- **Module**: RBAC
- **Exact file(s)**: `backend-api/app/Http/Controllers/Api/TeamController.php`, `backend-api/routes/api.php`
- **Current behavior**: `whatsapp.view/create/edit/delete` and `social_ads.*` can be granted per-user via `TeamController::updatePermissions()`, but no route middleware referencing these exact permission strings was found in `routes/api.php`.
- **Expected behavior**: Each granted/revoked permission should visibly gate a specific route or UI action.
- **Root cause**: Unconfirmed without a dedicated read of every WhatsApp-related route's middleware list — this audit found the grant mechanism but not (yet) its consumption point, if one exists.
- **Minimal required change**: Not determinable without further investigation — **do not implement a fix until this is confirmed as a real gap**, per the audit's own instruction to distinguish "didn't find it quickly" from "confirmed missing." Recommend a targeted follow-up read of every `whatsapp/*` route's middleware before treating this as more than PARTIALLY IMPLEMENTED.
- **Dependencies**: N/A (investigation task, not a code fix, until confirmed).
- **Risk**: N/A.
- **Verification method**: Grep every `Route::` definition under a `whatsapp` prefix for a `permission:whatsapp.*` middleware entry; if none exists, confirm with the team whether this is intentional (permission stored for future use) or an oversight.

---

## IMPLEMENTATION PLAN (phase count only — prompts to follow separately, per your instruction)

**Recommended: 4 separate implementation phases/prompts**, split by blast radius and dependency order rather than by module, because two of the fixes (Fix 1 and Fix 3) are unrelated in code but both P0, and bundling unrelated P0s into one prompt makes review and rollback harder if one needs to be reverted independently:

1. **Phase 1 — Count/graph correctness fix** (Fix 1 alone). Small, isolated, purely query-logic, no schema change, no authorization change. Safe to ship first and verify against the exact bug you reported before touching anything else.
2. **Phase 2 — Role-escalation lockdown** (Fix 3 alone). Also small and isolated, but touches authorization rather than data — kept separate from Phase 1 so a review of "did this break any legitimate Admin workflow" isn't tangled up with the counting fix's own review.
3. **Phase 3 — Group-module data exposure gate** (Fix 2 alone, plus resolving Fix 5's open question as a quick investigation step within the same phase since it's the same "permission/module gating hygiene" theme and is low-effort to confirm one way or the other). Depends on Phase 1 being done first only in the sense that you'll be looking at the same fields Fix 1 touches — not a hard code dependency, just easier to reason about in sequence.
4. **Phase 4 — Agent-wise / Super-Admin-wise analytics rollup** (Fix 4). This is the only genuine new feature among the five — deliberately last and separate, since it's the largest scope, benefits from Phases 1–3 being settled first (no point building a rollup on top of a counting bug or an unfixed permission leak), and is the one most likely to need its own back-and-forth on exact API shape before implementation.

I have not written the phase prompts themselves, per your instruction — this is only the count and reasoning.
