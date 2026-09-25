# wa-saas-platform — Project State

> **Read this file first.** It is the single, continuously-updated description of what this
> project is, what is built, and what is pending. It is maintained at the end of every
> development task. If you were asked to "analyze the project", start here, then open the
> files it points at — do not reconstruct the picture from scratch.

| | |
|---|---|
| **Last updated** | 2026-09-25 |
| **Last completed work** | **Phase 8 Task 3 CLOSED — Credit Ledger, Reservation & Consumption**: `CreditConsumptionService` is the one spending contract (reserve · partial consume · settle · release · direct spend), account-bound (`tenant_mismatch` / `reservation_not_found`), every movement still a `CreditService` ledger operation under the credit-account lock; direct spend = a reservation created already consumed (a consumption always references a reservation); partial consumption keeps a reservation open until nothing remains; optional product gate (`CreditSpendGate`, AI = `CreditEntitlementService`) evaluated under the lock after the idempotency lookup; caller-set reservation `expires_at` + scheduled `credits:release-expired-reservations`; stable failure codes; verified on MariaDB 10.11.14 and MySQL 8.0.46 — see §5 "Credit spending (Phase 8 Task 3)" · **Phase 8 Task 2 CLOSED — Credit Plans, Entitlements & Limits**: `plans.included_credits` (explicit 0 for every existing plan — owner decision), captured on the invoice at order time, allocated once per paid period (`plan_allocation` ledger type, key `plan-allocation:{subscription}:{invoice}`, period row in `usage_quotas`) inside payment fulfilment; `ai` capability vs credits kept as two checks (`CreditEntitlementService`); plan API/UI edit the allowance; `credits:backfill-plan-allocation` (dry-run, repeat-safe); verified on MariaDB 10.11.14 AND MySQL 8.0.46 — see §5 "Credit plans (Phase 8 Task 2)" · **Phase 8 Task 1 CLOSED — Credit System Foundation**: per-account `credit_accounts` (integer balance/reserved), append-only `credit_ledger_entries`, `credit_reservations` (reserved → consumed \| released), `App\Services\Credits\CreditService` as the only writer (row-locked transaction per operation, DB-enforced idempotency), Super Admin grant/adjust/refund API, tenant read API; proven on MariaDB by `tests/Probes/credit_concurrency_probe.php`; plan integration deferred to Task 2 — see §5 "Credits (Phase 8 Task 1)" · **P5-8 CLOSED** — entitlement audit logging: every allow/deny decision at the entitlement boundaries (capability/module guards incl. API-key siblings, route permission refusals, Agent target-account refusals, Journey save/publish/activate node authorization, cross-tenant journey access, Journey runtime node / runtime-entitlement / send-gate decisions) recorded in the existing `activity_logs` trail via `EntitlementAuditLogger` (module "Entitlement Authorization", action_type `allowed`/`denied`, allow-listed payload, never throws) — see §4 and §8.1 P5-8 · **P5-6 + P5-7 CLOSED** — P5-6: `api`-node credential headers/query values encrypted at rest (`JourneySecrets`, Laravel `Crypt`), masked in every journey response and audit row, kept on update via the mask, refused in URLs · P5-7: backend runtime truth (`JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES`), publish/activate refused for non-executable or malformed graphs (422 `JOURNEY_NOT_PUBLISHABLE`, drafts still save), run-time node re-authorization for every catalog node, first-node permanent failure no longer consumes the message, `default` journeys step aside for specific chatbot rules, deterministic trigger selection, question sessions expire after 24 h (`reply_timeout`) — see §8.1 P5-6 / P5-7 · **P5-5 CLOSED** — payment fulfilment exactly-once, now also serialized per account (`InvoiceCreditService` locks the invoice owner's account row); proven on MariaDB by `tests/Probes/payment_fulfillment_concurrency_probe.php` · **Phase 7 CLOSED** (Task 10 release-readiness audit; 2 cross-task regressions fixed — see §8.1 Phase 7 closure record) · Phase 7 Task 9 — Journey production hardening: one session state machine (`WhatsAppFlowSession::TRANSITIONS`, terminal saves refused), guarded checkpoint, recovery of interrupted immediate runs (run lease + `recoverInterruptedRuns()` in `journeys:resume-due`), manual test serialized and replaces every open session, race-free restore events; MariaDB cross-process + deploy probes (`tests/Probes/`) · Phase 7 Task 8 — one quota/entitlement classification for every Journey send (`JourneySendGate`; quota exhausted/plan expired → retried `quota_failure`, suspended/no subscription → failed `entitlement_blocked`; node capability via `JourneyNodeAuthorizer::runtimeDenialFor`) · Phase 7 Task 7 — Journey observability: durable `journey_execution_events` history, failure categories, retry visibility, read-only `GET /whatsapp/flows/{id}/sessions/{sessionId}`, opt-in `journeys:prune-history` · Phase 7 Task 6 — palette `text`/`image`/`video`/`document`/`audio` nodes executed; one completion/terminal contract (`transition()` compare-and-set: ended sessions are never rewritten) · Phase 7 Task 5 — Journey action execution hardening (`JourneyActionConfig`, checkpoint per node, immediate-path failures retried via the Task 1 model) · Phase 7 Task 4 — Journey condition/branching hardening (`JourneyConditionEvaluator`; `conditional` node executed) · P5-4 — orders fulfilled from plan terms captured on the invoice at checkout · P5-3 — group jobs: atomic claim, failure settlement from recipient rows, bounded slices, stale-batch recovery · P5-2 — Social Inbox lead reply now goes through `DirectMessageDispatcher` (quota + dispatch log) · Phase 7 Task 3 — durable inbound idempotency (`inbound_message_events`) + per-conversation lease · Task 2 Journey versions · P5-1 · Tasks 1–1.6 |
| **Backend test suite** | **2055 passed, 0 failures** (MariaDB, authoritative) · 2048 + 7 skipped (SQLite) · MySQL 8.0.46: 2052 passed, 3 pre-existing JSON key-order failures (§8.4) · frontend 560 passed |
| **Migrations** | 123 (123rd = `2026_09_25_120000_add_credit_reservation_expiry` (Phase 8 T3, additive: nullable `credit_reservations.expires_at` + index; CHECK consumed ≤ amount on MySQL/MariaDB); 122nd = `2026_09_25_110000_add_plan_credit_allocation` (Phase 8 T2, additive: `plans.included_credits`, `invoices.plan_included_credits`, 4 columns on `usage_quotas`); 121st = `2026_09_25_100000_create_credit_system_tables` (Phase 8 T1, additive: 3 new tables); 120th = `2026_09_24_170000` journey execution events (Phase 7 T7); 119th = `2026_09_24_160000` invoice plan-terms snapshot (P5-4); 118th = `2026_09_24_150000` group-batch claim columns (P5-3); 117th = `2026_09_24_140000` inbound events + conversation locks; 115th/116th = journey versions + backfill; 114th = `group_dispatch_recipients`; 113th = `qr → journey_automation`; 112th = temporal columns; 111th = platform CRM account) — 111th–123rd **not yet run on the real DB** |
| **Next up** | **Phase 8 Task 4** (not started — not specified here; AI provider/usage/pricing remain unbuilt) · owner: set real `included_credits` values, then `credits:backfill-plan-allocation --dry-run` · remaining Phase 5 audit items (P5-9 … P5-11) and P6-1 … P6-3, then **Phase 8** (not yet specified). Phase 7 is closed in code; its production rollout (10 pending migrations, sequence in §8.1 Phase 7 closure record) is an owner action and has NOT been performed. Owner decisions open: (1) run migration 120 on the real DB; (2) whether to schedule `journeys:prune-history --force` (dry run by default, not scheduled). Owner decisions pending from Task 1 — see §8.1 "Journey (Phase 7)". CRM Notes are backlog (§8.4). |

---

## 1. What This Product Is

A **multi-tenant WhatsApp SaaS platform**. Tenants ("accounts") connect a WhatsApp number
through one of two engines, send templated and bulk messages, capture leads from Meta ads
and social channels, automate replies through a visual journey builder, and are billed
against plan quotas. A Super Admin tier and a reseller ("agent") tier sit above tenants,
with commission tracking for agents.

Three deployable services:

| Directory | Stack | Role |
|---|---|---|
| `backend-api/` | **Laravel 11**, PHP **8.4**, MariaDB **10.11.14**, Sanctum, spatie/laravel-permission | The entire API, business logic, billing, RBAC |
| `frontend-app/` | **React 19.2**, TypeScript 6.0, Vite 8.2, Tailwind 3.4, vitest, oxlint | Tenant + Super Admin web console |
| `qr-engine-service/` | Node, **Baileys 7.0.0-rc14**, Express 5.2, socket.io 4.8 | The QR/Baileys WhatsApp engine (separate process) |

---

## 2. The Two Numbering Schemes — read this before using the word "phase"

⚠️ **There are two different phase numberings in this repo and they do not match.**

1. **The execution track (LIVE — this is what the team actually works to).**
   Phase 1 Foundation → Phase 2/3 Developer API → Phase 4 Meta provider → Phase 5 Journey &
   plan management → Phase 6 CRM → Phase 7 Journey hardening → **Phase 8 AI / Credit**
   (current: Task 1 Credit System Foundation done). Tasks are issued as
   "Phase N Task M" and each is a self-contained spec.

2. **The architecture report's roadmap** in
   `Claude outputs/wa-saas-platform-architecture-report.md` §27 — Phases 0–10, where
   **Phase 5 = CRM and Phase 6 = AI Platform**. This is the long-range plan, still useful
   for *what is left to build*, but its **numbers are not the ones in use**.

When a task says "Phase 6", it means **CRM**; "Phase 8" means **AI / Credit** (the report's "Phase 6 AI Platform"). Section 8 below maps both.

---

## 3. Architecture — the part you must not break

### 3.1 Tenant isolation

`TenantIsolationMiddleware` resolves the request's `account_id`, `is_super_admin` and
`agent_scope_id` from the authenticated user, and every query is scoped through
`ResolvesTenantAccount::requireAccount()` + a model's `scopeForAccount()`.

**`account_id`, `agent_id` and `tenant_id` are never read from the request body or query
string.** Several test suites assert exactly that. Any new endpoint must follow it.

### 3.2 The authorization chain

Every protected route stacks these in order:

```
auth:sanctum
  → tenant.isolation      (who is this, which account)
  → subscription.guard    (is the subscription live)
  → module.guard:<slug>   (is the module enabled for this account)
  → permission:<name>     (spatie RBAC)
  → capability.guard:<slug> (is the account entitled to this capability)
```

Conceptually: **PLAN → ENTITLEMENT → CAPABILITY → PERMISSION → USAGE/QUOTA.**
Middleware aliases live in `bootstrap/app.php`.

The Developer API (`/api/v1`) has no user, so it uses API-key siblings of the same checks
(Task 11): `log.apirequest → auth.apikey → throttle:external-api → idempotency →
subscription.apikey → module.apikey:<slug> → capability.apikey:<slug>`. The `*.apikey`
guards read the key's account and **fail closed**; the UI guards cannot be reused there
(`module.guard` falls through without `account_id`, `subscription.guard` needs a user).

**Hierarchy (verified Task 11; CRM amended after Phase 6):** outside the CRM a Super Admin
must pass `?account_id=` and bypasses the module/capability guards by design. **Inside
`/api/crm/*`** (`crm.target`, `EnsureCrmTargetAccount`, owner decision): with no client selected
the Super Admin acts on their own CRM account — the single `accounts` row with
`account_type = 'super_admin'` ("Platform (Super Admin)", `PlatformCrmAccount`; created by the
2026_09_23_130000 data migration or `php artisan crm:platform-account`); `users.account_id` of
the Super Admin stays NULL. The **target** account (own or selected) must hold `lead_crm` +
`crm` — the Super Admin bypass no longer applies to CRM. Without a platform account and no
selection the CRM still answers 422. an Agent reaches only its own account or its own
sub-clients (`?account_id=`, else 404), and the sub-client's module/capability apply — but
`subscription.guard` checks the **actor's** own account (pre-existing, platform-wide); any
other user's `?account_id=` is ignored. An API key is bound to exactly one account; `/v1`
never reads `?account_id=`.

### 3.3 Capability / Provider / Plan model

Three independent dimensions, deliberately not bundled — seeded by
`database/seeders/Phase1FoundationSeeder.php`, which is idempotent.

**Capabilities (12):** `whatsapp_send`, `whatsapp_groups`, `crm`, `journey_automation`,
`ads`, `social`, `ai`, `commerce`, `payments`, `external_api`, `custom_code`, `email`.

**Providers (3):** `qr` (Baileys), `meta` (Cloud API), `none`.

**Provider support is a data row, not code.** `provider_capabilities` states every pairing
explicitly with a written reason saying whether a `false` is a technical limitation or a
business rule. Notable rows:

| Provider | Capability | Supported | Why |
|---|---|---|---|
| `qr` | `whatsapp_groups` | ✅ | Real Baileys feature |
| `meta` | `whatsapp_groups` | ❌ | **Technical** — Cloud API has no groups |
| `qr` | `crm` | ✅ | CRM is engine-agnostic |
| `qr` | `journey_automation` | ✅ | Engine-agnostic — **owner decision, Phase 7 Task 1.5** (was a business-tier ❌) |
| `qr` | `ads` | ❌ | Business rule + CTWA is Meta-webhook-native |
| `qr` | `commerce` | ❌ | **Technical** — Baileys driver has no catalog messages |

**Plans (3):**

| Slug | Price | Quota | Engine | Bundled capabilities |
|---|---|---|---|---|
| `starter` | ₹499 / 30d | 500 msg | qr | whatsapp_send, whatsapp_groups, social, external_api |
| `growth` | ₹1,999 / 30d | 2,500 msg | qr | + crm, journey_automation, ai, payments, email |
| `business` | ₹7,999 / 30d | 10,000 msg | meta | + ads, commerce, custom_code (no groups) |

**A plan entitlement is not provider support.** A plan may sell a capability its engine
cannot run; `InvoiceCreditService::grantPlanEntitlements()` skips the incompatible pairing at
grant time and the Super Admin panel shows it as *"In plan — provider unsupported"*. Since
Phase 7 Task 1.5 the confirmed matrix has no such cell left (`growth → crm` and
`growth → journey_automation` were both made QR-supported by owner decision); `ads`/`commerce`
remain QR refusals. Journey access by plan: **Growth ✅ · Business ✅ · Starter ❌** (manual grant possible).

### 3.4 Modules (17, server-side gated)

`analytics`, `billing`, `chatbot`, `comment_automation`, `contact_groups`, `developer_api`,
`lead_crm`, `message_logs`, `meta_ads`, `notifications`, `reports`, `send_alert`,
`social_accounts`, `social_inbox`, `team_management`, `whatsapp_setup`.

Module-off means **backend-blocked**, not merely UI-hidden.

---

## 4. Database

**123 migrations**, **61 Eloquent models**. Grouped by domain:

| Domain | Key tables |
|---|---|
| Tenancy & RBAC | `accounts`, `users`, `permission_tables`, `activity_logs`, `login_audit_logs`, `system_routes`, `route_categories` |
| Billing | `subscriptions`, `plans`, `plan_entitlements`, `account_entitlements`, `usage_quotas`, `invoices`, `payment_gateway_settings`, `quota_requests` |
| Agent/reseller | `agent_selling_entitlements`, `agent_commission_rules`, `agent_commissions`, `agent_commission_payouts`, `agent_commission_payout_items` |
| Entitlement core | `capabilities`, `providers`, `provider_capabilities` |
| WhatsApp | `whatsapp_sessions`, `message_templates`, `message_dispatch_logs`, `group_dispatch_recipients` (P5-1), `whatsapp_flows`, `whatsapp_flow_versions` (Phase 7 T2), `whatsapp_flow_sessions`, `inbound_message_events` + `journey_conversation_locks` (Phase 7 T3), `journey_execution_events` (Phase 7 T7, append-only) |
| Contacts & groups | `contact_groups`, `contact_group_members` |
| **CRM** | `contacts`, `crm_leads`, `crm_capture_link_failures`, `crm_tags`, `crm_lead_tags` |
| Social & ads | `social_accounts`, `social_provider_configs`, `leads`, `ad_campaigns`, `ad_campaign_daily_metrics`, `organic_posts`, `comment_automation_rules`, `comment_automation_events` |
| Developer API | `api_keys`, `api_request_logs`, `api_idempotency_keys`, `webhook_subscriptions`, `webhook_deliveries` |
| **Credits** (Phase 8 T1, T3) | `credit_accounts`, `credit_ledger_entries` (append-only), `credit_reservations` (T3: `expires_at`, partial `consumed_amount`) |
| Chatbot & notifications | `chatbot_rules`, `chatbot_logs`, `notification_templates`, `notification_broadcasts`, `in_app_notifications`, `mail_settings`, `mail_logs` |

**Schema invariants that must not be violated:**

- `crm_leads.status` is the **only** CRM status field. No `pipeline_status`, `pipeline_stage`,
  `kanban_status`, `custom_status` or `stage` column exists — re-verified each CRM task.
- `crm_leads.assigned_user_id` is the **only** ownership field. No `owner_id`/`sales_owner_id`.
- **Tags are metadata, not status.** They live only in `crm_tags` / `crm_lead_tags`; every
  assignment row's `account_id` is bound to both the lead and the tag by composite FKs, so a
  cross-tenant pairing is unstorable. Tag names are unique per account case-insensitively
  (`normalized_name`, binary collation).
- **`whatsapp_flow_sessions` is the journey run state** (Phase 7 Task 1). `status` ∈ active ·
  waiting · blocked (Task 1.6) · completed · expired · failed · cancelled; `wait_until` / `attempts` / `last_error`
  drive the temporal backbone. A resume must CLAIM the row (conditional UPDATE) before acting —
  never execute a waiting session without that claim.
- **Journey versions are immutable; sessions are pinned** (Phase 7 Task 2). Every create and every
  graph-changing save of a `whatsapp_flows` row snapshots a new `whatsapp_flow_versions` row
  (model `saved` event → `JourneyVersionService::snapshot()`, numbered under a lock on the flow row,
  unique(flow_id, version)); `published_version_id` is what NEW sessions start on;
  `whatsapp_flow_sessions.flow_version_id` is the version a session runs — the engine reads nodes,
  edges and branches ONLY from it. Never update or delete a version row (the model throws).
- **Journey actions have one execution contract** (Phase 7 Task 5, docblock of
  `WhatsAppJourneyEngine`). Executable: trigger, message, question, condition, conditional, delay,
  save_lead. Before every node advance() CHECKPOINTS `current_node_id` (and any captured answer).
  Malformed action config (`JourneyActionConfig`), an edge to a missing node or a bad condition →
  `failed` at the node, never retried. A send that did not go out, a failed capture write, a CRM
  promotion failure while the account is CRM-entitled, or any exception → retried from the failed
  node with the Task 1 backoff on BOTH paths (immediate path: `runImmediate()` parks it `waiting`),
  then `failed` after `MAX_RESUME_ATTEMPTS`. Nothing downstream of a failed node runs. Not
  CRM-entitled → capture kept, `not_entitled` recorded, journey continues (Task 10 contract).
  Exactly-once provider delivery is NOT guaranteed (see the docblock).
- **Journey session state machine** (Phase 7 Task 9, `WhatsAppFlowSession::TRANSITIONS`). Terminal:
  completed/failed/expired/cancelled — no exit; an Eloquent save that would move one throws. Every
  engine write is a conditional UPDATE guarded by its `from` set; the per-node checkpoint is one too, so a
  run stops at the next node once the session was cancelled/replaced/ended elsewhere. An immediate
  (inbound/test) run holds a run lease (`wait_until` on an `active` row, RESUME_LEASE_SECONDS); every
  normal end clears it, so `active` + expired lease = interrupted run → `journeys:resume-due` parks it
  `waiting` at its checkpoint (`recoverInterruptedRuns()`), resumed at-least-once. `active` with no lease
  = awaiting a reply (legitimately indefinite, never touched). The trigger is the first checkpoint.
- **Journey send classification** (Phase 7 Task 8, `JourneySendGate`). Every Journey send goes through
  `WhatsAppJourneyEngine::send()` → `JourneySendGate::refusal()` (re-reads the subscription row each
  time, so usage consumed earlier in the same run is seen — a run can no longer overshoot the cap):
  quota exhausted / plan expired → `quota_failure`, RETRIED by the Task 1/5 machinery then `failed`;
  suspended account / no subscription → `entitlement_blocked`, `failed` at once (`JourneyStepFailed`
  `retryable=false`). Palette text/media nodes check only capability/provider at run time
  (`JourneyNodeAuthorizer::runtimeDenialFor`); save-time `denialFor()` is unchanged. Legacy nodes stay
  grandfathered (no `whatsapp_send` check). journey_automation / chatbot module → `blocked` (Task 1.6).
- **Journey execution history** (Phase 7 Task 7, `JourneyExecutionEvent` docblock). Every run writes
  append-only `journey_execution_events` through `JourneyExecutionRecorder` (never throws; one INSERT
  per event; no bodies/answers/graph/credentials). 15 events (session_started/resumed/waiting/blocked/
  restored/completed/expired/failed/cancelled, node_started/succeeded/failed/retry_scheduled,
  reply_received, inbound_deduplicated); 11 error categories. Correlation: account, flow, pinned
  version, session, node, `inbound_event_id` (→ `inbound_message_events.event_key` = WAMID), and
  `details.dispatch_log_id` / `lead_id` / `crm_lead_id`. `attempt` = the session's resume-claim number
  (0 on the immediate path). flow/session ids are NOT foreign keys (history outlives a deleted journey);
  account cascades. Instrumentation must never change execution order or outcome.
- **Journey completion / terminal contract** (Phase 7 Task 6). Also executable now: palette `text`
  (`{{ var }}` substitution from collected answers, missing → '', empty render → `failed`) and
  `image`/`video`/`document`/`audio` (http(s) `mediaUrl`, sent via `WhatsAppMediaPayloadBuilder`,
  node entitlement re-checked at run time → `failed` if denied). The other 20 palette types still
  `expire` with a reason. `completed` only via `complete()` at a valid end (no outgoing edge,
  unconnected branch, save_lead); `expired` always carries `last_error` (also on the resumed path).
  Every status change of a running session goes through `transition()` — one conditional UPDATE
  (`WHERE status IN (active, waiting)`), no row lock (ConsumeAfterQuotaMigrationTest forbids
  `lockForUpdate` in the engine) — so completed/failed/expired/cancelled never change again and
  blocked changes only via `restoreBlocked()`.
- **Journey branching has one contract** (Phase 7 Task 4). Both branching nodes — legacy
  `condition` (branch on each edge; first match in edge order, else the single default, else
  dead end) and palette `conditional` (rules, `match` all = AND / any = OR, then the one edge on the
  `true`/`false` handle) — evaluate through `App\Services\WhatsApp\JourneyConditionEvaluator`: pure,
  no eval/DB/clock; 12 operators; context = the session's own `context_data` only. A malformed
  condition FAILS the session (`last_error`, `current_node_id` = node) and sends nothing; the save
  API refuses the same definitions (drafts with empty rules still save). The 25-step limit now
  records `last_error`. Do not add operators or context sources outside the evaluator.
- **Journey secrets are encrypted and never leave the server** (P5-6, `App\Support\JourneySecrets`).
  Secret = a credential-named (`isSensitiveName()`: auth/token/secret/password/api-key/cookie/…)
  pair in an `api` node's `data.headers` / `data.query`; nothing else in a graph is treated as
  secret (`credentialRef`/`gatewayRef`/`agentId` are references, not secrets). Stored as
  `enc:v1:` + `Crypt::encryptString()` by the `MasksJourneySecrets` model trait (`saving`, both
  `whatsapp_flows` and `whatsapp_flow_versions`), serialized as `JourneySecrets::MASK` +
  `masked: true`, audited masked (`WhatsAppFlow::auditableAttributes`). A client sends the mask
  back to keep the stored value (same node id + field + name), else 422; credentials in the
  `api` URL are 422. Read plaintext only via `JourneySecrets::reveal()` (no runtime needs it yet).
- **Runtime truth** (P5-7). `JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES` (12: the 5 legacy +
  delay, conditional, text, image, video, document, audio) is the ONLY definition of "executable";
  the frontend `RUNTIME_EXECUTABLE_NODE_TYPES` mirrors it (asserted by `JourneyRuntimeSafetyTest`).
  Publishing (create/update with `publish` true, `versions/{id}/publish`) and activating (toggle
  on, or update switching `is_active` on) require `JourneyPublishValidator` to pass: executable
  types only, unique ids, edges between existing nodes, complete action/branch configuration —
  else 422 `JOURNEY_NOT_PUBLISHABLE`, for the Super Admin too. Draft saves (`publish: false`) keep
  the permissive save-time validation. At run time every node is checked against the list
  (`unsupported_node`) and, for catalog nodes, re-authorized (`runtimeDenialFor`) before it runs.
- **Journey trigger / consumption rules** (P5-7). Trigger tiers ctwa (specific ad, then catch-all)
  > keyword > default, lowest flow id first in every tier. A `default` journey is taken only if
  no chatbot rule other than a `fallback` rule matches (`ChatbotEngineService::specificRuleMatches`,
  lazy). A run that ends failed/expired at its first node with no node succeeded returns
  "not consumed" and the chatbot answers the message; a transient first-node failure parked for
  retry still consumes it. A session paused at a question expires after
  `WhatsAppJourneyEngine::QUESTION_REPLY_TTL_SECONDS` (24 h, from `last_interaction_at`):
  inline on the next inbound message and by `journeys:resume-due` (`expireUnansweredQuestions()`),
  category `reply_timeout`; an expired question never captures a message.
- **Entitlement decisions are audited in `activity_logs`** (P5-8, `App\Services\Access\EntitlementAuditLogger`).
  `module_name` = "Entitlement Authorization", `action_type` = `allowed` | `denied`, `user_id` = actor
  (NULL on webhook/scheduler/API-key paths), `account_id` = the tenant the decision is about, ALWAYS
  resolved server-side — a refused cross-tenant / foreign `?account_id=` attempt is recorded on the
  ACTOR's account, the other account only as `target_account_id` in the payload. `new_values` is a
  closed allow-list (decision, action, category, reason, source, resource_type/id, node_type/id,
  module, capability/capabilities, provider/providers, permission, actor/target account, session_id,
  flow_version_id, inbound_event_id, error_code, http_status) — never bodies, graphs, headers, keys.
  Categories: allowed `entitled` / `no_requirement` / `super_admin_bypass`; denied
  `capability_not_entitled` / `module_disabled` / `provider_not_supported` / `account_suspended` /
  `no_active_subscription` / `missing_permission` / `cross_tenant` / `unauthorized_target_account` /
  `unsupported_node` / `invalid_configuration`. Recorded at: `capability.guard`, `module.guard`,
  `capability.apikey`, `module.apikey` (denials), spatie `permission:`/`role:` refusals (exception
  render hook, response unchanged), `TenantIsolationMiddleware` Agent target refusal, Journey
  save/publish/activate (one row per governed node type, allowed and denied; publishability refusals
  keep their own category), cross-tenant journey ids, Journey runtime (node decisions once per node
  type per run; runtime-entitlement block/start refusal/restore; send-gate suspended/no-subscription).
  Legacy node types and control-flow nodes check nothing and record nothing; quota refusals are usage,
  not entitlement, and are not recorded. A failed audit write is `Log::warning` (no payload) and never
  changes the decision. Readable only through `/api/admin/activity-logs` (`view-activity-logs`,
  Super Admin), now filterable by `action_type=allowed|denied`. `JourneyNodeAuthorizer::decide()` is
  the structured form of `denialFor()`/`runtimeDenialFor()` (thin wrappers — the audited decision is
  the enforced one).
- **Credits change only through `App\Services\Credits\CreditService`** (Phase 8 T1). One transaction per
  operation, starting with `SELECT … FOR UPDATE` on the account's `credit_accounts` row (lock order:
  credit_accounts → credit_reservations → insert); every change of `balance`/`reserved` writes a
  `credit_ledger_entries` row with signed deltas and the resulting `balance_after`/`reserved_after`.
  `reserved <= balance` always (service + CHECK on MariaDB/MySQL); ledger rows and reservation rows are
  immutable through the models (update/delete throw); a reservation changes state only via the
  service's guarded UPDATE `WHERE status='reserved'`. Idempotency = unique(credit_account_id,
  idempotency_key) on the ledger (and reservations). Never write these tables from anywhere else.
  **Spending (Phase 8 T3) goes through `CreditConsumptionService`** (account-bound wrapper over
  `CreditService`); never call it with an account the caller did not resolve through the tenant rules.
- **Every inbound WhatsApp message passes `InboundEventGate`** (Phase 7 Task 3, inside
  `ChatbotEngineService::handleInboundMessage`): (1) take the (account, phone) lease in
  `journey_conversation_locks` (conditional UPDATE; 120 s lease; waits ≤ 10 s); (2) claim the event in
  `inbound_message_events` — unique(account_id, provider, event_key), first INSERT wins, a duplicate
  runs nothing; (3) run chatbot/Journey; stamp `processed_at`; release. Identity: Meta `wamid:<WAMID>`;
  QR `wamsg:<Baileys msg.key.id>` (qr-engine-service sends `message_id`). No key → no de-dup (lease
  only). Do not reintroduce cache-based inbound claims.
- **A group batch sends only to its frozen recipient list** (P5-1). The group dispatchers read the
  membership, `reserve()` exactly that count and write `group_dispatch_recipients` in ONE
  transaction; `ProcessGroupDispatchJob` / `ProcessGroupDirectMessageJob` iterate that list
  (read by parent + account, never from the job payload). A member added later is never sent; one
  removed later is a failed, refunded recipient. Never re-read live membership in a group job.
- **An order is fulfilled with the terms it was created with** (P5-4).
  `PaymentGatewayController::createOrder()` captures engine, billing model, rate, quota and duration
  from the database plan onto the invoice (`Invoice::capturePlanTerms()`, same INSERT;
  `plan_*` columns, `plan_terms_captured_at` marker). `InvoiceCreditService::markPaidAndCreditQuota()`
  reads them via `purchasedPlanTerms()` and never re-reads the plan for them. The columns are not
  fillable, are hidden, and are immutable once captured (model `updating` guard). An invoice with no
  captured terms (pre-P5-4 order) is fulfilled from the plan row as before; nothing is backfilled.
  The capability bundle is still reconciled from the live plan (unchanged).
- **One payment, one fulfilment; one account, one fulfilment at a time** (P5-5).
  `markPaidAndCreditQuota()` in ONE transaction: lock the invoice → already paid? return false (the
  webhook acks, verify-payment answers "already confirmed/processed" with the paid invoice) → lock the
  invoice OWNER's account row (never a request value) → mark paid → lock the current subscription →
  credit quota / extend expiry once → reconcile entitlements → Agent commission (unique invoice_id).
  Lock order is always invoice → account → subscription; the account lock is taken BEFORE the invoice
  write (that write takes a FK shared lock on the account, which deadlocked two fulfilments). Any
  exception rolls the whole fulfilment back and the invoice stays pending for the gateway's retry.
- **A group batch has one owner and always settles** (P5-3). A group job may send only after
  `MessageDispatchLog::claimGroupDispatch()` (conditional UPDATE of `claim_token`, NULL → token while
  `queued`) and only while `heartbeatGroupDispatch()` confirms it still owns a still-queued batch —
  never guard on a plain status read. Every settlement (completion, caught exception, `failed()`,
  `group-dispatch:recover-stale`) goes through `settleGroupDispatchFromRecipients()`: delivered =
  recipient rows with `sent_at`, refund = reserved − delivered via the existing
  `resolveGroupDispatch()` (locked queued → terminal, once). A run is bounded (`$timeout` 85 s <
  `retry_after` 90 s; 50 s slice budget, then `continueInNextSlice()` releases the claim and queues
  the next slice, which skips recipients that already have a row). Stale: claimed with no heartbeat
  for 900 s, or unclaimed and idle for 3600 s.
- **No model uses `SoftDeletes`.** The architecture report recommends adding it to `accounts`,
  `invoices`, `subscriptions`, `message_templates`; that has **not** been done.

---

## 5. API Surface

### Internal API (`/api/...`) — consumed by `frontend-app`

Prefixes: `admin`, `alerts`, `analytics`, `audit-logs`, `billing`, `chatbot`, `contacts`,
`crm`, `developer`, `exports`, `groups`, `leads`, `message-templates`,
`notification-broadcasts`, `notification-templates`, `social`, `team`, `whatsapp`.

### CRM surface (complete as of Task 7)

| Route | Verb | Purpose |
|---|---|---|
| `/api/crm/contacts` | GET POST | Contact list & creation |
| `/api/crm/contacts/{id}` | GET PATCH DELETE | Contact detail |
| `/api/crm/contacts/{id}/leads` | GET | A contact's leads |
| `/api/crm/leads` | GET POST | Lead list & creation; list filters `?tag_id=` / `?tag_ids[]=` (AND) |
| `/api/crm/leads/{id}` | GET PATCH DELETE | Lead detail |
| `/api/crm/leads/{id}/assignee` | PATCH | **The** ownership mutation API |
| `/api/crm/leads/{id}/status` | PATCH | **The** lifecycle mutation API |
| `/api/crm/assignees` | GET | Eligible assignees |
| `/api/crm/pipeline` | GET | **Read-only** Kanban view; same tag filters |
| `/api/crm/analytics` | GET | Task 12 — **read-only** aggregates (totals, conversion rate, by status/source/assignee, trend); shared lead filters + `from`/`to` |
| `/api/crm/tags` | GET POST | Tag list (`?search=` prefix, `lead_count`) & creation |
| `/api/crm/tags/{id}` | GET PUT PATCH DELETE | Tag detail / rename / delete |
| `/api/crm/leads/{id}/tags/{tag}` | POST DELETE | Idempotent tag attach / detach (never changes status) |
| `/api/crm/leads/bulk/assignee` | POST | Task 9 — bulk assign / reassign / unassign (`assigned_user_id` id or null) |
| `/api/crm/leads/bulk/status` | POST | Task 9 — bulk status (`status`, optional `not_converted_reason`) |
| `/api/crm/leads/bulk/tags/attach` | POST | Task 9 — bulk tag attach (`tag_id`), idempotent |
| `/api/crm/leads/bulk/tags/detach` | POST | Task 9 — bulk tag detach (`tag_id`), idempotent |

All carry `crm.target` + `module.guard:lead_crm` + `permission:manage-crm` + `capability.guard:crm`
(`crm.target`: Super Admin target resolution + target entitlement, see §3.2).

Lead lifecycle: `new → contacted → converted | not_converted`. Corrections are permitted;
it is not an irreversible state machine.

### CRM frontend (Task 8) — `frontend-app`

| Route | Screen | APIs consumed |
|---|---|---|
| `/crm/leads` | Lead list: filters (search, status, source, assignee incl. unassigned, tags AND), pagination, inline status / assignee / tag attach-detach, "Add lead" (header action; disabled in a Super Admin's global view until a client is selected; the modal names the target client; after creation the form closes, a toast confirms and the list reloads) | `GET/POST /crm/leads`, `PATCH …/status`, `PATCH …/assignee`, `POST/DELETE …/tags/{tag}`, `GET /crm/assignees`, `GET /crm/tags` |
| `/crm/leads/:id` | Lead detail: status (+optional not-converted reason), assignee, tags, capture origin, timestamps, contact link, move to another contact, delete | `GET/DELETE /crm/leads/{id}`, the three mutation endpoints above, `PATCH …/contact`, `GET /crm/contacts` |
| `/crm/pipeline` | Kanban: the 4 server columns + totals, per-column "load more", shared filters (no status), status change moves a card | `GET /crm/pipeline` (+`status` for load-more), `PATCH …/status` |
| `/crm/contacts` | Contact list: search, pagination, lead counts, create | `GET/POST /crm/contacts` |
| `/crm/contacts/:id` | Contact detail: edit, delete (409 shown), merge into another contact, leads with status/source filters | `GET/PATCH/DELETE /crm/contacts/{id}`, `POST …/merge/{target}`, `GET …/{id}/leads` |
| `/crm/tags` | Tag management: create, rename, delete (explains leads/contacts are kept), prefix search, lead counts linking to the filtered lead list | `GET/POST /crm/tags`, `PATCH/DELETE /crm/tags/{id}` |
| `/crm/analytics` | Task 12 — cards (total, new, contacted, converted, not converted, conversion rate), trend bars, status/source/assignee breakdowns; date range + shared lead filters in the URL; loading/empty/error states | `GET /crm/analytics`, `GET /crm/assignees`, `GET /crm/tags` |

### CRM analytics (Task 12)

One dataset per request: `crm_leads` of the resolved account (`forAccount`), narrowed by the
shared `CrmLead::scopeFilter()` vocabulary and an inclusive `from`/`to` on
`crm_leads.created_at` (UTC calendar days). Never the `leads` capture table.
`conversion_rate = converted / total × 100` (2 dp, `null` when total is 0) — a cohort view
over the leads' **current** status (no status-history table exists). Breakdowns are single
`GROUP BY`s (no joins; tag filters are EXISTS), so each sums to `total`. Trend: day buckets
up to 92 days, ISO weeks up to 731, then months; zero-filled. 5 queries per request
regardless of volume. Same gates as every CRM route (read-only, so allowed on an expired
subscription). Not on `/api/v1`. No CRM export exists (none was added).

**Access:** every CRM route and nav item requires `manage-crm` + the `lead_crm` module + the
`crm` capability from `/auth/me` (new `capability` prop on `ProtectedRoute`, new
`requiresCapability` on nav items — both read the existing capability map; no new
permission/capability/entitlement). Super Admin bypasses the UI gates and must pick a client in
the existing header switcher. Expired subscription → mutations disabled (existing
`isReadOnly()` convention). These are UX gates; the API middleware is the boundary.

**Filters/pagination** live in the URL query string (`q`, `status`, `source`, `assignee`,
`tags`, `page`, `per_page`) and are always sent to the server. No query library was added:
pages use a small keyed-request hook (`components/crm/crmHooks.ts::useCrmQuery`).

### CRM bulk operations (Task 9)

- **Endpoints:** the four `/api/crm/leads/bulk/*` routes above, same middleware chain as
  every CRM route; no new permission/capability. Not on `/api/v1`. No bulk delete, contact
  reassignment or merge.
- **Request:** `lead_ids` — required array, 1..**100** (`CrmBulkLeadSelection::MAX_LEADS`),
  distinct positive integers (a duplicate is a 422) — plus exactly one operation field.
  `account_id`/`agent_id`/`tenant_id` are never read.
- **All or nothing:** one DB transaction per request; the leads are selected with
  `WHERE account_id = ? AND id IN (…) FOR UPDATE`. Any foreign/missing lead id, foreign/missing
  tag, ineligible assignee or disallowed transition → **422, zero mutations**, generic
  non-identifying message. A failure during the writes rolls the whole batch back.
- **Response (200):** `{"message", "data": {"operation", "requested", "changed", "unchanged"}}`.
- **Domain reuse:** status uses `applyStatus()`/`STATUS_TRANSITIONS`; assignment uses
  `applyAssignee()` and the saving guard's `assigneeIsEligible()` (answer memoized for the
  duration of one bulk call only); tags go through the `CrmLeadTag` model. No-ops write and
  audit nothing.
- **Audit:** one `activity_logs` row per changed lead/pivot (actor, account, route, lead id,
  old/new), none for no-ops or rejected batches — verified on real rows.
- **Frontend:** CRM Leads list has page-scoped checkboxes + select-all-visible and a bulk bar
  (Assign, Unassign, Change status, Add tag, Remove tag, Clear). Selection is cleared by any
  filter/page/page-size/client change or leaving the page. One request per action.

**Route hardening (Task 9):** every `{id}` on the lead and contact routes now has
`whereNumber`, so a non-numeric id (e.g. `DELETE /crm/leads/bulk`) is a 404 instead of a 500.

### Manual vs automated CRM lead creation (verified post Phase 6)

Two independent paths into `crm_leads`, one API each; neither blocks the other:

| Path | Entry | Source | Authorization |
|---|---|---|---|
| Manual (UI "Add lead", Developer API) | `POST /api/crm/leads` / `POST /api/v1/crm/leads` | always `manual` (locked: the UI has no source picker; `StoreCrmLeadRequest` accepts only `manual`, any other value 422; `CrmLeadController::store` forces `manual`); `/v1` forces `api` | user + tenant scope + `lead_crm` + `manage-crm` + `crm` (+ `crm.target` for Super Admin) |
| Meta Lead Ads | `POST /api/social/webhook/meta` (leadgen) | `meta_ad` | webhook signature + page → tenant; CRM entitlement gate in `CaptureLeadLinker` |
| Click-to-WhatsApp | `POST /api/webhooks/meta` (referral) | `meta_ad` | same |
| Journey `save_lead` | `POST /api/webhooks/meta` → journey engine | `journey` | same |

Source describes origin only and never gates creation. A manual lead and an automated capture
for the same phone share one Contact and stay separate leads; manual creation never writes or
re-links a capture row. `CrmLeadSourcesIndependenceTest` drives every automated path through the
real signed webhooks.

### Meta / Ads lead capture → CRM (Task 10)

No new route, permission, capability, plan or migration. The integration is the existing
`CaptureLeadLinker`, called by all three capture writers (unchanged call sites):

| Capture writer | `leads.provider` | CRM `source` |
|---|---|---|
| `MetaLeadWebhookHandler` (Lead Ads form, `POST /api/social/webhook/meta`) | `meta` | `meta_ad` |
| `MetaWebhookController::captureCtwaLead()` (Click-to-WhatsApp referral, `POST /api/webhooks/meta`) | `whatsapp_ctwa` | **`meta_ad`** (was `whatsapp` until Task 10) |
| `WhatsAppJourneyEngine::upsertLead()` (journey `save_lead`) | `whatsapp_journey` | `journey` |

- **Flow:** capture row committed → `linkQuietly()` → Contact resolved/reused per account
  (`ContactResolver`) → `crm_leads` row (`status=new`, unassigned) with
  `capture_lead_id` → capture. The capture row is never modified; `leads` has no CRM column.
- **Entitlement gate (new in Task 10):** `linkQuietly()` promotes only when the account has an
  active `crm` entitlement **and** the `lead_crm` module (same predicates as
  `capability.guard:crm` / `module.guard:lead_crm`). Otherwise the capture is kept, a
  `crm_capture_link_failures` row with reason `not_entitled` is recorded, and
  `CaptureLeadLinker::retry()` promotes it once the account is entitled. Subscription state is
  not a gate (an expired subscription keeps read access to data). `link()` stays ungated.
- **Idempotency:** redelivery is stopped at capture (`leads.provider_lead_id` unique;
  leadgen early-exit; CTWA `updateOrCreate` on WAMID); promotion returns the existing CRM lead
  for an already-linked capture; `unique(crm_leads.capture_lead_id)` makes a duplicate
  unstorable, and a concurrent losing insert now returns the winner instead of recording a
  failure. A **new** capture of the same person (new leadgen id after 24 h, or a new CTWA
  click) is a new CRM lead on the same Contact — the rule established in Task 3.
- **Failure isolation:** a CRM exception rolls back the Contact + CRM lead transaction, is
  recorded as an `exception` failure, and never breaks the webhook (always 200) or the
  capture/notify/welcome steps.

### Journey execution (Phase 7 Task 1)

Routes `/api/whatsapp/flows`: index/show/sessions, store, update/toggle/test, destroy — gated
`tenant.isolation → subscription.guard → module.guard:chatbot → capability.guard:journey_automation
(Task 1.5) → permission:manage-chatbot|whatsapp.*`, plus the per-node `JourneyNodeAuthorizer` check on
save. Missing capability → the standard 403 `CAPABILITY_NOT_ENTITLED`, nothing written. Super Admin
bypasses the capability gate (unchanged).

**Runtime gate (Task 1.6).** `App\Services\Access\JourneyRuntimeEntitlement::allows()` =
`hasModuleEnabled('chatbot')` ∧ `canTenant(journey_automation)` on the account that owns the
session — the same predicates as the API guards, evaluated at execution time. Checked: inbound
(before continuing an open session, and before starting one once a trigger matched — tenants
without journeys pay nothing extra); scheduled resume (after the claim, before any step, and again
before every node of a resumed run). Refused → nothing sent, no lead, no quota; the session becomes
`blocked` with all state kept (`last_error` = reason). Restored when entitled again — inline on the
next inbound message, or by `journeys:resume-due` (`restoreEntitledBlockedSessions()`): `wait_until`
set → `waiting` (due at once, continues from its checkpoint), else → `active`. `testFlow` (API
"Test") is not runtime-gated — it is behind the API gate. One route added in Task 1:
`POST /api/whatsapp/flows/{id}/sessions/{sessionId}/cancel` (edit permission; flow then session
scoped to the resolved account → 404 otherwise; 409 if the session already ended).

| Piece | Where | Role |
|---|---|---|
| Immediate run | `WhatsAppJourneyEngine::handleInboundMessage()` ← `ChatbotEngineService` ← Meta webhook / QR internal inbound | unchanged; a `delay` node now parks the session instead of expiring it |
| Park | `advance()` `delay` branch | `status=waiting`, `current_node_id=<delay>`, `wait_until=now+amount·unit`, `attempts=0` |
| Scan | `journeys:resume-due` (every minute, `routes/console.php`) | due waiting rows on ACTIVE flows → `ResumeJourneySessionJob` on `database:journeys` |
| Worker | `queue:work database --queue=journeys --stop-when-empty --max-time=55` (every minute) | same pattern as `whatsapp-bulk`; needs the existing `schedule:run` cron |
| Resume | `WhatsAppJourneyEngine::resumeDueSession()` | atomic claim (lease 600 s, attempts+1) → run in "resumed" mode (send failure throws, per-message checkpoint, cancel check per node) → retry 60 s/120 s, `failed` + `last_error` after 3 |
| Cancel | `cancelSession()` | conditional UPDATE from active/waiting only |
| Pause | flow `is_active=false` | waiting rows are held (not scanned; a claimed one is handed back, attempt not counted) and resume when reactivated |

**Versions (Task 2).** `graph_data` on the flow stays the editable working copy (= latest version),
so the CRUD contract is unchanged; responses gain `published_version_id`. `store`/`update` accept an
optional `publish` (default `true` = the old "edit goes live" behaviour for new sessions; `false`
saves a draft version). New routes, same gates as the rest of the group:
`GET /{id}/versions` (list, newest first, `is_published`), `GET /{id}/versions/{versionId}` (with
graph), `POST /{id}/versions/{versionId}/publish` (edit permission; re-checks node entitlements;
also the rollback path). A new session pins the PUBLISHED version; the manual Test pins the LATEST
saved one. Running/waiting sessions never change version.

### Credits (Phase 8 Task 1) — Credit System Foundation

**Existing structures reused / not reused (audited first).** Billing today is message quota on
`subscriptions` (`MessageQuotaService`, lock-then-increment) plus `invoices`; `usage_quotas`
(Phase 1, per-capability allocated/used per period) exists but is unused and has no ledger or
idempotency, so it was not stretched into a financial ledger — it remains the natural place for a
Task 2+ per-period allocation if needed. The `ai` capability exists (Phase 1) and is not wired to
credits yet (Task 2). `api_idempotency_keys` is the `/v1` request-replay store, not a financial
guarantee, so credit idempotency lives in the ledger's own unique index.

**Model.** One `credit_accounts` row per account (unique `account_id`, created at balance 0 on first
use, race-safe `INSERT … IGNORE`): `balance` (credits owned) and `reserved` (held by open
reservations), UNSIGNED BIGINT; `available = balance − reserved`. No balance column on `accounts`.

**Ledger** (`credit_ledger_entries`, append-only, no `updated_at`): credit_account_id + account_id
(composite FK → credit_accounts(id, account_id), CASCADE — a row cannot mix tenants), `type`,
`amount` (> 0), `balance_delta`, `reserved_delta`, `balance_after`, `reserved_after`,
`idempotency_key` (unique per credit account), `request_hash` (hidden), `reservation_id`,
`refund_of_entry_id`, `reference_type`/`reference_id`, `reason`, `metadata`, `source`
(`system` | `admin_api`), `actor_user_id`, `created_at`. The rows of one account, in id order,
reproduce its balance and reserved exactly.

| type | balance | reserved | rule |
|---|---|---|---|
| `grant` / `purchase` | +a | 0 | purchase is recorded only (payment wiring = Task 2) |
| `adjustment` | ±a | 0 | a negative adjustment only takes AVAILABLE credits |
| `reservation` | 0 | +a | a ≤ available |
| `reservation_release` | 0 | −a | release, or the unused remainder of a partial consume |
| `consumption` | −a | −a | only of a reservation; 1 ≤ a ≤ reserved amount |
| `refund` | +a | 0 | of a `consumption` of the same account; Σ refunds ≤ consumed |

**Reservations**: `reserved → consumed` or `reserved → released`, both terminal. Consuming a released
or releasing a consumed reservation → `invalid_reservation_state`; consuming/releasing it again →
replay of the original entry (keys `consume:{id}` / `release:{id}`). ~~No automatic cleanup of stale
reservations~~ — Phase 8 T3: caller-set `expires_at` + `credits:release-expired-reservations`.

**Idempotency.** Every operation takes a key; replays return the original entry (`replayed`), a key
reused with different parameters → `idempotency_conflict`. Enforced by the unique index (a racing
duplicate that reaches INSERT is answered as a replay). Key convention `{origin}:{operation}:{ref}`;
the admin API stores `admin:{grant|adjust|refund}:{client key}`.

**Concurrency** (real MariaDB, `tests/Probes/credit_concurrency_probe.php`, run by
`CreditSystemFoundationTest` on MariaDB; 24/24 over 3 rounds): 20 simultaneous grants; 20
reservations racing for 100 credits (exactly 10 win); 20 consumes of 10 reservations (each once);
consume vs release (one wins); 12 duplicate grants / reservations on one key (one effect); 8
simultaneous HTTP grants on one `Idempotency-Key` (one 201, seven 200 replays); 24 mixed operations
— ledger always reproduces the balance.

**API** (no mutation endpoint for reserve/consume/release — service only):

| Route | Who | |
|---|---|---|
| `GET /api/billing/credits` | tenant (billing group: tenant.isolation + manage-subscriptions + module billing) | resolved account's balance/reserved/available |
| `GET /api/billing/credits/ledger` | same | its ledger, newest first, `?type=` |
| `GET /api/admin/accounts/{id}/credits` | Super Admin (any) · Agent (own sub-clients, else 404) | balance + ledger |
| `POST /api/admin/accounts/{id}/credits/grant` | **Super Admin only** (403 otherwise) | `amount`, `reason`, `reference?`, `idempotency_key` (or `Idempotency-Key` header) |
| `POST …/credits/adjust` | Super Admin only | signed non-zero `amount` |
| `POST …/credits/refund` | Super Admin only | `amount`, `consumption_entry_id` (same account) |

201 applied · 200 `replayed: true` (and an `activity_logs` row, module `Credits`, action_type `replay`)
· 409 `IDEMPOTENCY_CONFLICT` · 422 `INSUFFICIENT_CREDITS` / `INVALID_OPERATION` / validation. The target
is always the path `{id}` (or the tenant.isolation-resolved account); body/query `account_id` is
never trusted. Clients have no write path; Agents read only.

**Known limitations (Task 1):** ~~no plan ↔ credit link~~ (Task 2); no rollover / expiry, no pricing,
no AI usage (Task 3+); `purchase` is not wired to payments; no reservation expiry / cleanup; Agents
cannot grant credits (reselling needs pricing); no credits screen for tenants; the CHECK constraints
exist on MySQL/MariaDB only (SQLite relies on the service). Verified on MariaDB 10.11.14 and (Task 2)
MySQL 8.0.46 — the closest 8.0 build reachable from the sandbox; 8.0.41 itself was not available.

**Task 2 fix to Task 1:** `CreditService::creditAccountFor()` now creates the row with an UPSERT
(`INSERT … ON DUPLICATE KEY UPDATE`) and re-reads it with a LOCKING read. Found by the new
plan-allocation probe: inside a caller's transaction, INSERT IGNORE left shared locks that deadlocked
racing FOR UPDATEs, and a plain read used the caller's older REPEATABLE-READ snapshot and missed the
row another process had just committed.

### Credit plans (Phase 8 Task 2) — plans, periods, AI capability vs credits

**Audit (existing structures).** A plan is a `plans` row (price, duration, engine, billing model,
message quota) + its `plan_entitlements` bundle; `plan_entitlements.usage_limit` carries the message
quota for `whatsapp_send` and NULL (= unbounded / n.a.) elsewhere — NOT reused for credits, because
NULL-means-unbounded is the wrong default for money-like credits and it would tie credits to a
capability row. Subscriptions have NO plan column: an account has ONE subscription row that every paid
plan invoice mutates (`InvoiceCreditService::markPaidAndCreditQuota`: renewal/upgrade/downgrade while
active STACK a new `duration_days` period on the current expiry; new/lapsed start one now; message
quota is additive). The current plan = the latest paid invoice's `plan_key`
(`PlanEntitlementReconciliationService::currentPlanSlug`). Super-Admin-provisioned subscriptions
(`AccountController::store/updateSubscription`) are custom (no plan) and top-up invoices use
`plan_key = quota_topup` (no plan row). There is NO cancellation flow — status is
active/expired/exhausted; suspension is the account's `status`. `usage_quotas` (Phase 1) was unused.

**Plan → credits.** `plans.included_credits` (UNSIGNED BIGINT, NOT NULL, DEFAULT 0): AI credits per
purchased period. Seeded explicitly — starter 0 · growth 0 · business 0 (owner decision; nothing is
granted until a Super Admin sets a value). The seeder writes it only when it CREATES a plan row, so
re-seeding never resets an administrator's value (`PlanCatalog` carries the same 0s). Plan management
(`/api/admin/plans-management`, Super Admin only): `included_credits` in index/store/update
(0 … 1,000,000,000); a plan may include credits ONLY if its bundle includes `ai` (422 otherwise,
also when removing `ai` from a plan with credits). A change applies to orders placed afterwards.

**When credits are allocated** (the billing period IS the paid plan invoice):

| Event | Credits |
|---|---|
| new subscription / activation (first paid plan invoice) | the invoice's captured `plan_included_credits`, period [now, now+duration) |
| renewal while active | again, for the stacked period [current expiry, +duration) |
| upgrade / downgrade (paid invoice of another plan) | the NEW plan's captured amount for its period; nothing already held is removed |
| reactivation after a lapse | a fresh period from now |
| expired subscription | nothing allocated or removed; credits kept, not usable (see below) |
| suspended account | nothing removed; a paid invoice still allocates (payment-backed); not usable |
| cancellation | no such flow exists; nothing to do |
| Super-Admin custom subscription / top-up invoice | no plan → no plan credits (Super Admin can grant manually, Task 1) |
| pre-Task-2 orders (terms captured without credits) | 0 — they bought no credits (backfill is the owner's tool) |

Allocation runs INSIDE the payment-fulfilment transaction (after the subscription is saved), so it
is exactly-once per paid invoice (invoice lock + `isPaid()` + the ledger key) and rolls back with the
payment. Amount = `Invoice::purchasedIncludedCredits()` (P5-4 principle: fulfilled with the terms it
was ordered with). Ledger type **`plan_allocation`** (distinct from grant / purchase / adjustment /
refund), `reference_type = invoice`, metadata {plan, subscription_id, invoice_id, period_start,
period_end, origin payment|backfill}. **Idempotency key `plan-allocation:{subscription_id}:{invoice_id}`**
(unique per credit account — duplicate webhook, retried job, backfill after the live path, and
concurrent processing all yield ONE allocation; a different amount for the same key → conflict).
Each period also gets a `usage_quotas` row (capability `ai`, allocated, used 0, period start/end,
subscription_id, invoice_id, source `plan_allocation`, `credit_ledger_entry_id` UNIQUE) — old
periods stay auditable and every one is reconstructable from the ledger alone. **No rollover, no
credit expiry** (not in the product definition → deferred; credits never silently expire or change
type). Credits granted at payment are available at once, also for a stacked future period (as the
message quota is).

**AI capability vs credits** (`CreditEntitlementService`): capability `ai` (account_entitlements,
`canTenant`) = MAY the account use AI; credit balance = HAS it credits. Both required, never merged:
credits without `ai` (manual grant, or kept after a downgrade that revoked `ai`) are not usable; `ai`
with zero credits is not usable. `usable()` / `status()` additionally require an administratively
active account and a CURRENT (unexpired) subscription — an exhausted MESSAGE quota does NOT block AI
credits. `status().reason` ∈ ai_capability_missing · account_suspended · subscription_inactive ·
no_available_credits · null. Nothing consumes credits yet (Task 3+).

**Ownership.** Credits belong to the account that paid the invoice / owns the subscription
(`PlanCreditAllocator` refuses any other account) — an Agent's own account, its Client, a Client, the
Super Admin's platform account: each its own credit account; no pooling; an Agent never receives a
client's allocation.

**Visibility.** `GET /api/billing/credits` (and the admin `GET /api/admin/accounts/{id}/credits`) now
return balance / reserved / available + `plan` {slug, label, included_credits} + `subscription`
{status, starts_at, expires_at} + `current_period` {starts_at, ends_at, allocated} + `ai_capability`,
`can_use_ai_credits`, `reason` — no ledger internals.

**Backfill** (owner-run, never scheduled): `php artisan credits:backfill-plan-allocation --dry-run`
then without `--dry-run` (`--account=ID` to limit). Per active account with an active subscription
and a paid plan invoice: allocates the LATEST paid plan invoice's period [expires_at − duration,
expires_at) with the captured amount, else the plan's CURRENT `included_credits`; same key as the live
path → repeat-safe and never doubles a live allocation; additive (manual/purchased/adjusted/refunded
credits and earlier periods untouched). Skips (reported): suspended accounts, expired subscriptions,
no paid plan invoice, zero-credit plans. With every plan at 0 today it allocates nothing.

**Verified**: `CreditPlanEntitlementTest` (21); `credit_concurrency_probe.php` now 13 checks (+5
plan-allocation races: 8 fulfilments of one invoice, 8 re-allocations, 8 first allocations of one
period, 2 invoices of one account, 4 backfills after a live allocation) — 39/39 over 3 rounds on
MariaDB 10.11.14 (×3 runs) and on MySQL 8.0.46; migrations fresh / upgrade-with-legacy-data /
rollback / re-apply on both engines.

**Known limitations (Task 2):** real per-plan credit values are an open owner decision (all 0);
the backfill allocates only the latest purchased period per account (older stacked periods are not
reconstructed); a payment refund/reversal does not claw back credits (same as message quota —
product decision needed); no rollover/expiry; no credit consumption or pricing (Task 3+); MySQL
verification used 8.0.46, not 8.0.41 exactly.

### Credit spending (Phase 8 Task 3) — reservation & consumption

**Contract** — `App\Services\Credits\CreditConsumptionService` (the only spending API; no HTTP
endpoint — service-only; every call names the paying account, already resolved by the caller through
the tenant rules; reservations are addressed by id and must belong to it):

| Call | Effect | Ledger rows | Key |
|---|---|---|---|
| `reserve(acct, a, key, ctx, ?gate)` | hold a ≤ available; `ctx.expires_at` optional | `reservation` (0, +a) | caller |
| `consume(acct, rid, a, key)` | partial consumption, 1 ≤ a ≤ remaining; reservation stays open until nothing remains (→ `consumed`) | `consumption` (−a, −a) | caller |
| `settle(acct, rid, ?a)` | Task 1 `consume`: take a (default: all remaining), release the rest; → `consumed` | `consumption` [+ `reservation_release` `:remainder`] | `consume:{rid}` |
| `release(acct, rid, ?a)` | give the remaining hold back (a, if given, must equal it); → `released`; allowed after expiry | `reservation_release` (0, −remaining) | `release:{rid}` |
| `spend(acct, a, key, ctx, ?gate)` | direct consumption of available credits | `reservation` (`:hold`) + `consumption` — the reservation row is created already `consumed` | caller |

Reservation: `amount`, `consumed_amount` (accumulates), remaining = amount − consumed while open; the
terminal status names the closing action; terminal rows are never mutated (guarded compare-and-set on
status + consumed_amount, under the credit-account lock). `consumption` still always references a
reservation, so refunds are unchanged. `consume:`/`release:` prefixes are refused as caller keys (also
for grant/adjust/refund). Key namespace suggestion `ai:reservation:<id>`, `ai:consume:<id>:<n>`,
`ai:request:<id>`.

**Idempotency**: same key + same parameters → original entry (`replayed`), nothing written; different
parameters → `idempotency_conflict` (409 in any future API). `expires_at` is not a parameter (a retry
recomputing now+ttl replays). Enforced by the ledger's unique index; racing duplicates → one effect.

**Product gate**: `CreditSpendGate::assertMaySpend()` — optional on reserve/spend, run INSIDE the lock
after the idempotency lookup (a retry of an applied request replays even if the account was blocked
since). `CreditEntitlementService` implements it for AI (capability → `entitlement_blocked`; suspended
account / non-current subscription → `account_blocked`; exhausted message quota is not a block). The
balance check stays in `CreditService` (`insufficient_credits`). Settle/release are never gated.
`CreditService` itself stays generic (no AI knowledge).

**Failure codes** (`CreditException::$reason`): `insufficient_credits`, `invalid_amount`,
`reservation_not_found`, `tenant_mismatch`, `reservation_already_terminal` (Task 1's
`invalid_reservation_state`, renamed — constant `RESERVATION_STATE` kept as alias), `invalid_reservation`
(expired), `idempotency_conflict`, `entitlement_blocked`, `account_blocked`, `invalid_operation`. Task 1's
amount errors moved from `invalid_operation` to `invalid_amount` (admin API validates amounts first, so
its responses are unchanged). Refused operations write nothing.

**Expiry** (infrastructure only, no product TTL): `credit_reservations.expires_at` NULL = never (all
existing rows). Past it: consume/settle → `invalid_reservation`; release only. `credits:release-expired-reservations
[--dry-run] [--limit=500]`, scheduled every 5 min `withoutOverlapping()`; each release is the normal
`release:{id}` operation, so it is idempotent and safe against a racing owner. Not credit expiry/rollover.

**Metadata hygiene**: flat scalar map, ≤ 20 keys, strings ≤ 191 chars (identifiers, never prompts/content).

**Concurrency** (`credit_concurrency_probe.php`, +7 checks, 20 per round): 20 direct spends of 10 vs 100
(exactly 10); 20 partial consumes of 10 on one reservation of 100 (exactly 10); partial consumes racing
releases of one reservation (one terminal transition); duplicate spend/consume/settle/release keys (one
effect each); 30 mixed grant/reserve/consume/release/settle/spend/refund/adjust; expiry cleanup ×4 racing
owner releases; spends inside callers' own transactions (holding an `accounts` lock) racing a plan
payment on the same account (no deadlock). 60/60 over 3 rounds: MariaDB 10.11.14 ×3 runs, MySQL 8.0.46 ×2.

**Known limitations (Task 3):** no spending HTTP endpoint and no reservation read endpoint (the ledger
endpoint shows reservation/consumption rows); no AI provider, pricing or cost model (later tasks); the
product gate reads account/subscription without locking them (a suspension committed during an
in-flight reserve may let that one reserve through — settle/release are unaffected); expiry TTL is the
caller's choice; `expires_at` is dropped by a rollback of the Task 3 migration; MySQL verified on
8.0.46, not 8.0.41.

### Developer API (`/api/v1/...`) — public, API-key authenticated

Separate surface with its own key auth, per-key capability gating
(`capability.apikey`), request logging and idempotency keys.

⚠️ **It is deliberately narrower than the internal API.** Do not widen it as a side effect
of internal work.

**CRM on `/api/v1` (Task 11)** — single-lead operations only, all under
`subscription.apikey` + `module.apikey:lead_crm` + `capability.apikey:crm`:

| Route | Purpose |
|---|---|
| `POST /api/v1/crm/leads` | Create (H2). `source` forced to `api`; contact reused by normalized phone; `Idempotency-Key` honoured |
| `GET /api/v1/crm/leads/{id}` | Read — same representation as the tenant API |
| `PATCH /api/v1/crm/leads/{id}/status` | `CrmLeadService::changeStatus()` |
| `PATCH /api/v1/crm/leads/{id}/assignee` | `changeAssignee()`; same eligibility guard (active, same account, manage-crm) |
| `POST`/`DELETE /api/v1/crm/leads/{id}/tags/{tag}` | Idempotent attach / detach by tag id |

**Not exposed:** list/search, delete, general update, contact reassignment, contacts,
pipeline, tag CRUD, assignee listing, any bulk operation. Foreign and missing ids are
identical 404s. Writes need an active subscription; reads do not. API-key writes produce no
`activity_logs` row (LogsActivity needs a user) — they are attributed via `api_request_logs`.

---

## 6. Test Suite

**70 feature test files** + 2 unit files (+ `tests/Probes/`: 4 MariaDB/MySQL-only probe scripts, not PHPUnit), run with `php artisan test`.

| | SQLite (secondary) | **MariaDB 10.11.14 (authoritative)** | MySQL 8.0.46 (Phase 8 T2, T3) |
|---|---|---|---|
| Tests | 2070 passed, 8 skipped | **2078 passed** | 2075 passed |
| Assertions | 11218 | 11262 | 11259 |
| Failures | 0 | **0** | 3 — pre-existing JSON key-order test assumptions, fail identically on the original baseline (§8.4) |

The 5 SQLite skips: 4 foreign-key tests SQLite cannot express + P5-5's real-concurrency test (needs MariaDB; it runs the payment probe against `wa_throwaway_probe`). **MariaDB is authoritative —
a green SQLite run does not compensate for a MariaDB failure.**

**Frontend** (`frontend-app`, vitest + Testing Library): **19 test files, 560 tests, 0
failures** (+3 Phase 8 T2, +2 P5-8, +6 P5-6/P5-7; previously 549) (+2 Phase 7 Task 4, +2 Task 5) after the manual-source lock (417 before Task 8; +87 Task 8, +19 Task 9; Task 10 none; +1 Task 11; +11 Task 12; +7 Add-lead fix; +2 own-CRM fix; +1 source lock). `npm run build` (tsc -b + vite build)
clean; `npm run lint` (oxlint) 64 warnings / 0 errors — all 64 pre-existing, none in CRM files.

Running the suite needs `JOURNEY_REGISTRY_PATH` pointed at
`frontend-app/src/journey/nodeRegistry.tsx` (the backend asserts the journey node palette
against the frontend registry).

---

## 7. Standing Engineering Findings

Established experimentally. These constrain future work — do not re-derive them.

### MariaDB 10.11.14 foreign keys (proven, not assumed)

| Attempt | Result |
|---|---|
| Composite FK + `ON DELETE SET NULL` where any child column is `NOT NULL` | **Refused** — ERROR 1005 / errno 150 |
| `CHECK` constraint over a `SET NULL` column | **Refused** — ERROR 1901 |
| Composite FK + `RESTRICT` | Breaks `DELETE FROM accounts` — ERROR 1451 |
| Composite FK + `CASCADE` | **Works**; account deletion cascades cleanly |

### SQLite cannot `ALTER` foreign keys

All FK migration work is driver-guarded (`in_array($driver, ['mysql','mariadb'])`). A
migration that creates an FK on SQLite makes the later drop fail with *"unknown column in
foreign key definition"*.

### `LogsActivity` rows are skipped in console — but can be asserted in PHPUnit (Task 9)

`recordActivity()` returns early under `app()->runningInConsole() || !Auth::check()`, so by
default no `activity_logs` rows are written during a test run. **Task 9 established that a
test can flip the application's `isRunningInConsole` flag (reflection) for the duration of its
HTTP calls** and then assert the real rows — see
`CrmLeadBulkOperationsTest::test_bulk_operations_write_one_attributable_activity_row_per_changed_lead`.
The older workaround (assert the model event + `Auth::id()`) remains valid.

### `LogsActivity` update rows can name their record (Task 9)

`update` rows used to carry only the changed columns, so a status/assignee change could not be
traced to a lead. Models may now opt in with `protected array $auditIdentity = ['id'];`
(`CrmLead` does); their `update` rows include those attributes in `old_values`/`new_values`.
Every other model's audit payload is unchanged.

### Laravel global middleware changes validation semantics

`ConvertEmptyStringsToNull` ⇒ `''` is `null` everywhere. `TrimStrings` ⇒ `' contacted '`
is `'contacted'`. Write tests against actual behaviour, not assumed behaviour.

### Pre-existing Spatie guard bug (documented, NOT fixed)

All permission rows carry `guard_name: 'web'` while a Sanctum request resolves `'sanctum'`.
Consequence: **`POST /api/roles` returns 500 for every permission**, including pre-existing
ones such as `manage-social-leads` — which is how it was proven pre-existing rather than
CRM-introduced. Tests must build roles **before** the first `actingAs()`.

### Composite FKs declared in `CREATE TABLE` are enforced on SQLite

Only *ALTER*-added FKs are impossible on SQLite. `crm_lead_tags`' composite FKs are declared
in `CREATE TABLE` and are enforced on both engines (Task 7, verified).

### Audit rows can be verified over real HTTP

Under `php artisan serve` against a throwaway MariaDB DB, `LogsActivity` does write
`activity_logs` rows; Task 7 used this for row-level audit verification.

### ~~Super Admin client selection is lost on a hard page reload~~ — FIXED (CRM "Add lead" fix)

`TenantContext`'s "drop a selection that is not in the loaded account list" effect ran on the
first render, before the account list had started loading (`accounts = []`,
`isLoadingAccounts = false`), so a stored `super_admin_selected_account_id` was cleared on every
full reload — putting a Super Admin back in "All Clients (Global View)", where CRM Leads hides
its create action. It now prunes only after a list request has **succeeded**
(`hasLoadedAccounts`); a failed load keeps the selection. Reproduced first by
`core/context/TenantContext.test.tsx` (2 of 3 failed before the fix). The server still refuses
an invalid/foreign `?account_id=`, so nothing is exposed by keeping it until the list loads.
Affects every client-scoped page (all benefit).

### `frontend-app/.env.local` points the dev UI at a remote backend

`frontend-app/.env.local` (gitignored, machine-local) sets
`VITE_API_BASE_URL=https://sahilmoney.in/WapHubBackend/api`, overriding `.env`'s
`http://localhost:8000/api`. A UI run from that machine talks to that deployed backend and its
database, not to the local `wa_saas_platform`. Backend changes and data migrations must be
deployed there before they are visible in that UI.

### Laravel's middleware priority reorders route middleware (Task 11)

`ThrottleRequests` is in Laravel's priority list; custom middleware is not, so a route's
`throttle:*` was hoisted **ahead of** `log.apirequest`/`auth.apikey`. On `/api/v1` this made
the per-account limiter always fall back to 20/min per IP (`api_rate_limit_per_minute` was
dead) and left 429s unlogged. Fixed in `bootstrap/app.php` with `prependToPriorityList()`.
Check real order with `Router::gatherRouteMiddleware($route)`, not `route:list`.

### Unindexed `crm_leads.source`

A `source`-filtered grouped COUNT costs ~116 ms at 120k leads in one tenant. Below the bar
for a new index today; revisit if source-filtered dashboards become a primary access path.

**Task 12 re-measured it for analytics** (MariaDB 10.11.14, 300k leads, tenant of 100k):
all-time analytics (5 queries) best-of-3 155 ms; 30-day range 45 ms. `status`/`assignee`
groups are index-only on the existing `(account_id, status|assigned_user_id)` indexes; a dated
range uses `(account_id, created_at)`; all-time `source`/trend groups full-scan (the tenant is
a third of the table, so the optimizer's choice is correct). Candidate if it ever matters:
`(account_id, source)` / `(account_id, created_at, status)`. **No index added.**

---

## 8. Phase Status — what is built and what is pending

### 8.1 Execution track (live numbering)

| Phase | Scope | Status |
|---|---|---|
| 1 — Foundation | Capabilities, providers, provider_capabilities, plans, entitlements, usage quotas, agent selling entitlements | ✅ **DONE** |
| — Agent commissions | Commission rules, accrual, reversal, payouts | ✅ **DONE** |
| 3 — Developer API | API keys + secrets, request logging, idempotency, webhooks, `/api/v1`, audit | ✅ **DONE** |
| 4 — Meta provider | Meta Cloud API foundation, templates, send, webhook + HMAC hardening, security regression | ✅ **DONE** |
| 5 — Journey & plans | Journey node capability gating, plan→capability matrix, lifecycle reconciliation, DB-backed checkout, entitlement revocation, Super Admin plan management UI | ✅ **DONE** |
| **6 — CRM** | Contacts, leads, linking, assignment, lifecycle, pipeline, tags, frontend, bulk, Meta/Ads capture, entitlement/RBAC/Developer API, analytics. **Notes: out of scope** (backlog §8.4) | ✅ **DONE — closed 2026-09-23** |

#### CRM (Phase 6) task-by-task

| # | Task | Status | Migration | Suite total after |
|---|---|---|---|---|
| 1 | CRM Domain & Database Foundation | ✅ | 12 CRM migrations | 693 |
| 2 | Lead Creation & Source Management | ✅ | — | 752 |
| H1 | Hardening Round 1 (10 issues) | ✅ (1 deferred, 8 limitations logged) | — | 791 |
| H2 | Hardening Round 2 (all closed) | ✅ | FK moved to `crm_leads.capture_lead_id` | 857 |
| 3 | Contact ↔ Lead Linking | ✅ | none | 909 |
| 4 | Lead Assignment & Ownership | ✅ | none | 960 |
| 5 | Lead Status & Lifecycle | ✅ | none | 1031 |
| 6 | Lead Pipeline & Kanban Foundation | ✅ | none | 1077 |
| 7 | Lead Tags & Segmentation Foundation | ✅ | 3 (`crm_tags`, `crm_lead_tags`, `crm_leads` unique(id, account_id)) | **1170** |
| 8 | CRM Frontend Foundation (frontend only) | ✅ | none | **1170** backend (unchanged) · frontend 504 |
| 9 | CRM Bulk Operations & Productivity | ✅ | none | **1220** · frontend 523 |
| 10 | Meta / Ads Lead Capture → CRM Integration | ✅ | none | **1252** · frontend 523 |
| 11 | CRM Entitlement, RBAC & Developer API | ✅ | none | **1301** · frontend 524 |
| 12 | CRM Analytics & Phase Closure audit | ✅ | none | **1337** · frontend 535 |
| — | Phase 6 final closure (notes descoped; real DB verified read-only; MySQL 8 run) | ✅ **CLOSED** | none (real DB already at 110/110) | 1337 · frontend 535 |

> Tasks 1–5 and both hardening rounds were reported in full at the time but have no
> standalone file; the figures above are from this project's verified run records.

#### Phase 5 / Phase 6 final-audit fixes

The final verification audit (2026-09-24) left Phase 5 and Phase 6 **NOT CLOSED** (P5-1…P5-11,
P6-1…P6-3). Fixes are applied one item per task:

| Item | Fix | Status | Migration | Suite after |
|---|---|---|---|---|
| P5-1 | Group recipients frozen at reservation (`group_dispatch_recipients`); both group jobs iterate it | ✅ closed | `2026_09_24_120000` (new table) | **1446** |
| P5-2 | Social Inbox `lead:` reply (`SocialInboxController::sendToLead()`) sends through `DirectMessageDispatcher` instead of its own driver call — quota-gated, one `MessageQuotaService::consume()` on a confirmed send, `message_dispatch_logs` row with `source = social_inbox`. `SocialInboxLeadReplyDispatchTest` (17) | ✅ closed | none | **1495** (MariaDB) · 1491 + 4 skipped (SQLite) |
| P5-3 | Group jobs: atomic claim (`claim_token`/`claimed_at`), heartbeat before every send, `failed()` + caught exceptions settle from recipient rows (refund = reserved − delivered, once), `$timeout` 85 s + `failOnTimeout`, 50 s slices with continuation jobs, `group-dispatch:recover-stale` every 5 min. `GroupDispatchReliabilityTest` (49); MariaDB multi-process race + kill -9 + real `queue:work` slicing verified outside PHPUnit | ✅ closed | `2026_09_24_150000` (2 nullable columns + index) | **1544** (MariaDB) · 1540 + 4 skipped (SQLite) |
| P5-4 | Invoice snapshots the purchased plan terms at order creation (`plan_engine_type`, `plan_billing_model`, `plan_rate_per_message`, `plan_total_allocated_messages`, `plan_duration_days`, `plan_terms_captured_at`); fulfilment uses them, not the live plan; not fillable, hidden, immutable. `InvoicePlanTermsSnapshotTest` (24) | ✅ closed | `2026_09_24_160000` (6 nullable columns) | **1568** (MariaDB) · 1564 + 4 skipped (SQLite) |
| P5-6 | Journey secret / configuration protection. Only the `api` node's credential-named `headers`/`query` pairs carry secrets; they were stored and returned in plaintext (flow, versions, list/show/version responses, `activity_logs`). Now encrypted at rest (`JourneySecrets` + `MasksJourneySecrets`, Laravel `Crypt`), masked in every response and audit row, kept on update via the mask (unresolvable mask → 422), credentials in the URL refused (422). Frontend: masked value never displayed (password field, "Saved — type to replace"). `JourneySecretProtectionTest` (10). Existing plaintext rows are masked on read but NOT rewritten (data mutation — owner decision) | ✅ **CLOSED** | none | **1992** (MariaDB) · 1987 + 5 skipped (SQLite) · frontend 555 |
| P5-7 | Journey runtime execution & safety: backend runtime truth (`RUNTIME_EXECUTABLE_TYPES`); publish/activate validation (`JourneyPublishValidator`, 422 `JOURNEY_NOT_PUBLISHABLE`); run-time re-authorization of every catalog node; first-node permanent failure not consumed; default trigger no longer swallows chatbot-rule messages; deterministic trigger order; 24 h question expiry (`reply_timeout`, inline + `journeys:resume-due`); delay already non-blocking (Phase 7 T1) and duplicate inbound already de-duplicated (Phase 7 T3) — both re-verified. Frontend: palette marks the 20 non-executable nodes "Draft only", "Save draft" button, publish refused client-side with the blocking node names. `JourneyRuntimeSafetyTest` (24). 12 existing tests adapted to the new contract (drafts now sent with `publish: false`; two "question never expires" tests rewritten to the 24 h rule) | ✅ **CLOSED** | none | **1992** (MariaDB) · 1987 + 5 skipped (SQLite) · frontend 555 · probes: concurrency 8/8, deploy 13/13 |
| P5-8 | Entitlement audit logging. Gap: entitlement/authorization decisions were enforced but left no trace — `activity_logs` only recorded model mutations (LogsActivity), `journey_execution_events` only session history; a 403 at a capability/module/permission gate, a Journey node denied at save or run time, a cross-tenant id or a refused Agent target left nothing auditable. Now recorded through `EntitlementAuditLogger` into the existing `activity_logs` (see §4). No migration; no authorization rule changed (`JourneyNodeAuthorizer::decide()` backs the existing string methods). Frontend: Activity Logs page filters/badges for `allowed`/`denied`. `EntitlementAuditLoggingTest` (19) + `ActivityLogsPage.test.tsx` (2) | ✅ **CLOSED** | none | **2011** (MariaDB) · 2006 + 5 skipped (SQLite) · frontend 557 · probes: concurrency 8/8, deploy 13/13 |
| P5-5 | Payment fulfilment exactly-once — same-invoice duplicates were already safe (invoice row lock + `isPaid()`), but two different invoices of one account fulfilled at once rolled one back (entitlement unique-key clash, then an FK-lock deadlock): a paid order stayed pending. Fixed by locking the owner's account row (before the invoice write) and the current subscription. | ✅ **CLOSED** | none | **1958** (MariaDB) · 1953 + 5 skipped (SQLite) · payment probe 18/18 ×3 |

#### AI / Credit (Phase 8) task-by-task

| # | Task | Status | Migration | Suite total after |
|---|---|---|---|---|
| 1 | Credit System Foundation (accounts, immutable ledger, reservations, idempotency, Super Admin ops, RBAC) — `CreditSystemFoundationTest` (23) + `credit_concurrency_probe.php` | ✅ **CLOSED** | `2026_09_25_100000_create_credit_system_tables` (additive; fresh / upgrade-with-data / rollback / re-apply verified on throwaway MariaDB) — **not run on the real DB** | **2034** (MariaDB) · 2027 + 7 skipped (SQLite) · frontend 557 · probes: credit 24/24 (×3 rounds), journey concurrency 8/8, deploy 14/14, payment 6/6 |
| 2 | Credit Plans, Entitlements & Limits — `CreditPlanEntitlementTest` (21) + 5 plan-allocation probe checks | ✅ **CLOSED** | `2026_09_25_110000_add_plan_credit_allocation` (additive; fresh / upgrade-with-data / rollback / re-apply on MariaDB 10.11.14 and MySQL 8.0.46) — **not run on the real DB** | **2055** (MariaDB) · 2048 + 7 skipped (SQLite) · MySQL 8.0.46 2052 + 3 pre-existing · frontend 560 · probes: credit 39/39 ×3 rounds (MariaDB and MySQL 8.0.46) |
| 3 | Credit Ledger, Reservation & Consumption — `CreditConsumptionService`, partial consumption, direct spend, `CreditSpendGate`, failure codes, reservation expiry + cleanup — `CreditConsumptionTest` (23) + 7 spending probe checks | ✅ **CLOSED** | `2026_09_25_120000_add_credit_reservation_expiry` (additive; fresh / upgrade from pre-Phase-8 / upgrade from T2 schema with credit data / rollback / re-apply / no-op re-migrate on MariaDB 10.11.14 and MySQL 8.0.46) — **not run on the real DB** | **2078** (MariaDB) · 2070 + 8 skipped (SQLite) · MySQL 8.0.46 2075 + 3 pre-existing · frontend 560 (unchanged, no frontend change) · probes: credit 60/60 ×3 rounds (MariaDB ×3, MySQL 8.0.46 ×2) |
| 4 | (not specified) | ⏳ not started | — | — |

#### Journey (Phase 7) task-by-task

| # | Task | Status | Migration | Suite total after |
|---|---|---|---|---|
| 1 | Journey Foundation Audit + Temporal Execution Backbone | ✅ | 1 additive (`wait_until`, `attempts`, `last_error` + `(status, wait_until)` index on `whatsapp_flow_sessions`) — **not yet run on the real DB** | **1408** · frontend 545 (type only) |
| 1.5 | Journey Capability / Entitlement Hardening | ✅ | 1 data-only (`qr → journey_automation` supported) — **not yet run on the real DB**; then `entitlements:backfill-plan` for existing Growth accounts | **1419** · frontend 545 (unchanged) |
| 1.6 | Enforce Journey Entitlement at Runtime | ✅ | none (`blocked` is a new value of the existing string `status`) | **1431** · frontend 545 (type only) |
| 3 | Journey Trigger Reliability, Idempotency & Event Inbox | ✅ | 1: `2026_09_24_140000` (`inbound_message_events`, `journey_conversation_locks`) — **not yet run on the real DB** · qr-engine-service now sends `message_id` | **1478** · frontend unchanged |
| 2 | Journey Versioning and In-Progress Run Isolation | ✅ | 2: `2026_09_24_130000` (versions table + `published_version_id` + `flow_version_id`), `130001` (backfill: v1 per journey, sessions pinned) — **not yet run on the real DB** | **1461** · frontend 545 (type only) |
| 4 | Journey Condition / Branching Engine Hardening | ✅ | none | **1671** (MariaDB) · 1667 + 4 skipped (SQLite) · frontend 547 |
| 5 | Journey Action Execution Hardening | ✅ | none | **1718** (MariaDB) · 1714 + 4 skipped (SQLite) · frontend 549 |
| 6 | Journey Remaining Node Execution & Completion Semantics | ✅ | none | **1798** (MariaDB) · 1794 + 4 skipped (SQLite) · frontend 549 (unchanged) |
| 7 | Journey Observability, Audit & Operational Controls | ✅ | 1 additive: `2026_09_24_170000` (`journey_execution_events`) — **not yet run on the real DB** · `journeys:prune-history` exists but is a dry run by default and NOT scheduled (owner decision) | **1829** (MariaDB) · 1825 + 4 skipped (SQLite) · frontend 549 (unchanged) |

| 8 | Journey Quota, Retry & Entitlement Consistency | ✅ | none | **1862** (MariaDB) · 1858 + 4 skipped (SQLite) · frontend 549 (unchanged) |
| 9 | Journey Production Hardening & Recovery | ✅ | none | **1914** (MariaDB) · 1910 + 4 skipped (SQLite) · frontend 549 (unchanged) · probes: concurrency 8/8 (×6 runs), deploy 13/13 |

| 10 | Journey Final Release Readiness & Phase 7 Closure | ✅ | none | **1942** (MariaDB) · 1938 + 4 skipped (SQLite) · frontend 549 (unchanged) · probes: concurrency 8/8 (×3), deploy 13/13 |

#### Phase 7 closure record (Task 10) — **Phase 7 CLOSED** in code; NOT yet deployed to production

**Regressions found and fixed in Task 10** (both: a Task 1/1.6 inbound path undoing the Task 9
interrupted-run guarantee): (1) a customer message reaching an interrupted run (`active` + run lease)
expired it — it now falls through to the chatbot and the scheduler recovers the run; (2) an interrupted
run blocked by lost entitlement on the inbound path lost its timer and came back as "awaiting a reply" —
it now keeps a timer and returns to `waiting` on restoration.

**Journey capability** (runtime): `chatbot` module + `journey_automation` capability
(JourneyRuntimeEntitlement) — else sessions `blocked`, restored automatically. Palette send nodes also
need `whatsapp_send` (JourneyNodeAuthorizer: save/publish time via `denialFor`, run time via
`runtimeDenialFor`). API: auth → tenant.isolation → subscription.guard → module.guard:chatbot →
capability.guard:journey_automation → permission (manage-chatbot | whatsapp.view/create/edit/delete).

**Executable nodes (12)**: trigger, message, question, condition, save_lead (legacy, grandfathered —
no `whatsapp_send` check) · delay, conditional, text, image, video, document, audio (palette).
The other 20 palette types persist (as drafts — P5-7 refuses to publish/activate them) and end a run as `expired` / `unsupported_node` if one is reached in legacy data.

**State machine**: `WhatsAppFlowSession::TRANSITIONS` (see §4). Open: active, waiting, blocked. Terminal
(immutable; Eloquent save refused; every engine write is a guarded conditional UPDATE): completed,
failed, expired, cancelled. Legitimately indefinite: `active` awaiting a reply; `blocked` awaiting
entitlement; `waiting` on a deactivated journey (held, resumes on reactivation).

**Retry / recovery matrix** (tested: JourneyPhase7ReleaseReadinessTest::test_the_authoritative_failure_matrix)

| Failure | Outcome | Category |
|---|---|---|
| invalid configuration | `failed`, never retried | invalid_configuration |
| missing node | `failed`, never retried | missing_node |
| unsupported node | terminal `expired`, never retried | unsupported_node |
| provider failure | retried (60 s, 120 s…, 3 resume attempts) → `failed` | provider_failure |
| CRM failure (save_lead) | retried → `failed` | crm_failure |
| quota exhausted / plan expired | retried → `failed` | quota_failure |
| suspended account / no subscription | `failed` at once | entitlement_blocked |
| `whatsapp_send` revoked (palette node) | `failed` at once | entitlement_blocked |
| journey_automation / chatbot revoked | `blocked`, restored on re-entitlement | entitlement_blocked |
| execution limit (25 steps/run) | `expired` | execution_limit |
| cancellation | `cancelled` (terminal) | cancelled |
| worker/process crash | resumed from the durable checkpoint (claim lease / run lease, 600 s) | internal_error |
| terminal session | never revived by inbound, scheduler, job, restore, recovery, API or test | — |

(There is no separate "suspended subscription": `Subscription::computeStatus()` yields only
active/expired/exhausted; suspension is the account status.)

**Exactly-once limitation**: provider delivery is at-least-once. A crash after the provider accepted a
message and before the next checkpoint re-sends that one node on recovery (if it also preceded the quota
consume/dispatch log, that first delivery is unmetered/unlogged). save_lead is idempotent; only its
completion message can repeat. History is written after the fact (a crash can lose one row, never state).

**Production deployment sequence** (owner-run; nothing has touched `wa_saas_platform`):
1. Back up `wa_saas_platform`.
2. Deploy backend + frontend + qr-engine-service code.
3. `php artisan migrate --force` — the 10 pending migrations, in order: `2026_09_23_130000` (platform CRM
   account), `2026_09_24_100000` (temporal columns), `110000` (qr → journey_automation), `120000` (group
   recipients, P5-1), `130000` + `130001` (versions + backfill), `140000` (inbound events + locks),
   `150000` (group claim, P5-3), `160000` (invoice plan terms, P5-4), `170000` (execution history)
   — **now 13**: plus `2026_09_25_100000` (credit system tables, Phase 8 Task 1), `110000` (plan
   credit allocation, Phase 8 Task 2) and `120000` (reservation expiry, Phase 8 Task 3); all additive, no data step. `migrate:rollback --step=13` for
   all of them. After migrating: set real `included_credits` per plan (all 0 today), then
   `php artisan credits:backfill-plan-allocation --dry-run` → review → run without `--dry-run`.
   Proven on MariaDB 10.11.14 by `tests/Probes/journey_deploy_probe.php` (fresh; upgrade from the
   110-migration schema with legacy journeys/sessions/leads; rollback; re-apply; idempotent backfills).
   All ten have `down()` (`130001`'s is intentionally a no-op — the backfilled versions go with `130000`'s
   drop); roll back with `migrate:rollback --step=13` (10 + the three Phase 8 migrations) only from a backup-verified state.
4. `php artisan entitlements:backfill-plan --dry-run` (review).
5. `php artisan entitlements:backfill-plan` (idempotent).
6. Restart: queue workers (`php artisan queue:restart`), qr-engine-service (sends `message_id`, Task 3),
   PHP-FPM/opcache.
7. Verify the scheduler (`php artisan schedule:list` shows `journeys:resume-due` and the
   `queue:work database --queue=journeys` entry every minute, plus `credits:release-expired-reservations`
   every 5 minutes — Phase 8 T3) and that `schedule:run` is in cron.
8. Smoke test: a test journey (keyword → text → delay 1 min → text) via the Test button; session
   completes after ≥1 scheduler minute.
9. QR: send a message to a QR number; `inbound_message_events` row with the Baileys `message_id`.
10. Meta: webhook verify (GET) + a real message; `inbound_message_events` row with the WAMID.
11. `GET /api/whatsapp/flows/{id}/sessions/{sessionId}` shows the history (session_started … session_completed).

**Runtime processes**
| Process | Needed for |
|---|---|
| Web/PHP (Meta webhook `POST /api/webhooks/meta`, QR `POST /api/internal/whatsapp-inbound`) | inbound journeys: start, answers, immediate sends, immediate retries parking |
| qr-engine-service | QR inbound (with `message_id` for dedup) and all QR sends |
| Laravel scheduler, every minute (`journeys:resume-due`: restore blocked → recover interrupted → dispatch due) | delays, all retries, interrupted-run recovery, entitlement restoration |
| `queue:work database --queue=journeys` (scheduled every minute, `--stop-when-empty`) | executing the dispatched resumes |
| (none extra) | execution history — written inline; never load-bearing |
| `journeys:prune-history` — MANUAL only (dry run unless `--force`; 90-day events except open sessions, 30-day unreferenced inbound keys) | retention, owner decision; not scheduled |

Monitoring: watch `whatsapp_flow_sessions` for `waiting` rows with `wait_until` far in the past (scheduler
or worker down) and `failed` counts by `journey_execution_events.error_category`.

**Known intentional limitations**: at-least-once provider delivery; ~~no expiry for sessions awaiting a
reply~~ (P5-7: they expire after 24 h, `reply_timeout`); immediate runs check journey entitlement at start, resumed runs before every node; legacy nodes
are grandfathered from `whatsapp_send`; 20 palette node types are not executable (P5-7: draft-only — a journey containing one cannot be published or activated); subscription.guard
refuses Journey writes (incl. cancel) while the plan is not active; history pruning is manual.

~~Task 7 finding: palette text/media nodes failed permanently on an exhausted quota while `message`
retried~~ — **resolved by Task 8** (all sends retry `quota_failure`). Task 8 also found and fixed: a
run judged every send against the usage it saw when it started, so one run could send past the cap.

Task 1 audit findings (the brief assumed more than exists): there are **no** journey
`versions`, `runs`, `nodes`/`edges` or `triggers` tables — a journey is one `whatsapp_flows`
row (graph JSON + a single keyword/ctwa_referral/default trigger) and a run is one
`whatsapp_flow_sessions` row. Pending owner decisions: (a) flow versioning — edits apply to
in-flight/waiting runs immediately — **closed by Task 2**; (b) Super Admin journey target — flows still require a
selected client (422) and SA bypasses node entitlements, unlike CRM's `crm.target`; (c) ~~no
route-level `journey_automation` gate~~ — **closed by Task 1.5**; ~~execution not
capability-gated~~ — **closed by Task 1.6**; (d) ~~QR inbound has
no redelivery dedup; Meta's is a cache claim~~ — **closed by Task 3** (durable DB claim; QR sends
the Baileys message id).

### 8.2 Long-range roadmap (architecture report §27 numbering)

| Report phase | Scope | Status against code |
|---|---|---|
| 0 — QR stabilization | Login throttling, async bulk upload, real queue worker | ⚠️ **UNVERIFIED** — not confirmed in this codebase; treat as open |
| 1 — Architecture foundation | Provider/plan/billing split, module gates, first real tests | ✅ Done (test suite now 1077, 17 modules gated). ❌ **SoftDeletes never added** |
| 2 — Meta WhatsApp foundation | Productize `MetaCloudApiDriver` | ✅ Done |
| 3 — Meta messaging suite | WABA embedded signup, broadcast parity, unified inbox, Flows/catalog | 🟡 Partial — Flows tables exist; embedded signup and full parity **not** built |
| 4 — Automation engine | `delay`/`wait` node, recurring triggers, paused-execution persistence | 🟡 Partial — **durable delay execution built (Phase 7 Task 1)** on `whatsapp_flow_sessions` (waiting + `wait_until`, scheduler + database queue, retry, cancel). Recurring triggers and flow versioning **not** built |
| 5 — CRM *(= execution Phase 6)* | Contact/Lead/**Deal/Pipeline/Stage/Task/Note/Tag** | 🟡 Contacts, Leads, a status-based pipeline and tenant-scoped lead **tags** exist. **No `deals`, `crm_tasks` or `crm_notes` tables** |
| 6 — AI Platform *(= execution Phase 8)* | Content generation + append-only credit ledger | 🟡 **Credit ledger, plan credits and spending layer built** (Phase 8 Tasks 1–3: `credit_ledger_entries`, not `ai_credit_transactions`; `CreditConsumptionService`); AI provider / usage / pricing not built |
| 7 — Social media | LinkedIn OAuth, organic scheduling/analytics | 🟡 Meta only. `SocialOAuthProviderFactory` **throws** for `linkedin` and `google` |
| 8 — Catalog/ecommerce | Commerce capability | ❌ Capability seeded; no implementation |
| 9 — Ads + attribution | Full ad → deal → conversion chain | ❌ Blocked on a Deal model existing |
| 10 — Advanced analytics | Extend analytics to CRM/AI/Ads data | ❌ Not started |

### 8.3 Confirmed gaps (verified against code, not inferred)

- ~~No CRM frontend~~ — **closed by Task 8.** `frontend-app/src/pages/crm/` now covers
  leads (list + detail), pipeline, contacts (list + detail) and tags. See §5, "CRM frontend (Task 8)".
- **No Deal / Task / Note entities** — the CRM is Contact + Lead + lead Tags only. **CRM Notes
  are explicitly OUT OF SCOPE for Phase 6** (owner decision, 2026-09-23). The implemented
  activity/history capability is the Activity Log: `activity_logs` rows written by
  `LogsActivity` on CrmLead, Contact, CrmTag, CrmLeadTag, CrmCaptureLinkFailure and
  ContactGroupMember (module "CRM"). No notes table, API, UI, route, service or test exists.
  Backlog: §8.4.
- ~~Task 7 migrations on the real DB unverified~~ — **verified 2026-09-23** (read-only, MySQL
  Workbench "Local Instance" root@127.0.0.1:3306 = `.env`): `migrations` has 110 rows (= the 110
  files), last `2026_09_23_100002_create_crm_lead_tags_table`, batch 47; all 15 Phase 6
  migrations recorded; `crm_tags` and `crm_lead_tags` exist with their composite FKs;
  `crm_leads_id_account_id_unique` = UNIQUE(id, account_id). **No migration was run; no schema
  changed.**
- **The real local DB server is MySQL 8.0.41, not MariaDB.** The full suite was additionally run
  on a throwaway MySQL 8.0.46: 1335/1337 — every CRM test passes (679); the 2 failures are
  pre-existing, outside Phase 6 (`JourneyNodeEntitlementTest` "round trips unchanged": MySQL's
  JSON type reorders object keys; content is equal). Not fixed. MariaDB remains the suite's
  authoritative engine; consider making MySQL 8 authoritative if it is the deployment engine.

### 8.4 Backlog (recorded, not scheduled)

| Item | Origin | Notes |
|---|---|---|
| **CRM Notes** (per-lead / per-contact free-text notes) | Descoped from Phase 6 at closure | Would need its own table, tenant-safe composite FKs, API under the CRM gates, UI on lead/contact detail, audit via LogsActivity. Not started. |
| CRM Deals / Tasks | Roadmap §27 (CRM) | Not started. |
| CTWA `source` backfill (`whatsapp` → `meta_ad` for pre-Task-10 leads) | Task 10 | Optional data update; needs owner authorization. |
| Journey JSON round-trip tests on MySQL 8 | Phase 6 closure MySQL run; re-confirmed Phase 8 T2 on MySQL 8.0.46 | Test assumption (MySQL's JSON type reorders object keys): `JourneyNodeEntitlementTest::test_a_legacy_journey_round_trips_unchanged_through_update`, `JourneyNodePaletteTest::test_a_node_configuration_round_trips_unchanged`, `JourneyObservabilityTest::test_the_recorder_keeps_rows_bounded_and_categories_normalised` — content equal, order differs; fail identically on the pre-Phase-8 baseline. (The P5-6/P5-8 tests with the same assumption were made key-order-neutral in Phase 8 T2.) |
- ~~No AI credit ledger~~ — **closed by Phase 8 Task 1** (Credit System Foundation); plan credits Task 2; spending/reservation layer Task 3. AI provider execution and pricing are still not built.
- ~~No durable journey-execution persistence~~ — **closed by Phase 7 Task 1** (waiting sessions,
  resume scheduler). ~~No flow versioning~~ — closed by Phase 7 Task 2. ~~No expiry sweep for
  sessions stuck at a question~~ — closed by P5-7 (24 h `reply_timeout`). Immediate-path send
  failures are retried/failed since Phase 7 Task 5.
- **P5-6 follow-ups (owner decisions, not done):** journeys stored BEFORE P5-6 may still hold
  plaintext credential values in `whatsapp_flows`, `whatsapp_flow_versions` (immutable) and
  historical `activity_logs` rows. They are masked in every API response, and a flow row is
  encrypted on its next save; rewriting the stored rows is a data mutation that needs explicit
  authorization. The `code` node's `code` and the `api` node's `body` are free text and are NOT
  treated as secret (a credential typed there is stored as typed; neither node can run).
- **No soft deletes anywhere.**
- **LinkedIn / Google OAuth unimplemented** — the factory throws by design.
- **`POST /api/roles` is broken** by the Spatie guard mismatch (§7).
- **CTWA leads promoted before Task 10 carry `source = whatsapp`** — Task 10 changed the
  mapping for new captures only; no data was rewritten. They are identifiable by
  `crm_leads.capture_lead_id → leads.provider = 'whatsapp_ctwa'`.
- **No view-only CRM permission** — `manage-crm` is the single CRM permission (read + write).
  "Read-only" today means an expired subscription (reads allowed, writes 403).
- **Developer API CRM writes have no `activity_logs` row** — attributed only via
  `api_request_logs` (account, key, method, path, status), not old/new values.
- **No UI or API for `crm_capture_link_failures`** — `retry()` exists as a service method
  only; `not_entitled` captures are promoted only when something calls it.

---

## 9. Working Conventions

### Frontend page layout

See `CLAUDE.md`. Use **Pattern A** (plain `p-6` wrapper, no duplicate header) for new
pages; `PageShell`/`PageHeader` is legacy.

### Verification pipeline applied to every backend task

1. Targeted suite for the new work
2. Full SQLite suite (secondary)
3. Fresh **throwaway** MariaDB database → full suite (**authoritative**)
4. Migration forward → rollback → forward, **throwaway database only**
5. `EXPLAIN` on MariaDB with realistically seeded data, where query shape changed
6. `php -l` on every changed file + `php artisan route:list`
7. md5 drift-compare before committing
8. `expectedMtimeMs`-guarded commit, then md5 re-verification

### State protection (non-negotiable)

**The real database `wa_saas_platform` is never migrated, seeded or written to by
development tooling.** All schema work uses throwaway databases. `RefreshDatabase` wipes
its target — never point a test run at the real database.

### Task discipline

Tasks arrive as tightly-scoped specs. The standing rules: inspect existing code before
proposing changes; gap-fill rather than rebuild; preserve QR/Baileys, Meta, Journey and
billing behaviour; no unrelated refactors; never weaken, skip or delete existing tests;
full regression on both engines; do not widen `/api/v1` as a side effect.

---

## 10. Keeping This File Current

**Update this file at the end of every task**, before reporting completion. At minimum:

- the header table (date, last completed work, suite totals, migration count, next up)
- §8.1's task table — new row, status, migration, new suite total
- §8.3 if a confirmed gap was closed or a new one found
- §7 if a new standing finding was established
- §4/§5 if the schema or API surface changed

Reference documents (historical, **not** maintained):
`Claude outputs/wa-saas-platform-architecture-report.md` (the long-range roadmap, §27),
`Claude outputs/wa-saas-platform-phase1-foundation-plan.md`, and ~40 dated audit files in
`Claude outputs/`. They are snapshots of their date — **this file supersedes them wherever
they disagree.**
