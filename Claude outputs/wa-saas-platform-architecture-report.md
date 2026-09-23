# wa-saas-platform — Comprehensive Technical & Product Architecture Report

**Prepared:** 2026-09-18 | **Method:** Direct, read-only source inspection of the live repository (`~/mnt/wa-saas-platform` on the user's machine, HEAD `34800e2`) via seven parallel research passes, each independently re-verifying prior AI-authored audit documents (`Claude outputs/*.md`) against current code rather than trusting them. No code was executed, no server started, no destructive action taken.

**Evidentiary key**, used throughout: **[Fact]** — personally verified against the current file (file:line cited); **[Inference]** — a conclusion drawn from verified facts; **[Hypothesis]** — plausible, not independently confirmed; **UNKNOWN — requires confirmation** — cannot be determined from the codebase, needs a human answer. Severities for security findings: CRITICAL / HIGH / MEDIUM / LOW. Priorities: P0 = must fix before continuing, P1 = required for next phase, P2 = important but can wait, P3 = future optimization.

**This report's premise, honored throughout:** the codebase is not small or simple (53 controllers, 36 models, 35 services, 50 database tables, three independently-run services, ~35 prior audit documents already written against it), it is not being recommended for a rewrite, and the QR/Baileys WhatsApp engine — which works — is not touched. Every recommendation below is additive or corrective, not a replacement of working systems, unless explicitly flagged otherwise with its risk called out.

---

## 1. Executive Summary

This is a mature, actively-developed three-service platform (Laravel 11 backend, React 19 frontend, Node/Baileys QR engine) with a genuinely working core: WhatsApp QR messaging, a real 3-tier Agent/Client hierarchy with server-enforced (not just UI-hidden) tenant isolation, a working Meta Ads launcher with Click-to-WhatsApp lead capture, and — as of this session — a cleaned-up per-message billing/balance model. The single most important structural finding for the platform's stated future direction is a positive one: **a real `WhatsAppDriverInterface` provider abstraction already exists and is already used at 14+ call sites**, meaning the "add Meta Cloud API as a second provider without rewriting QR" goal is already substantially achieved at the code level — the gap is that Provider, Plan, Billing Model, and Usage Limit are currently conflated into one `subscriptions` table rather than kept as independent dimensions (§14, §26).

The platform has no CRM, no AI content-generation platform, and no automation-engine with delay/recurring capability — all three are genuinely greenfield, confirmed by direct grep rather than assumed (§16–18). It does have a real trigger→condition→action graph engine already (`WhatsAppJourneyEngine`) whose execution core is a strong foundation for a future generalized automation engine, but its temporal backbone (queue worker, delay persistence, recurring schedules) does not exist and would need building from scratch (§15).

The most severe unaddressed finding across the whole audit is **HIGH**: `/auth/login` has no rate limiting or lockout of any kind (§20). The most severe structural risk is the **complete absence of automated test coverage** (2 unmodified scaffold tests total) for a multi-tenant billing/messaging system whose own prior audits describe its tenant-isolation and quota logic as security-critical (§23). Deployment is entirely manual (SSH + hand-run Artisan commands), with a CI workflow that verifies the build but deploys nothing (§31).

None of this calls for a rewrite. It calls for: closing the login-throttling gap (P0), building a real test suite starting with tenant-isolation/RBAC/quota regression tests (P0/P1), separating Provider/Plan/Billing-Model as independent schema dimensions before Meta becomes a second real provider (P1), and building CRM/Automation/AI as new modules on top of the existing account/module/permission scaffolding rather than beside it. The phase-wise roadmap in §27 sequences this.

## 2. Current System Understanding

**[Fact]** Three independently-run services in one repository: `backend-api` (Laravel 11.31, PHP 8.2+, Sanctum, spatie/laravel-permission ^6.25) is the system of record — all business logic, the database, the REST API; `frontend-app` (React 19.2.8, TypeScript, Vite 8.2.2, react-router-dom 7.18.3) is the tenant/Super-Admin SPA; `qr-engine-service` (Node/Express 5.2.1, Socket.IO 4.8.3, `@whiskeysockets/baileys` 7.0.0-rc14) holds live WhatsApp Web sessions, one Baileys socket per tenant `account_id` in an in-process `Map`, talking to `backend-api` over an internal, shared-secret-authenticated HTTP hop (`X-Internal-Secret`, constant-time comparison both directions).

**[Fact]** 50 database tables (verified against `wa_saas_platform.sql`, a committed MySQL dump, cross-checked against the live 74-file migration directory — only 4 additive columns postdate the dump, no new tables). 866-line `routes/api.php` with 156 hand-declared HTTP routes across three auth tiers (external API-key, internal Sanctum-authenticated dashboard, internal shared-secret webhook). 53 controllers, 36 models, 35 services, 7 queued Jobs, zero Events/Listeners (the codebase does not use Laravel's event system at all — cross-cutting concerns go through a `LogsActivity` trait called directly).

**[Fact]** ~35 prior AI-authored audit documents already exist in `Claude outputs/` at repo root, dated 2026-09-09 through 2026-09-17, several of them (`PROJECT_ARCHITECTURE_DATABASE_BLUEPRINT.md`, `FULL_PROJECT_ANALYSIS*.md`) already extremely thorough. This report re-verified their load-bearing claims against current code rather than re-deriving from zero, and found the large majority still accurate, with several specific gaps now closed (Meta webhook HMAC, `RoleController` privilege escalation, group-count undercounting, social/ads media upload and CTWA) and a few claims now stale (quota-request permission tier changed; an inbound-webhook docblock claiming "no listener wired" when one now exists). Section-by-section detail below cites exactly which.

## 3. Business Model Understanding

*(This section reflects the business direction as stated by the user in this request — it is context, not a code-verified claim, and is marked as such throughout.)*

**[Stated]** The product started as WhatsApp messaging SaaS and is expanding toward: Meta Cloud API alongside QR, templates/campaigns/groups/automation, a CRM, AI content generation, Facebook/Instagram social integration, ads and lead generation, an Agent/reseller model alongside direct subscription, and industry-specific workflows (money transfer, coaching/schools, clinics/hospitals, e-commerce, real estate, generic SMB) — explicitly **not** hard-coded per industry. **[Stated]** The hierarchy is Super Admin → Agent → Agent's Client/Admin, with Super Admin also able to directly manage clients; Agents must only see their own clients, never another Agent's; permission delegation must never let a child exceed its parent's grant; UI-hiding must never be the only enforcement layer. Every one of these stated requirements is independently checked against actual code in §9 and §20 below — the business requirement is treated as the target to verify against, not assumed to already be true.

**[Stated]** Pricing direction: QR offered free/cheap via Agents (low provider cost, customer-acquisition lever); possible per-message WhatsApp pricing (~₹0.20/message) or fixed/monthly/unlimited packages; Meta priced separately due to provider/template costs; AI with free daily credits plus paid top-ups, separate image-generation credit type. §32 evaluates the *architecture* implications of this without inventing real provider costs.

## 4. Current Architecture

**Service-to-service auth, both directions** [Fact]: backend-api → qr-engine-service (`POST /api/qr/start-session`, `/logout`) is gated by `requireInternalSecret` in `qr-engine-service/src/server.js:167-187`, comparing `X-Internal-Secret` via `crypto.timingSafeEqual`. qr-engine-service → backend-api (`/api/internal/whatsapp-status`, `/whatsapp-inbound`) is gated by `VerifyInternalSecret` middleware (`backend-api/app/Http/Middleware/VerifyInternalSecret.php:25-44`), `hash_equals()` against `config('services.qr_engine.internal_secret')`. Shared secret is `INTERNAL_API_SECRET`; both sides fail closed outside local dev. **[Inference]** A documented local-only placeholder fallback exists identically in both `.env.example` files — safe for local dev only, a footgun if `APP_ENV=local` is ever set in a shared/staging environment.

**Browser ↔ qr-engine-service live channel** [Fact]: Socket.IO, authenticated by the browser sending its Sanctum bearer token + `accountId` in the handshake; qr-engine-service calls back to backend-api's `/api/auth/me` to resolve the token, then independently enforces tenant isolation (`server.js:124-141`, `backendClient.js:67-101`). A previously-fixed bug (`user.role` singular vs. actual `user.roles` array) that made Super Admin's own status stream always reject as forbidden is confirmed fixed.

**Directory layout** [Fact]: standard Laravel MVC for `backend-api` (`app/{Http,Models,Services,Jobs,Console,Providers,Support,Traits}`); `qr-engine-service` deliberately thin (3 source files — `server.js`, `sessionManager.js`, `backendClient.js`); all Baileys session state lives in filesystem auth-state, never in a DB table (no `whatsapp_web_sessions` table exists — the only WhatsApp-session table, `whatsapp_sessions`, is exclusively Meta Cloud API fields).

**New finding — an orphaned migration** [Fact]: `Claude outputs/2026_09_16_999999_sync_production_baseline_schema.php` (1,202 lines, a defensive full-schema-reconstruction migration per its own docblock) sits in the `Claude outputs/` docs folder, **not** in `backend-api/database/migrations/`. Laravel's migrator only scans the configured migrations path, so **[Inference]** this migration cannot currently run at all — either deliberately shelved reference material or a dropped task. **UNKNOWN — requires confirmation.**

## 5. Current Module Inventory

**backend-api/app/** [Fact, via `find`]: 53 controllers (1 root + 51 under `Api/`, including `Api/Admin/`, `Api/Internal/`, `Api/V1/`); 36 models (flat, no subdirectories); 35 services across 15 domain subdirectories (`Ads`, `Ai`, `Billing`, `Chatbot`, `Comments`, `Groups`, `Leads`, `Payment`, `PaymentAlerts`, `Pdf`, `Social`, `SocialAuth`, `Templates`, `Webhooks`, `WhatsApp`); 7 Jobs; **0 Events/Listeners** (directories don't exist — cross-cutting logic goes through `app/Traits/LogsActivity.php` called directly, a synchronous/coupled pattern rather than decoupled event-driven); 6 custom middleware classes (`ApiAuthMiddleware`, `AuthenticateApiKey`, `EnsureModuleEnabledMiddleware`, `SubscriptionGuardMiddleware`, `TenantIsolationMiddleware`, `VerifyInternalSecret`).

**Two independently-evolved API-key auth mechanisms** [Fact]: `AuthenticateApiKey` (single-factor, `X-API-KEY`, SHA-256-hashed lookup) and `ApiAuthMiddleware` (dual-factor key+secret, scoped to newer `/v1/whatsapp/*` routes only). **[Inference]** Individually sound, but overlapping-surface duplication is a maintenance-consistency risk worth consolidating (P2).

**Tenant isolation is per-request, not a model-level global scope** [Fact]: only `Account.php` uses `addGlobalScope`; no shared "BelongsToTenant" trait exists. `TenantIsolationMiddleware` computes and stashes `account_id`/`is_super_admin`/`agent_scope_id` as request attributes; each controller is responsible for applying that filter itself (the `ResolvesTenantAccount` trait is the consistent, reused pattern for doing so). **[Inference]** This is a real (if common) architecture risk class: a controller that forgets to apply the request-scoped filter could leak cross-tenant data — nothing at the Eloquent/DB layer enforces it automatically. §9/§20 verify the specific controllers most exposed to this and find them correctly guarded; a full 53-controller sweep was out of this pass's scope (**P1** — build an automated tenant-isolation regression test suite, §30).

**frontend-app/src/** [Fact]: 31 pages across 12 domain subfolders, 31 services (1:1 with pages/domains, all funneling through one shared `axiosInstance`), 27 components, 35 `<Route>` elements in `App.tsx` (31 protected + 3 public + 1 catch-all).

## 6. Database Architecture

**Full table list (50)** [Fact]: `accounts`, `activity_logs`, `ad_campaign_daily_metrics`, `ad_campaigns`, `api_keys`, `cache`, `cache_locks`, `chatbot_logs`, `chatbot_rules`, `comment_automation_events`, `comment_automation_rules`, `contact_group_members`, `contact_groups`, `failed_jobs`, `in_app_notifications`, `invoices`, `job_batches`, `jobs`, `leads`, `login_audit_logs`, `mail_logs`, `mail_settings`, `message_dispatch_logs`, `message_templates`, `migrations`, `model_has_permissions`, `model_has_roles`, `notification_broadcasts`, `notification_templates`, `organic_posts`, `password_reset_tokens`, `payment_alerts`, `payment_gateway_settings`, `permissions`, `personal_access_tokens`, `quota_requests`, `role_has_permissions`, `roles`, `route_categories`, `sessions`, `social_accounts`, `social_provider_configs`, `subscriptions`, `system_routes`, `users`, `webhook_deliveries`, `webhook_subscriptions`, `whatsapp_flow_sessions`, `whatsapp_flows`, `whatsapp_sessions`. No duplicate/near-duplicate tables found.

**Tenant-key consistency** [Fact]: every tenant-scoped table uses `account_id` consistently (never `tenant_id`). `accounts` is the tenant root, `account_type enum('super_admin','agent','client')` + a self-referencing `agent_id` FK encode the whole 3-tier hierarchy in one table.

**Findings, CURRENT MODEL → PROBLEM → RECOMMENDED CHANGE → MIGRATION RISK:**

1. **[Fact]** `accounts.agent_id` and nearly every tenant table's `account_id` FK are `ON DELETE CASCADE`. **Problem:** deleting one Agent physically cascades through to delete all its Clients and their entire billing/message/audit history — combined with zero soft-delete support, this is unrecoverable. **Recommendation:** move destructive account deletion to a soft-delete/deactivation flow (the `accounts.status` column already supports this pattern via `Account::isAdministrativelyActive()`); reserve hard cascade for genuinely disposable child data. **Risk:** Low to add `deleted_at` (additive); higher risk to change existing FK `ON DELETE` behavior on populated tables — needs a maintenance window, not a casual migration. **Priority: P1.**
2. **[Fact]** Zero tables have `deleted_at`; zero models use `SoftDeletes`. **Problem:** every delete the UI/API exposes is permanent; no undo/recovery mechanism beyond the purpose-built `activity_logs` (opt-in per call site, not automatic). **Recommendation:** add `SoftDeletes` to the highest-risk tables first — `accounts`, `invoices`, `subscriptions`, `message_templates`. **Risk:** Low (additive nullable column); behavior-change risk in queries needing `withTrashed()` awareness. **Priority: P1.**
3. **[Fact]** `activity_logs.agent_id` has an index but **no FK constraint**, unlike its sibling `user_id`/`account_id` on the same table. **Recommendation:** add the matching FK (`ON DELETE SET NULL`), after first checking for orphaned values (an `ADD CONSTRAINT` on a populated table with orphans will fail). **Risk:** Low-medium. **Priority: P2.**
4. **[Fact]** `message_dispatch_logs.template_name`/`group_name` are freetext snapshots with **no FK** to `message_templates`/`contact_groups`. **Problem:** could be a deliberate audit-snapshot design (reasonable for a log table) or an oversight — recent commits ("fix the template id to template code") suggest active churn here. **Recommendation:** document intent explicitly if snapshot-by-design; otherwise add a nullable `template_id` FK alongside. **UNKNOWN — requires confirmation** which it is. **Priority: P2.**
5. **[Fact]** Status columns are inconsistently typed — most `varchar` with app-level validation, a few true SQL `enum` (`message_templates.status`, `accounts.account_type`, `mail_logs.status`). **Recommendation:** not a blanket enum conversion (MySQL enums are awkward to extend); document the intended value set per varchar column as a Model constant consistently, rather than mixing styles further. **Risk:** none if documenting only. **Priority: P3.**
6. **[Fact/Inference]** `message_templates.account_id` and `notification_broadcasts.account_id` are nullable with `ON DELETE SET NULL` — almost certainly deliberate (global/platform-wide records, e.g. industry-level shared templates), not orphaned data, given `message_templates.industry_type` exists alongside. **UNKNOWN — requires confirmation** that every listing query correctly excludes NULL-account_id rows from tenant-scoped views where it shouldn't leak.
7. **[Fact]** No table stores Baileys/QR credentials — that state lives entirely on the qr-engine-service filesystem, outside the database's backup/replication story. **UNKNOWN — requires confirmation** of the actual backup/rotation policy for those session files (out of this pass's scope).
8. **Audit coverage** [Fact]: `activity_logs` (before/after JSON, account/agent/user-scoped) and `login_audit_logs` (point-in-time snapshot at login) are real and used. **[Inference]** Coverage is opt-in per call site (`LogsActivity` trait), not automatic for every mutation — full coverage across all 53 controllers not exhaustively verified. **UNKNOWN — requires confirmation.**
9. **Indexing** [Fact]: spot-checked high-traffic tables (`message_dispatch_logs`, `activity_logs`, `payment_alerts`) show composite indexes well-matched to actual query patterns (e.g. `(account_id, created_at)`, `(account_id, status)`). No missing index found in the tables checked — the one gap found was the missing FK in #3, not a missing index.

**Migration history** [Fact]: 30 batches spanning 2026-09-08 through 2026-09-17, consistent with iterative daily feature delivery matching the dated audit-document series.

## 7. API Architecture

**Route inventory** [Fact]: 156 hand-declared verb routes (69 GET, 58 POST, 12 PUT, 10 DELETE, 7 PATCH) in `routes/api.php`, zero use of `Route::apiResource`. Grouped by domain: auth, internal webhooks, external v1 API, billing, roles, whatsapp setup, meta-config, developer settings, chatbot, social (largest domain, ~9 sub-groups), team, alerts, contact groups, analytics, message logs, audit, notifications, and a separate `admin/*` block.

**Three auth tiers coexist** [Fact]: (1) `auth.apikey`/`auth.apisecret` + `throttle:external-api` for the external partner API under `/v1`; (2) `auth:sanctum` + Spatie `permission:`/`role:` for the internal dashboard (~145 of 156 routes); (3) `internal.secret` for the QR-engine webhook. Several groups additionally stack `module.guard:<slug>` on top — correct defense-in-depth where present (§9 covers where it's *not* present).

**Versioning is real but narrow** [Fact]: `Api/V1/` (4 controllers) serves only the external partner surface with its own Form Requests; it is not a duplicate of the internal `Api/MessageTemplateController` but a genuinely separate caller/contract. **[Inference]** ~145 of 156 routes (the internal dashboard API) are unversioned and tightly coupled to the current SPA shape — a reasonable choice for a product that deploys frontend+backend together today, but it means any breaking internal-route change has no versioning safety net; a future public/partner API expansion needs a deliberate decision here (**P2**, see §29).

**REST consistency** [Fact]: consistently action-endpoint-heavy by *style*, not by drift — `POST /groups/create`, `/{id}/send-template`, `/{id}/recreate`, `/{id}/pause` pattern repeats uniformly across domains ("RPC-over-REST" convention, not textbook REST, but applied consistently).

**Response-shape consistency — genuinely inconsistent** [Fact]: 94 occurrences of `'success' => false` across 19 files vs. 78 bare `{message}`-only error responses with no `success` key. The 402 quota-exhausted fix standardized `success:false` within the cases it touched but left an `error_code` gap between internal (`Api/`) and external (`Api/V1/`) 402 responses, and did nothing for the larger population of non-402 errors. **[Inference]** No single enforced response envelope exists platform-wide — a frontend/partner consumer must branch per-endpoint. **Priority: P2** — standardize a `{success, message, error_code?, data?}` envelope going forward (not a mass retrofit); see §29.

## 8. Authentication & RBAC

**[Fact]** Sanctum tokens (`'expiration' => null` — never expire server-side; only explicit logout or an admin-forced password-change revokes them — **LOW–MEDIUM**, widens blast radius of a leaked token, compounds with the login-throttling gap below). `config/permission.php` sets `'teams' => false` — Spatie roles are **global**, not per-tenant; the team has designed around this consistently rather than fighting it (verified, not assumed). `Gate::before()` (`AuthServiceProvider.php:9-30`) grants Super Admin everything, layered with Super Admin also holding the full permission catalog directly (belt-and-suspenders).

**[Fact]** Mass assignment is clean platform-wide: zero `$guarded` usage (every model uses explicit `$fillable`), zero `$request->all()` piped into `create()`/`update()`/`fill()` anywhere in the 53 controllers checked. Password hashing uses Laravel 11's automatic `'password' => 'hashed'` bcrypt cast uniformly.

**[Fact — HIGH severity]** `POST /api/auth/login` has **no rate limiting, no lockout, no CAPTCHA**. `bootstrap/app.php` never calls `->throttleApi()` (self-disclosed in `AppServiceProvider.php:44-58`, which motivated two *other* limiters but never mentions `/auth/login`). `AuthController::login()` writes a `LoginAuditLog` row on failure but nothing reads it back to slow or block repeated attempts. **Failure scenario:** an attacker with a known/guessed tenant-admin email can submit unlimited password guesses with zero delay — the only defense is bcrypt's per-hash cost. **This is the single highest-severity open finding in this report. Priority: P0.**

## 9. Agent Hierarchy

**[Fact]** `AccountController::callerAgentScopeId()` (`AccountController.php:44-52`) is the canonical derivation, non-null only for a caller whose own account is `account_type==='agent'`. Every id-addressed mutation (`show`, `update`, `updateSubscription`, `updateQuota`, `updatePermissions`) calls `assertCallerCanAccessAccount()` (line 63), 404ing (deliberately non-disclosing, not 403) on `$account->agent_id !== $agentScopeId` — verified across every call site in the file, no gap found. List endpoints scope identically (`ownedByAgent()`); a client-supplied `?agent_id=` from a non-Super-Admin caller is silently ignored.

**[Fact]** `QuotaRequestController` (the named approval/request workflow) independently implements the same correct pattern: list scoped via `whereHas('account', ...->where('agent_id',$agentScopeId))`, and the mutating `approve()` action **re-checks ownership itself** rather than trusting the caller's scoped list — `abort_if($agentScopeId !== null && $account->agent_id !== $agentScopeId, 404)`. A directly-onboarded client (`agent_id===null`) is unreachable by any Agent, only Super Admin. **This directly and verifiably satisfies the stated "approval authority stays within the hierarchy" requirement — not an inference, a traced fact.**

**[Fact]** Module-delegation is real and correctly capped: `AccountService::resolveDelegatedModules()` intersects a requested `allowed_modules` array against the granting Agent's own `allowed_modules` — an Agent literally cannot hand a Client a module it doesn't itself hold. **This directly satisfies "child cannot receive more permissions than parent" for the module dimension.**

**[Fact — gap, not exploitable as found, but architecturally fragile]** For the *Spatie-permission* dimension (`TeamController::updatePermissions()`, granting one of 9 granular slugs to a team member), the granting check is only `hasRole('admin')` — it does not itself verify the granting Admin's account currently holds the module the permission maps to. This is **not currently exploitable** because every granular permission route is separately wrapped in `module.guard:<slug>`, which acts as the real backstop — but that backstop only covers 6 of ~17 modules (see §20). **Priority: P1** — either extend `module.guard` coverage (preferred, see §20) or add the same-module check directly into `updatePermissions()` as defense-in-depth.

**[Fact — historical, confirmed fixed]** The previously-documented `RoleController` privilege escalation (any `admin`-role user could mutate *global* Spatie role definitions, since roles are global) is confirmed closed: `POST/PUT /roles` now require `role:super_admin` layered on top of `permission:manage-roles`; `git log` shows no subsequent route/middleware change reopening it.

## 10. WhatsApp QR Architecture

**[Fact]** One Baileys session per tenant `account_id`, tracked in an in-process `Map` (`sessionManager.js:17-35`) plus per-account `useMultiFileAuthState` credential directories on disk (`mode:0o700`). QR codes stream over Socket.IO, not the HTTP response (`start-session` returns 202 immediately). Reconnect logic retries up to 5 attempts on transient close reasons; `loggedOut`/`badSession` wipes the session directory — confirmed matching the prior `QR_ENGINE_CROSSDEVICE_HARDENING_SUMMARY.md` fix, still live.

**[Fact]** Outbound sends are a **synchronous HTTP round-trip inside the Laravel request** (`BaileysDriver::sendMessage()` → POST to qr-engine-service, 15s timeout) for individual/template sends. Inbound messages flow via Baileys' `messages.upsert` → POST to `/api/internal/whatsapp-inbound` → `ChatbotEngineService::handleInboundMessage()`. **[Fact, contradicts a stale in-repo comment]**: `WhatsAppInboundController`'s own docblock still calls the inbound listener a "KNOWN GAP … not yet wired" — it demonstrably exists and works; this is a documentation-hygiene issue to fix, not a functional gap (**P3**).

**[Fact]** QR/Baileys has **no per-message delivery-status callback** (sent/delivered/read) into backend-api — only connection-level status. This is a real, permanent capability asymmetry versus Meta (which does provide delivery receipts), self-disclosed in `MetaWebhookController.php`'s own docblock.

**[Fact]** "Multi-device" in the existing `MULTI_DEVICE_FIX_SUMMARY.md` refers to multiple **developer laptops** staying in sync, not multiple WhatsApp phones per tenant — the session registry is keyed by a single `account_id`, one socket per account, confirmed by direct code read. **UNKNOWN — requires confirmation** whether "multiple WhatsApp numbers per tenant account" is an actual future business requirement; nothing in the current schema (`whatsapp_sessions` has a unique `account_id`, by explicit migration docblock design) supports it.

## 11. Meta API Readiness

**Headline finding: a real provider abstraction already exists and is already used broadly — this is not a greenfield design problem.**

**[Fact]** `App\Services\WhatsApp\WhatsAppDriverInterface` (one method: `sendMessage()`), `WhatsAppEngineFactory::make(Account $account)` (a documented Strategy-pattern factory reading `$account->currentSubscription->engine_type`), and a working `MetaCloudApiDriver` (real HTTP calls against Graph API v18.0, correct non-text-type handling) all exist today. `WhatsAppEngineFactory::make()` is called from **14+ distinct sites** across controllers, jobs, and services — every one of them is already provider-agnostic; swapping in a hardened Meta driver later requires changing `MetaCloudApiDriver` only, no call-site changes.

**[Fact — the real gap]** The driver interface covers only single-message send. Everything group-related (create/list/add-participants/metadata) is QR-only with no interface at all — direct Baileys calls gated by hardcoded `engine_type !== 'qr'` string checks in 10+ places. **[Inference]** This is largely correct business logic (Meta Cloud API genuinely cannot do groups) rather than a missed abstraction, but it means group capability can never be symmetric across providers — a future unified UI needs an explicit "does this engine support groups" capability flag, not an assumption of parity.

**[Fact — the biggest conceptual gap]** PLAN, FEATURE, PERMISSION, PROVIDER, PROVIDER CAPABILITY, USAGE LIMIT, and BILLING are **not** kept as independent concepts. The `subscriptions` table conflates `engine_type` (provider), `billing_model`, `rate_per_message`, `total_allocated_messages`/`used_messages` (usage limit), and `price_paid` (billing) into one flat row; `PlanCatalog` hardcodes three plans that each bundle provider + billing-model + quota + price as one indivisible unit (`business` is the *only* plan on `engine_type: 'meta'`). **Today, "which WhatsApp engine" and "how billed" are the same database row and cannot vary independently** — an account cannot be on Meta with per-message billing without hand-adding a new catalog entry. **This is the single highest-leverage schema change for Phase 2 (§27, §28) — extract Provider/Billing-Model/Plan into independent dimensions before Meta becomes a fully productized second provider. Priority: P1.**

**Assessment of what a second (hardened) Meta provider actually needs**, given the interface already exists: (1) harden `MetaCloudApiDriver` (token refresh, real media-upload endpoint, richer error mapping) — isolated, low blast radius; (2) a Meta-side connect/config flow parallel to but distinct from the QR-scan modal (`MetaConfigController` exists — its completeness was **not** independently audited in this pass, **UNKNOWN — requires confirmation**); (3) a product decision on how the UI signals "this engine doesn't support groups" per account; (4) the Provider/Billing-Model schema separation above, which is a migration, not a driver-layer rewrite. **None of this requires touching `qr-engine-service` or the Baileys session lifecycle — the stated goal of not rewriting the QR engine is already achievable given the existing seam.**

## 12. Messaging Architecture

**[Fact]** Individual/template sends are **deliberately synchronous** (`.env` ships `QUEUE_CONNECTION=sync`; `TemplateMessageDispatcher.php`'s own docblock confirms this is intentional, not an oversight) — both drivers block up to 15s per send inside the HTTP request. Group sends and payment alerts use real Job classes, but under the shipped `sync` default they also execute inline unless production overrides the queue connection (**UNKNOWN — requires confirmation** whether it does).

**[Fact]** Idempotency is a deliberate, disclosed design choice: every WhatsApp-send Job sets `$tries = 1` specifically to avoid double-sending on retry (confirmed across all 5 relevant Jobs). Meta webhook status correlation and CTWA lead capture are explicitly idempotent against Meta's at-least-once redelivery (skip-if-already-processed guards).

**[Fact — a real gap]** The ordinary inbound-message path (`handleInboundMessages()` → chatbot/journey engine) has **no WAMID dedup guard** before firing, unlike the two paths above that do. Since Meta's webhook delivery is at-least-once, a redelivered webhook for the same inbound message could theoretically fire an automated chatbot reply twice. **Mechanism confirmed by code; real-world frequency not measured. Priority: P2** — add the same dedup pattern already used elsewhere in the same controller.

**[Fact]** Rate limiting is narrow: only the external Developer API (`external-api`, per-account) and public Meta webhooks (`meta-webhook`, 120/min/IP — added specifically because Laravel's `->throttleApi()` was never enabled) are limited. The Sanctum-authenticated tenant-app routes and the internal QR-engine webhooks are **unthrottled**. **[Hypothesis]**, lower severity than the login gap (requires a valid session first) but worth a deliberate product decision on whether that's accepted.

## 13. Group Architecture

**[Fact]** Groups are entirely QR/Baileys-only — a real, permanent platform-capability gap versus Meta, not an oversight (Meta Cloud API cannot do groups). `ContactGroupController` exposes a genuinely complete, iterated-on surface: list, create, import native groups, add contacts, delete, send-template, and a "recreate" flow for groups deleted/left outside the app — this reads as a deliberately built and maintained feature, not a fragile bolt-on. Its correctness is entirely dependent on `sessionManager.js`'s Baileys group calls.

**[Fact]** The previously-documented P0 group-undercount bug (batch success/failure counts summed to near-zero) is confirmed fixed and still intact in current `AnalyticsController::resolveRecipientAwareTotals()`, called consistently from `summary()`, `charts()`, and `computeGlobalSummary()`.

## 14. Subscription/Billing

**[Fact]** `billing_model` enum (`flat_quota`|`per_message`|`unlimited`), `rate_per_message`, `total_allocated_messages`, `used_messages`, `price_paid`, plus the `spentAmount()`/`remainingBalance()` accessors added this session — confirmed live and consolidating three previously-duplicated computations into one source of truth. `Account::currentSubscription()` is a `hasOne(...)->ofMany('starts_at','max')` — subscriptions form an append-only history by row-creation, but the "current" row's `used_messages`/`price_paid` are then mutated in place.

**[Fact]** Two distinct, separately-verified gates: `SubscriptionGuardMiddleware` (coarse, 403, subscription-row-level, blocks all mutating requests when inactive, allows all GETs) vs. the fine-grained 402 per-send quota-exhausted gate inside the dispatchers (`MessageTemplateController`, `ContactGroupController`), routing the user message to "your Agent" or "your Super Admin" based on `agent_id !== null` — consistent with `QuotaRequestController`'s own routing logic.

**[Fact]** Real payment-gateway integration exists for whole-plan purchases (`RazorpayGatewayDriver`, `StripeGatewayDriver`, `PaymentGatewayController::createOrder()`/`verifyPayment()`, a webhook as the documented source of truth for production) — not a stub. **[Fact — but]** quota *top-ups* are explicitly manual/request-based, not gateway-integrated: `QuotaRequestController::approve()` credits quota and creates an Invoice in one transaction with **no real payment captured** (a disclosed placeholder `RATE_PER_EXTRA_MESSAGE = 1.00` constant, confirmed still present). **[Fact, corrects a stale prior audit]**: the routing permission tier has since changed to `permission:manage-accounts` — `QUOTA_TOPUP_INVOICE_AUDIT.md`'s claim of `manage-billing-settings` is now stale.

**[Fact — real financial/audit risk]** No append-only ledger exists anywhere (`transactions`/`wallet_transactions` tables do not exist). `used_messages`/`price_paid`/`total_allocated_messages` are all mutated in place on the single current-subscription row, guarded only by `lockForUpdate()` optimistic-concurrency locking, not an audit trail. **[Inference]** There is no way to reconstruct usage-over-time purely from `subscriptions` without joining `message_dispatch_logs` and hoping every increment has a matching log row — not enforced by any constraint. The closest thing to a ledger is `Subscription`'s generic `LogsActivity` field-diff trait, not a purpose-built financial transaction table. **Priority: P1 for any AI-credit or wallet system (§17, §32) — do not repeat this in-place-mutation pattern for a new ledger; build it as append-only from day one.**

**[Fact — positive]** `InvoiceCreditService::markPaidAndCreditQuota()` is a genuinely well-built, idempotent, row-locked "money becomes quota" transaction, called from both the client-verify path and the authoritative webhook, with an already-paid no-op guard against the race between the two callers. **This is the correct pattern to reuse as the template for a future AI credit/wallet ledger** — see §17.

## 15. Automation/Scheduler Readiness

**[Fact]** `QUEUE_CONNECTION=sync` is the shipped default — jobs dispatched with plain `::dispatch()` execute inline in the HTTP request unless production overrides it (**UNKNOWN — requires confirmation** whether it does). Horizon is not installed. **[Fact]** There is **no persistent queue worker process anywhere in the repo** — the codebase's own comment in `routes/console.php` states this explicitly: the one genuinely-async Job (`SendWhatsAppTemplateJob`, forced onto `->onConnection('database')->onQueue('whatsapp-bulk')`) is drained by `Schedule::command('queue:work database --queue=whatsapp-bulk --stop-when-empty --max-time=55')->everyMinute()` — i.e., **the scheduler tick IS the worker**, gated entirely on an OS-level crontab entry invoking `schedule:run` every minute, which nothing in the repo installs or verifies.

**[Fact]** Zero Events/Listeners exist anywhere in the backend (§5) — no domain-event pattern to build automation triggers on top of.

**[Fact — genuine strength]** A real trigger→condition→action graph engine already exists: `WhatsAppJourneyEngine` executes a `WhatsAppFlow` node graph with trigger matching (keyword/CTWA-referral/default), condition evaluation (variable+operator+value edges), and `message`/`question`/`save_lead` action nodes, including a cycle guard and the same tenant/quota-aware dispatch pattern used elsewhere. **This is the right shape for a generalized automation engine's execution core** and should be extended, not replaced.

**[Fact — the real gap]** No `wait`/`delay` node type exists anywhere in the journey engine; it runs a graph to completion (or pauses only at a `question` node awaiting the next inbound reply) synchronously within the webhook-handling request. No delayed-job dispatch, no recurring-trigger concept, and no `flow_executions`/`pending_steps`-style persistence table for a paused-until-timestamp-X flow instance exists in the schema.

**[Inference]** Building the requested generic automation engine (delays, recurring schedules, arbitrary triggers like `student.absent`/`fee.due`) can **reuse**: the node-graph data model/evaluator shape, the tenant-scoped quota-aware dispatch pattern, and the existing `Schedule::command()` mechanism as a poll-based trigger-check tick. It **needs, as genuinely new infrastructure**: (a) a real persistent queue worker (or a materially more robust polling design than the current "scheduler-tick-is-the-worker" hack) — this is a prerequisite, not optional, for reliable delayed/recurring execution at any scale beyond today's; (b) a `delay`/`wait` node type plus a paused-flow-instance persistence table; (c) genuine per-rule recurring-trigger scheduling, architecturally different from the two hardcoded `Schedule::command()` entries that exist today; (d) some job-monitoring (Horizon or equivalent), since none exists. **This is Phase 4 work (§27) and should not be attempted before Phase 1's queue-infrastructure hardening.**

## 16. CRM Readiness

**[Fact]** Zero CRM data model exists. No `Contact`, `Deal`, `Pipeline`, `Stage`, `Task`, `Note`, or `Tag` model/table anywhere (verified by direct grep of all 33 model files and all 74 migrations). Two adjacent-but-narrow concepts exist and neither is a CRM: `ContactGroup`/`ContactGroupMember` is the WhatsApp broadcast-recipient-list feature (3 fillable fields: group_id, phone_number, name — no company/tags/notes/history); `Lead` is a flat row created from Meta Lead Ads/CTWA submissions (its docblock literally says "Instant Lead CRM," which is presumably the source of any "CRM" grep hit) with no stage, no deal amount, no owner/assignment, no notes, no pipeline relation.

**Conclusion: CRM is a fully greenfield build.** The only reusable fragment is the `Lead` model's dedup pattern (`isDuplicatePhoneWithin24Hours()`, unique `provider_lead_id`) and its `scopeForAccount` tenant-scoping convention — useful scaffolding precedent, not functionality to extend. See §5 in the roadmap (§27) for how this should be sequenced relative to the automation engine (leads/contacts need to exist before "lead created → automation" triggers are meaningful).

## 17. AI Architecture Readiness

**[Fact]** Exactly one AI integration point exists platform-wide: `CopywriterService.php`, reachable only via `POST /api/social/ai/generate`, scoped entirely to the Meta Ads copywriter wizard (Gemini primary, OpenAI/Anthropic/template fallback chain, per-tenant key override via `Account.gemini_api_key`). No general-purpose AI content-generation surface, no chat/document/image-generation endpoint exists anywhere. **This is a usable pattern (provider fallback chain, per-tenant key override) but not a platform to extend — a future general AI platform is greenfield.**

**[Fact]** No literal credit ledger exists (no `credit_transactions`/`wallet_transactions` table). What exists is a reusable **pattern**, not a reusable **table**: `InvoiceCreditService::markPaidAndCreditQuota()` (§14) — an idempotic, row-locked, "money becomes quota" transaction — is the closest template for an AI wallet's top-up side. `QuotaRequest` already carries wallet vocabulary (`isWalletTopUp()`, `requested_topup_amount`) for message billing, so the domain concept of "wallet" is not foreign to this codebase.

**[Recommendation, P1 for Phase 6]** Do **not** build the AI credit system as another in-place-mutated balance column (repeating §14's ledger gap). Build a genuine append-only `ai_credit_transactions` table from day one (credit/debit rows, reason, reference, timestamp), with a materialized/cached current-balance column for fast reads — reusing `InvoiceCreditService`'s idempotent-locked-transaction pattern for every mutation, never a bare `increment()`/`decrement()`. Keep image-generation credits as a separate `credit_type` dimension on the same ledger rather than a second parallel table, per the user's stated requirement that text and image credits differ in rate but should not be architecturally separate systems.

## 18. Social Media Readiness

**[Fact]** Substantially more built than the prior `SOCIAL_ADS_MODULE_AUDIT.md`/`SOCIAL_ADS_STEP3_4_SUMMARY.md` describe — both are now partially stale, independently re-verified against current code:
- **Paid Meta Ads launcher**: real, working Campaign→AdSet→AdCreative→Ad chain against Marketing API v19 (`MetaAdsService.php`), correct ODAX objective mapping, CTWA destination-type branching, pause/resume, insights. Not a UI shell.
- **Media upload**: real (`SocialMediaController.php`, 50MB limit, MIME validation, tenant-scoped storage, custom serving route chosen deliberately for Windows/XAMPP compatibility) — closes a gap the original audit flagged as must-build.
- **Live ad preview** (`AdPreview.tsx`, FB Feed/IG Post/IG Story tabs from live wizard state) — closes another flagged gap.
- **Organic publishing**: real (`OrganicPost` model, `OrganicPublishService` for FB/IG/LinkedIn publish flows, routes live and gated).
- **Click-to-WhatsApp**: real (`AdCampaign::OBJECTIVES` includes `CLICK_TO_WHATSAPP`, `MetaAdsService` sends `destination_type=>'WHATSAPP'`, `MetaWebhookController::captureCtwaLead()` upserts a `Lead` from `message.referral`) — this closes what the original audit called the single biggest missing piece.
- **Webhook signature verification**: implemented and verified live (§20).

**[Fact — gaps still open, independently re-confirmed, not carried over uncritically]**: LinkedIn OAuth is still unimplemented (`SocialOAuthProviderFactory::IMPLEMENTED_PROVIDERS = ['meta']` only — the LinkedIn organic-publish driver exists but is unreachable). No Lead Form (Instant Form) object creation — `LEAD_GENERATION` campaigns run the full ad chain without ever creating/attaching a `leadgen_form_id`, likely to be rejected by Meta at validation time (**[Hypothesis]**, uncontradicted). No video-creative upload, no city/region geo-targeting.

**Verdict: closer to "real, working integration" than "scaffolding" for the ads launcher, organic publishing, and CTWA capture.** Remaining gaps are specific and narrow (LinkedIn, Lead Forms, video ads), not structural — treat as P2 backlog items, not a redesign.

## 19. Ads/Attribution Readiness

**[Fact]** The core of the attribution chain the business wants (Ad → Click → WhatsApp conversation → Contact → Lead) is **already partially real**: `captureCtwaLead()` reads Meta's `message.referral` payload on an inbound WhatsApp message originating from a CTWA ad click and upserts a `Lead` row tagged `provider='whatsapp_ctwa'`, linking `ad_id` where present. What does **not** exist yet, confirmed by the same evidence as §16: Journey (the `Lead` never becomes a CRM contact/deal with a pipeline stage — there is no pipeline to enter), and Deal/Conversion (no deal model exists at all). **[Inference]** The attribution *capture* mechanism (ad click → lead) is built; the attribution *chain* (lead → journey → deal → conversion) cannot be completed until CRM (§16) exists. This is exactly the dependency the user's own phased request (Phase 5 CRM before Phase 9 Ads+Attribution) already anticipates — the code confirms that ordering is correct, not arbitrary.

**Recommendation (Phase 9, §27):** do not build a separate "attribution" subsystem — once CRM (§16) exists, extend `Lead`'s existing `provider`/`provider_lead_id`/`ad_id` fields into the CRM's Contact/Deal model as the attribution source fields, reusing the CTWA capture code already written rather than duplicating it.

## 20. Security Audit

*(Consolidated from the dedicated RBAC/security research pass; every finding below was independently traced through current code, not carried over from prior audits without re-verification.)*

**IDOR.** **[Fact]** Systematically checked across all id-addressed endpoints in the account/team/quota/role surface (`AccountController`, `TeamController`, `QuotaRequestController`) — every one resolves its target through a tenant-scoped query (`ResolvesTenantAccount`, `assertCallerCanAccessAccount`) that 404s on ownership mismatch rather than trusting the id. No unguarded `:id` endpoint found in the surface reviewed. A full 60+-controller sweep was not exhaustive (**P1**, see §30 — this needs an automated regression suite, not another one-off manual audit).

**Mass assignment.** **[Fact]** Clean platform-wide (§8) — no findings.

**Horizontal privilege escalation (Agent A → Agent B).** **[Fact]** No exploitable path found — traced end-to-end in `AccountController`/`QuotaRequestController` (§9).

**Vertical privilege escalation (Client → Agent/Super-Admin).** **[Fact]** No exploitable path found — `TeamController::store()`/`update()` explicitly exclude `super_admin`/`agent`/(for non-Agent callers)`admin` from self-assignable roles; the historical `RoleController` escalation is confirmed fixed (§9).

**Webhook signature verification (Meta HMAC).** **[Fact, directly re-verified against live source]** `MetaWebhookController::assertValidSignature()` and `SocialWebhookController::assertValidSignature()` both compute HMAC-SHA256 over the raw request body against a DB-backed (not `.env`-backed) app secret, compare via `hash_equals()`, fail closed on any missing/empty/mismatched case, bypass only under `APP_ENV=local`. **This is a real, confirmed-live fix, not aspirational documentation** — the prior audit's "still open" note on the second (`SocialWebhookController`) endpoint is now stale; it has since been closed.

**Rate limiting / brute force — HIGH.** **[Fact]** `/auth/login`: zero throttling of any kind (§8). Ordinary authenticated tenant-app traffic and the internal QR-engine webhooks are also unthrottled, though that requires a valid session/shared-secret first (**[Hypothesis]**, lower severity, worth a deliberate product decision). **Priority: P0 for login; P2 for the broader `/api/*` surface.**

**Module-guard coverage — MEDIUM.** **[Fact, self-disclosed by the codebase's own route-file comments, independently re-confirmed against the live route table]** `EnsureModuleEnabledMiddleware` is wired to only 6 of ~17 module slugs (`chatbot`, `meta_ads`, `social_accounts`, `contact_groups`, `message_logs`, `analytics`). For the remaining ~11 modules (`send_alert`, `whatsapp_setup`, `templates`, `device_settings`, `billing`, `developer_api`, `notifications`, `lead_crm`, `social_inbox`, `comment_automation`, `reports`), toggling the module off for a tenant only hides the frontend nav item — the backend route is still reachable by anyone holding the underlying Spatie permission via a direct API call. **This is precisely the "UI-hiding must not be the only enforcement" failure mode the business explicitly named as a requirement, and for roughly two-thirds of the module catalog it currently is exactly that.** Failure scenario: Super Admin disables `send_alert` for a client believing it blocks bulk messaging; any user on that account holding `send-messages` can still call `POST /api/alerts/*` directly. **Priority: P1** — extend `module.guard:<slug>` to the remaining ~11 modules; this is additive middleware, low risk, high leverage, and directly closes a stated business requirement.

**Sanctum token expiration.** **[Fact]** Never expire server-side — LOW–MEDIUM, compounds with the login-throttling gap.

**Queue job data isolation.** **[Fact, spot-checked]** `SendWhatsAppTemplateJob` takes `accountId` as an explicit constructor argument, baked in at enqueue time from the already-tenant-resolved request — cannot pick up the wrong account on execution. **[Hypothesis]**, not independently confirmed, that the other 6 Jobs follow the same pattern given the codebase's consistency elsewhere.

**Logs for leaked secrets.** **[Fact]** All 11 `Log::*` call sites near password/token/secret keywords log presence/absence or mismatch only, never the actual value. No leaked-secret pattern found.

**No CRITICAL findings.** The most severe historical issue (global-role-mutation privilege escalation) is confirmed fixed. Nothing else checked in this pass rose above HIGH.

## 21. Performance Audit

*(Static code review only — no PHP runtime, database, or load-testing tools were available; every finding is a source-level inference, explicitly not a measurement.)*

**N+1 risk.** **[Fact]** 18 of 53 controllers use `->with()`/`->load()` eager-loading; the highest-traffic ones spot-checked (`AccountController`, `BillingController`, `MessageLogController`, `NotificationBroadcastController`, `AnalyticsController`) all correctly batch-load before looping. **[Inference]** Probably not the dominant systemic risk given this consistency, but the remaining ~35 controllers were not exhaustively checked — **UNKNOWN, requires confirmation** (P2, covered by the testing roadmap's controller sweep).

**Caching.** **[Fact]** Used deliberately and correctly where present (`Account::findCached()` with active cache-invalidation on model save/delete; `AnalyticsController`'s two heaviest aggregates wrapped in 60s `Cache::remember`). **But the cache store itself is `database`, not Redis** (`CACHE_STORE=database` shipped in `.env.example`; Redis env vars exist but are inert unless an operator manually switches `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION`). **[Inference]** Under shipped defaults, caching reduces duplicate *application logic*, not *database load* — every cache read/write and session touch is itself a query against the same database the app otherwise depends on.

**Bulk operations — the most consequential finding in this section.** **[Fact]** Two bulk-send paths were built to two different standards. `PaymentAlertController::bulkUpload()` (CSV upload) dispatches one job per row on the **default** (shipped `sync`) queue connection — each `ProcessPaymentAlertJob` documented to take 5–18 seconds (deliberate anti-ban `sleep()` + WhatsApp-engine HTTP call) per the root README's own troubleshooting table; chained across even 20-50 CSV rows in one request, this risks holding a PHP-FPM worker open for minutes — a real gateway-timeout/worker-pool-starvation risk, verified from the dispatch call and documented per-job latency, not assumed. By contrast, `BulkMessageDispatcher` (template bulk-send) deliberately forces its Jobs onto a named `database` queue with chunked, randomized-delay anti-ban pacing — but that queue is only drained by a `Schedule::command('queue:work ... --stop-when-empty --max-time=55')->everyMinute()` entry, i.e. a single-threaded, ≤55-seconds-of-work-per-minute burst processor gated on a manual OS crontab entry that nothing in the repo installs or verifies (§15). **Priority: P0 for the CSV bulk-upload path specifically** (fix before any further scale — it's an active timeout/worker-starvation risk today, not a future one); **P1** for making the `whatsapp-bulk` queue a real persistent worker rather than a cron-gated burst processor.

**`NotificationBroadcastController::store()`** sends every email synchronously in a `foreach` loop, unqueued regardless of `QUEUE_CONNECTION` — a broadcast to hundreds of recipients blocks the request for that many SMTP round-trips. **Priority: P2.**

## 22. Scalability Analysis

*(Qualitative, source-derived only — explicitly not a benchmark. Real numbers require actual load testing, called out below.)*

- **~100 users [Inference]:** Everything above is likely invisible — infrequent concurrent bulk sends, `sync` queue's in-request blocking tolerable, database-as-cache negligible.
- **~1,000 users [Inference]:** The CSV bulk-upload `sync`-queue behavior becomes the first plausible failure mode — concurrent uploads across tenants could each hold a PHP-FPM worker for minutes, risking pool exhaustion (deployment is documented PHP-FPM + Nginx, no async gateway).
- **~10,000 users [Inference]:** Database-as-cache/session/queue-store starts competing with primary application traffic for the same connection pool; the cron-gated `whatsapp-bulk` queue visibly lags behind large campaigns; per-row duplicate-check queries in `bulkUpload()` add proportional DB round-trips.
- **~100,000 users [Inference/Hypothesis]:** The relational database itself (no read replicas or sharding evidenced anywhere) becomes the likely first-order constraint, since queue/cache/session/application data all share it by default. Separately and independently: `qr-engine-service` holds every tenant's Baileys socket in a single in-process `Map` with no clustering/sharding logic — a hard architectural ceiling on concurrent QR sessions per process/server, orthogonal to anything on the Laravel side.

**What would need real load testing to confirm:** actual PHP-FPM worker-pool exhaustion thresholds; actual query latency/lock contention on `payment_alerts`/`message_dispatch_logs` under concurrent write load; actual memory ceiling of `qr-engine-service`'s in-memory session map; whether the OS crontab for `schedule:run` is even reliably installed in the real production environment (repo evidence shows it only as a documented manual step, never automated — **UNKNOWN, requires confirmation**).

## 23. Testing Audit

**[Fact]** Backend: exactly 3 files under `backend-api/tests/` — the framework base class plus two **unmodified Laravel scaffold tests** (`GET /` returns 200; `true===true`). Zero coverage of any actual business logic — no test of tenant isolation, quota enforcement, RBAC, webhook signatures, or any of the dozens of controllers/services the platform's own prior audits describe as security/billing-critical.

**[Fact]** Frontend: zero test files (no `.test.`/`.spec.` files), no test script in `package.json`, no Jest/Vitest/Testing-Library dependency anywhere. `tsc -b` (type-check) and `oxlint` run as part of build/lint — these catch type errors and lint violations, not behavioral regressions.

**[Fact]** `qr-engine-service`: zero test files.

**[Fact]** One CI workflow exists (`.github/workflows/ci.yml`): runs backend scaffold tests against a fresh SQLite DB, frontend type-check+build, and a Node syntax-check (not a real test run) for qr-engine-service — **it verifies the code compiles/boots, it does not deploy anything and does not exercise business logic.**

**Net assessment: this is not thin coverage, it is no coverage.** For a multi-tenant billing/messaging SaaS whose own tenant-isolation and quota-enforcement logic this report independently confirmed to be correctly implemented (§9, §20) — a regression in `TenantIsolationMiddleware`, `ResolvesTenantAccount`, or the quota-gate logic would currently be caught by nothing automated. **This is the single highest-leverage process gap in the whole report — see §30 for the priority-ordered testing roadmap. Priority: P0/P1.**

## 24. Technical Debt

*(Synthesized across all seven research passes. Each item includes its own file:line evidence in the relevant numbered section above; this is the consolidated punch list.)*

**Documentation/code drift (low risk, real, worth a cleanup pass — P3):**
- `WhatsAppInboundController`'s docblock claims "no inbound listener wired" when one demonstrably exists (§10).
- `MULTI_DEVICE_FIX_SUMMARY.md`'s title is misleading relative to its actual (developer-machine-sync) content (§10).
- `QUOTA_TOPUP_INVOICE_AUDIT.md`'s claimed permission tier (`manage-billing-settings`) is stale — now `manage-accounts` (§14).
- An orphaned migration (`Claude outputs/2026_09_16_999999_sync_production_baseline_schema.php`) sits outside the migrations path and cannot run (§4) — **UNKNOWN — requires confirmation** whether it's still needed.
- Entire git working tree shows as "modified" due to CRLF/LF churn from the Windows checkout, not real content changes — cosmetic but noisy for every future `git status`/diff review; a `.gitattributes` fix has been recommended in at least two prior audits and still hasn't landed (**P2**, cheap, high signal-to-noise payoff for future work).

**Structural inconsistencies (real, worth planned cleanup — P2):**
- Two independently-evolved API-key auth mechanisms (`AuthenticateApiKey` vs. `ApiAuthMiddleware`) with overlapping surface area (§5).
- Response-shape inconsistency across the API — no single enforced envelope (§7).
- Frontend Pattern A/B page-layout split — 9 of 31 pages (29%) still on the legacy `PageShell` pattern, tracked but unresolved by the team's own explicit choice not to scope-creep it into unrelated work (§20 in the frontend research, folded here).
- Status columns inconsistently typed (varchar vs. enum) across otherwise-normalized schema (§6, finding 5).

**Genuine gaps that are business-appropriate to leave alone for now (not debt, just not-yet-built — see roadmap for sequencing):** CRM, AI platform, automation delay/recurring engine, LinkedIn OAuth, Lead Form objects, video ad creatives.

## 25. Critical Risks

Ranked by (severity × how close to a P0 trigger it already is), not by personal preference:

1. **HIGH — `/auth/login` has zero rate limiting.** Exploitable today, no precondition needed beyond a guessed email. (§8, §20) **P0.**
2. **HIGH-equivalent operational risk — CSV bulk-upload path can starve the PHP-FPM worker pool** under the shipped `sync` queue default; this is an active risk at even moderate concurrent usage, not a future scaling concern. (§21) **P0.**
3. **HIGH — zero automated test coverage** on tenant-isolation/RBAC/quota logic that this report confirmed is currently correct — meaning any future change has no safety net to catch a regression in exactly the logic a multi-tenant SaaS depends on most. (§23) **P0/P1.**
4. **MEDIUM — `module.guard` covers only 6 of ~17 modules**, so most "Super Admin disabled this feature" toggles are backend-unenforced, directly contradicting the stated "UI-hiding must not be the only enforcement" business requirement. (§20) **P1.**
5. **MEDIUM — no append-only financial ledger**; `used_messages`/`price_paid` are mutated in place with no audit trail beyond a generic activity-log trait. Real risk for dispute resolution and for any future AI-credit system built the same way. (§14, §17) **P1.**
6. **MEDIUM — cascading hard deletes with zero soft-delete support** on `accounts` and all tenant-scoped tables; deleting one Agent physically destroys all its Clients' billing/message/audit history irrecoverably. (§6) **P1.**
7. **MEDIUM — no persistent queue worker**; the entire async-job system depends on an unverified OS crontab entry invoking `schedule:run` every minute. If that crontab is ever missing, the `whatsapp-bulk` queue silently never drains, and no automation-engine feature (§15) can be built reliably on top of it as-is. (§15, §22) **P1, and a hard prerequisite for Phase 4.**
8. **LOW–MEDIUM — Sanctum tokens never expire**, no error-tracking/alerting service, no health-check endpoint on `backend-api`. Individually minor, collectively mean production incidents are discovered by manually tailing a flat log file. (§20, §23 in performance research) **P2.**

No CRITICAL-severity finding was confirmed in this pass — the one historically CRITICAL issue (global role-mutation privilege escalation) is fixed and stays fixed.

## 26. Recommended Target Architecture

**Explicitly not recommended:** microservices sprawl, Kubernetes, an event bus, additional databases, or any rewrite of the QR engine. The existing shape — **Laravel modular monolith + a separate lightweight QR/Baileys service (already exists, keep as-is) + MySQL + queues**, evolved incrementally — is the right architecture for this platform's actual current scale and team size, and matches the user's own stated preference.

**What should change, in priority order, without changing the overall shape:**

1. **Turn on real queue infrastructure** (Redis or `database` connection consistently in production, a real persistent `queue:work` supervisor process — not a cron-gated burst — for at least the `whatsapp-bulk` and any future automation queue). This single change de-risks §21's CSV-upload finding, §15's automation-engine prerequisite, and §22's scaling ceiling simultaneously. **This is the one piece of net-new infrastructure this report recommends, and it's config/process, not a new service.**
2. **Separate Provider / Billing-Model / Plan as independent schema dimensions** (§11), rather than one flat `subscriptions` row conflating all four — this is the actual unlock for Meta as a fully productized second provider and for AI/other future credit systems reusing the same billing substrate cleanly.
3. **Extend `module.guard` to full module coverage** (§20) — closes the stated "backend must independently enforce" requirement completely rather than partially.
4. **Add a real automated test suite**, starting with tenant-isolation/RBAC/quota regression tests (§23, §30) — protects everything else on this list from silent regression as new modules (CRM, AI, automation) get built on top of the same account/permission scaffolding.
5. **New modules build ON TOP of, not beside, the existing account/module/permission/queue scaffolding**: CRM's Contact/Deal tables reuse the `account_id`-scoped, `LogsActivity`-audited, module-gated pattern already used by every other domain table; the AI credit ledger reuses `InvoiceCreditService`'s idempotent-transaction pattern but as a real append-only table; the automation engine's delay/recurring layer extends `WhatsAppJourneyEngine`'s existing node-graph shape rather than inventing a second engine.

**Logical module map (target state, incremental):**
```
backend-api (Laravel monolith)
├── Core: Accounts/Tenancy/RBAC/Modules (exists, extend module.guard coverage)
├── Billing: Subscriptions (exists, split Provider/Plan/BillingModel dimensions)
├── WhatsApp: QR driver (exists, untouched) + Meta driver (exists, harden)
│     behind WhatsAppDriverInterface (exists) — add a ProviderCapability lookup
├── Messaging: Templates/Groups(QR-only)/Dispatch (exists)
├── Automation Engine (extend WhatsAppJourneyEngine's node model with delay/recurring nodes;
│     requires real queue worker from item 1 above)
├── CRM (new: Contact/Deal/Pipeline/Stage/Task/Note/Tag, reusing Lead's tenant-scoping
│     and dedup conventions; industry-specific fields via a custom_fields JSON column
│     per entity, per §17-equivalent frontend feasibility finding — see below)
├── AI Platform (new: append-only ai_credit_transactions ledger, reusing
│     InvoiceCreditService's locked-transaction pattern; CopywriterService's
│     provider-fallback pattern extended to a general content-generation service)
├── Social/Ads (exists, extend: LinkedIn OAuth, Lead Form objects)
└── Queue/Scheduler (harden: real worker process, not cron-gated burst)

qr-engine-service (Node, untouched)
frontend-app (React, extend with new module pages following existing Pattern A convention)
```

**Industry-agnostic data model recommendation** (grounded in this codebase's own conventions, not textbook theory): neither a general EAV table (zero precedent in this codebase, fights its Eloquent-model-per-table style) nor a fully generic dynamic-form "custom objects" module (a much bigger investment with no partial precedent in this hand-built-React-page frontend) fits. The pattern that matches the codebase's existing grain — `leads.raw_field_data` (JSON bag for the parts that vary, first-class extracted columns only for the parts that are queried/filtered) — is the right fit: a small number of genuinely shared entities per industry vertical, each with a `custom_fields` JSON column for industry-specific attributes, gated by extending the existing `Account::MODULES`/`allowed_modules` mechanism to cover "industry packs." **UNKNOWN — requires confirmation:** whether reporting needs real-time filtering/aggregation on those custom fields at scale (which would push toward MySQL generated/virtual columns rather than pure JSON) and whether fields need to be tenant-configurable versus platform-defined-per-industry — both are product decisions, not something the code can answer.

## 27. Phase-wise Roadmap

For every phase: objective, features, DB changes, APIs, backend/frontend work, queue/cron work, security work, tests, migration requirements, dependencies, risks, rollback plan, definition of done.

### Phase 0 — Existing QR System Stabilization *(P0, do first, do independent of everything else)*
- **Objective:** close the highest-severity live risks without touching working systems.
- **Features:** none new.
- **Backend:** add rate limiting/lockout to `/auth/login` (§8); fix `PaymentAlertController::bulkUpload()` to dispatch onto a real async queue instead of the `sync` default, or explicitly cap batch size per request as an interim mitigation (§21); stand up a real persistent queue worker process for `whatsapp-bulk` (supervisor/PM2, not the cron-gated burst) (§15, §26 item 1).
- **DB:** none required.
- **Security work:** the login-throttle fix itself is the security work.
- **Tests:** first real tests of this project should be regression tests proving the throttle works and proving `bulkUpload()` no longer blocks a worker for the CSV-row count observed in practice.
- **Migration risk:** none (all additive config/middleware).
- **Dependencies:** none.
- **Rollback:** trivial (remove middleware / revert queue connection).
- **Definition of done:** login throttling verified by a test that asserts a 429 after N attempts; bulk CSV upload verified not to block past a fixed timeout in a test with a realistic row count; a real worker process confirmed running (not cron-dependent) in whatever environment is being called "production."

### Phase 1 — Architecture Foundation: Tenant / Permissions / Provider Abstraction *(P1)*
- **Objective:** close the module-guard gap, separate Provider/Plan/BillingModel, and build the first real automated tests — this phase is the prerequisite for every later phase, not optional groundwork.
- **DB changes:** split `subscriptions.engine_type`/`billing_model` into independent, explicitly-modeled dimensions (exact shape needs a design decision — at minimum, stop treating `PlanCatalog` entries as provider+billing-model+quota+price bundled 1:1); add `deleted_at`/`SoftDeletes` to `accounts`, `invoices`, `subscriptions`, `message_templates` (§6, findings 1-2).
- **APIs:** no breaking changes required if the subscription-dimension split is done additively (new columns/tables, old columns kept and migrated in a follow-up once verified).
- **Backend:** extend `module.guard:<slug>` to the ~11 currently-ungated modules (§20).
- **Tests:** this phase's primary deliverable — tenant-isolation regression tests (Agent A cannot see Agent B's accounts; Client cannot escalate to Admin/Agent/Super-Admin; module-off means backend-blocked, not just UI-hidden) and quota/billing regression tests (§30 has the detailed matrix).
- **Migration risk:** LOW for module-guard (additive middleware); MEDIUM for the soft-delete additions (additive columns, but changes delete-query semantics everywhere they're used — needs careful `withTrashed()` audit); the subscription-dimension split itself needs a dedicated design spike before estimating migration risk — do not schedule it without one.
- **Dependencies:** Phase 0's queue work should land first so this phase's tests can rely on a real worker.
- **Rollback:** module-guard additions are trivially revertible; soft-delete additions are additive and safely revertible; the subscription split should be designed with a reversible migration path from the start (parallel-write period before cutover).
- **Definition of done:** full RBAC/tenant-isolation test matrix (§30) green; every module in `Account::MODULES` has a corresponding server-side gate; Provider is a first-class, independently-variable concept (verified by a test that can construct a `meta`-engine account on a `per_message` billing model without a new hardcoded `PlanCatalog` entry).

### Phase 2 — Meta WhatsApp Foundation *(P1/P2, depends on Phase 1's provider-dimension split)*
- **Objective:** harden the already-existing `MetaCloudApiDriver` into a fully productized second provider.
- **Backend:** token refresh handling, real Meta media-upload endpoint (not raw URL), richer error mapping; audit `MetaConfigController`'s current completeness (flagged UNKNOWN in §11 — this phase's first task should be closing that unknown).
- **Frontend:** an explicit provider-capability flag (does this account's engine support groups?) driving UI, rather than scattered `engine_type==='qr'` checks (verify current state — flagged UNKNOWN in §11).
- **Tests:** Meta driver send/error-path tests; provider-capability-flag tests.
- **Migration risk:** low — this phase touches only `MetaCloudApiDriver` and `MetaConfigController`, both already isolated behind the existing interface; no changes needed to any of the 14+ existing `WhatsAppEngineFactory::make()` call sites.
- **Dependencies:** Phase 1's provider/billing-model split (a Meta account needs to be constructible on any billing model, not just the hardcoded `business` plan).
- **Definition of done:** a tenant can be provisioned on Meta with any billing model the catalog supports; group-dependent UI correctly reflects Meta's lack of group support via the capability flag, not a hardcoded string check.

### Phase 3 — Meta Messaging Suite *(P2)*
- **Objective:** WABA embedded signup/onboarding, richer templates, broadcast/campaign parity with QR where Meta supports it, unified inbox across engines, Flows/catalog if pursued.
- **Dependencies:** Phase 2 complete. **UNKNOWN — requires confirmation** on exact Meta feature scope wanted (Flows/catalog were named as "future" in the business context, not committed).

### Phase 4 — Automation Engine *(P1/P2, hard-dependent on Phase 0's real queue worker)*
- **Objective:** generalize `WhatsAppJourneyEngine`'s existing node-graph execution core with delay/recurring capability and arbitrary triggers.
- **DB changes:** a `flow_executions`/`pending_steps`-equivalent table to persist a paused-until-timestamp-X instance; generalize the trigger vocabulary beyond WhatsApp-specific keyword/CTWA matching to the event catalog the business named (`lead.created`, `appointment.completed`, etc.) — this genuinely depends on CRM (Phase 5) existing first for most of the named triggers to have real data behind them, so **do CRM's core Contact/Lead/Deal tables before or alongside this phase's trigger catalog expansion**, even though the user's own suggested ordering put Automation (4) before CRM (5) — flagging this dependency explicitly rather than silently reordering.
- **Backend:** `delay`/`wait` node type; recurring-trigger scheduler (per-rule, not the two hardcoded entries that exist today); job-monitoring (Horizon or equivalent).
- **Risks:** building this before Phase 0's real queue worker lands would repeat the cron-gated-burst fragility at a larger scale — do not skip the dependency.
- **Definition of done:** a rule with a `wait 3 days` node reliably fires 3 days later under real load, verified by a test using a fast-forwarded clock, not a manual timer.

### Phase 5 — CRM *(P1/P2)*
- **Objective:** Contact/Lead/Deal/Pipeline/Stage/Task/Note/Tag, built on the existing tenant-scoping/audit conventions.
- **DB changes:** new tables, `account_id`-scoped, following the pattern already used everywhere else; `custom_fields` JSON column per entity per §26's industry-agnostic recommendation.
- **Dependencies:** none strictly, but should land before or alongside Phase 4's trigger-catalog expansion and well before Phase 9 (Ads/Attribution needs a Deal to attribute to).
- **Definition of done:** a Lead captured via CTWA (already working, §19) can be promoted into a CRM Contact/Deal without rebuilding the capture logic.

### Phase 6 — AI Platform *(P2)*
- **Objective:** general content-generation surface plus a real append-only credit ledger (§17).
- **Dependencies:** none technical; product decision needed on free-credit allowance and top-up pricing (§32).
- **Definition of done:** `ai_credit_transactions` is append-only and every debit/credit is idempotent under concurrent requests, verified by a test that fires concurrent generation requests against a low balance and confirms no over-debit.

### Phase 7 — Social Media *(P2, mostly extends existing work)*
- **Objective:** close LinkedIn OAuth, extend organic-publish scheduling/analytics.
- **Dependencies:** none new.

### Phase 8 — Catalog/Ecommerce Capabilities *(P3)*
- **UNKNOWN — requires confirmation**: this was in the user's phase list but not detailed in the business-context section; needs its own scoping pass before estimation.

### Phase 9 — Ads + Attribution *(P2/P3, depends on Phase 5 CRM)*
- **Objective:** complete the Ad→Click→WhatsApp→Contact→Lead→Journey→Deal→Conversion chain by wiring the already-working CTWA capture (§19) into the CRM's Deal/Conversion model once it exists.
- **Definition of done:** a CTWA-sourced Lead's full journey to a closed Deal is queryable end-to-end without manual joins across disconnected tables.

### Phase 10 — Advanced Analytics & Optimization *(P3)*
- Extend the already-solid analytics module (§6 in the CRM/analytics research; confirmed accurate and actively maintained) to the new CRM/AI/Ads data once those phases land.

## 28. Database Migration Roadmap

Sequenced by dependency and risk, not by phase number alone:

1. **P0, additive, near-zero risk:** none required for Phase 0 (config/middleware only).
2. **P1:** `deleted_at`/`SoftDeletes` on `accounts`, `invoices`, `subscriptions`, `message_templates` (§6 finding 2) — additive nullable columns.
3. **P1:** FK on `activity_logs.agent_id` (§6 finding 3) — check for orphaned values first, then add constraint.
4. **P1, needs a design spike before scheduling:** the Provider/Plan/BillingModel dimension split on `subscriptions` (§11, §27 Phase 1) — this is the highest-value and highest-risk migration in the roadmap; do not attempt without first designing a reversible, parallel-write cutover path.
5. **P1/P2 for Phase 5:** new CRM tables (`contacts`, `deals`, `pipelines`, `pipeline_stages`, `tasks`, `notes`, `tags`) — purely additive, low risk, but needs the custom-fields-JSON schema decision (§26) made once, consistently, before the first table is created, not per-table ad hoc.
6. **P1/P2 for Phase 6:** `ai_credit_transactions` append-only ledger — additive, low risk, but must be designed append-only from the first migration, not retrofitted later (retrofitting an existing mutated-balance system to a ledger is materially harder than starting correctly, per the lesson already visible in §14's `used_messages` finding).
7. **P2:** `template_id` FK on `message_dispatch_logs` if the freetext snapshot fields turn out not to be intentional (§6 finding 4) — needs the UNKNOWN resolved first.
8. **P4/Phase 4:** `flow_executions`/paused-step persistence table for the automation engine's delay nodes.
9. **P3:** document (don't convert) inconsistent status columns (§6 finding 5) — no migration needed if choosing the documentation-only path.

## 29. API Evolution Roadmap

- **P2:** standardize a single response envelope (`{success, message, error_code?, data?}`) for all **new** endpoints going forward; do not attempt a mass retrofit of the 78 existing bare-`{message}` responses (§7) — that's high-risk, low-value churn on working code. Document the target shape once and enforce it via code review for new work.
- **P2:** decide deliberately whether the internal dashboard API (~145 of 156 routes, currently unversioned and coupled to the SPA's current shape) needs its own versioning scheme before any partner-facing expansion of that surface — today only the external `v1` partner API is versioned (§7).
- **P2:** consolidate the two independently-evolved API-key auth mechanisms (`AuthenticateApiKey` vs. `ApiAuthMiddleware`) into one, once their overlapping surface is mapped (§5, §24).
- **P3:** consider `Route::apiResource` for genuinely CRUD-only future endpoints (new CRM tables in Phase 5 are a natural first candidate) rather than continuing the hand-declared-route convention indefinitely — not urgent, current convention is consistent and documented, just more boilerplate than necessary going forward.

## 30. Testing Roadmap

**P0 — first tests written, before any new feature work:** tenant-isolation and RBAC regression tests, because this report independently confirmed the current logic is correct (§9, §20) and that correctness currently has zero automated protection. Suggested matrix (Super Admin / Agent / Agent's Client / Direct Client × the core actions):

| Action | Super Admin | Agent | Agent's Client | Direct Client |
|---|---|---|---|---|
| List all accounts | ✅ all | ✅ own clients only | ❌ | ❌ |
| View/edit another Agent's client | ✅ | ❌ (404) | ❌ | ❌ |
| Approve own client's quota request | ✅ | ✅ (own clients only) | ❌ | N/A |
| Approve another Agent's client's quota request | ✅ | ❌ (404) | ❌ | N/A |
| Grant a module the granter doesn't hold | N/A | ❌ (intersected away) | N/A | N/A |
| Mutate global Spatie role definitions | ✅ | ❌ | ❌ | ❌ |
| Reach a module-off-gated route directly (bypassing UI) | N/A | N/A | should ❌ once §20's P1 fix lands (currently ✅ for the ~11 ungated modules — this row's current-state failure is exactly what Phase 1 must close) | same |

- **P1:** quota/billing regression tests — 402 vs. 403 gate correctness, `spentAmount()`/`remainingBalance()` accuracy, `InvoiceCreditService` idempotency under concurrent webhook+client-verify race.
- **P1:** webhook signature-verification tests (Meta HMAC, both controllers) — currently correct (§20), needs a regression guard given how security-critical it is.
- **P1:** the CSV bulk-upload worker-starvation fix from Phase 0 needs its own test (§27 Phase 0).
- **P2:** WhatsApp driver-interface tests (both QR and Meta paths) using a mocked HTTP layer, since no live WhatsApp/Meta connection is testable in CI.
- **P2:** frontend — introduce Vitest + Testing Library (matching Vite already in use) starting with `ProtectedRoute`/`AuthContext` permission-gating logic, since that's the other half of the "UI-hiding is not the only enforcement, but it must still be correct" requirement.
- **P3:** load tests to convert §22's qualitative scale estimates into real numbers, once Phase 0/1 land.

## 31. Deployment Roadmap

**Current state** [Fact]: entirely manual — SSH access, hand-run `git pull`/`composer install`/`php artisan migrate --force`, manual `chown`/`chmod`, manual `php8.2-fpm`/nginx restart, separate manual `pm2 restart` for the Node service. The one CI workflow that exists verifies build/boot correctness on every push but has no deploy job at all.

- **P1:** automate at least the deploy *steps* (not necessarily full CI/CD) — a single deploy script checked into the repo that codifies the manual runbook (`Claude outputs/PRODUCTION_DEPLOYMENT_COMMANDS_2026_09_16.md`) removes the single-person-SSH-knowledge dependency and the copy-paste-error risk that caused this session's own subpath/env-file deployment confusion.
- **P2:** add a real health-check endpoint to `backend-api` (`qr-engine-service` already has one at `/health`, §23-equivalent in the observability research) and wire basic uptime monitoring against it.
- **P2:** add error-tracking (Sentry or equivalent) — today, a production error is only discoverable by manually tailing `storage/logs/laravel.log`.
- **P3:** extend the CI workflow into an actual CD pipeline once the deploy script from the P1 item above exists and is trusted — CI running tests is a precondition for safely automating deploys, so don't invert this order.
- **Explicitly not recommended:** Docker/Kubernetes for this stage — the shared-hosting/cPanel-style deployment this session directly observed is a real constraint, not a choice to architect around; a deploy script and a health check solve the actual, observed pain (this session's own multi-turn subpath-deployment confusion) without changing the hosting model.

## 32. Business/Unit Economics Considerations

*(This section evaluates architectural implications only — it does not invent real provider pricing. Every number below is either the user's own stated figure, explicitly marked, or a structural risk category requiring real numbers the codebase cannot supply.)*

- **[Stated]** QR offered free/cheap via Agents, ~₹0.20/message possible pricing, Meta priced separately, AI with free daily credits + paid top-ups. **UNKNOWN — requires confirmation**, all of these: actual WhatsApp Cloud API per-message/per-template cost from Meta (varies by country/category and changes over time), actual server cost per active QR session (memory footprint of `qr-engine-service`'s in-process session map, not measured in this pass), actual AI provider (Gemini/OpenAI/Anthropic) per-token cost at the volumes implied by "5-7 free credits/day," actual payment-gateway fees (Razorpay/Stripe take a percentage — not sourced here).
- **Dangerous pricing assumption flagged by architecture, not guessed at:** an "unlimited" `billing_model` tier exists in the schema (`unlimited` is a real enum value) with **no architectural usage cap or anomaly-detection mechanism found anywhere** in this pass — a single heavy user on an unlimited plan has no code-level circuit breaker between their usage and the platform's underlying Meta/provider message cost. **This is a real, structural risk to flag before pricing an unlimited tier, not a hypothetical one** — recommend either removing true "unlimited" from the public catalog in favor of a very high soft cap with alerting, or building the anomaly-detection/circuit-breaker before selling it at scale.
- **Server cost per user** scales with: (a) `qr-engine-service`'s per-session memory footprint (unmeasured, §22), (b) the database-as-cache/session/queue load pattern under shipped defaults (§21) — moving to real Redis (§26 item 1) changes this unit-economics picture materially and should be priced with that change already made, not against today's shipped defaults.
- **Agent commission/reseller economics — currently zero backing code** (§14 §2 finding, "does not exist"). **Any pricing model assuming Agent commission or revenue share needs that feature built first (Phase 1/Billing extension) before it can be operationalized** — today an Agent's only real lever is the shared quota pool (§14), not money.
- **Message/AI/storage/bandwidth/queue/support/payment-gateway cost, margin:** all **UNKNOWN — requires confirmation**, genuinely out of this codebase-inspection pass's ability to answer; flagging here so they are not silently assumed away in whatever pricing model gets built next.

## 33. Agent/Reseller Model

**[Fact]** What exists, verified: the 3-tier `account_type`+`agent_id` hierarchy; a real quota **pool** (an Agent's own allocation caps the sum of its Clients' allocations, enforced via `QuotaService::assertWithinPool()`); consistent Agent-scoped visibility across accounts/quota-requests/templates; module-delegation capping (an Agent cannot grant a module it doesn't hold); a working (if bare) customer-reassignment path (`agent_id` can be changed on `update()`, with **UNKNOWN — requires confirmation** whether the quota-pool re-check actually fires on a bare reassignment vs. only on `updateQuota()` — this needs verification before relying on it).

**[Fact]** What does **not** exist, confirmed by direct grep, zero backing code: Agent commission/revenue share, Agent-owned pricing or discounting, an Agent wallet (money held/earned by the Agent — the only "wallet" concept in the codebase belongs to the tenant client, not the Agent), Agent termination/offboarding (no `destroy()` method on `AccountController`, no soft-delete — an Agent can only be suspended, and nothing in the code addresses what happens operationally to that Agent's still-active Clients when it is).

**Recommendation for the "Agent gives free QR, customer later wants Meta/CRM/AI/Social" upgrade scenario the business described:** this is architecturally straightforward given what already exists — `allowed_modules` (already module-gated, already delegation-capped) is the natural mechanism for progressive feature unlock per tenant, and the Provider/Plan/BillingModel split recommended in §11/§27 Phase 1 is the natural mechanism for the engine upgrade (QR→Meta) independent of module unlock. **No new architecture is needed for the upgrade/downgrade flow itself** — it needs a dedicated UI/API workflow (not found in this pass — **UNKNOWN — requires confirmation** whether one exists today beyond the generic `AccountController::update()`) and a business decision on proration/billing-transition rules, which is a product decision this codebase cannot supply.

**Recommendation for Agent commission**, since it must be built from scratch: do not bolt it onto `Account`/`Subscription` as more flat columns (repeating the §11 conflation problem) — model it as its own append-only `agent_commission_ledger` (mirroring the §14/§17 ledger recommendation), computed from real billing events (subscription payments, quota top-ups) rather than a stored running total.

## 34. Final P0/P1/P2/P3 Action Plan

**P0 — must fix before continuing with new feature work:**
1. Add rate limiting/lockout to `POST /api/auth/login` (§8, §20, §25).
2. Fix `PaymentAlertController::bulkUpload()`'s worker-starvation risk under the shipped `sync` queue default (§21, §25).
3. Stand up a real persistent queue worker process (not the cron-gated `--stop-when-empty` burst) for at least `whatsapp-bulk` (§15, §21, §26).
4. Begin the tenant-isolation/RBAC/quota automated test suite (§23, §30) — this is P0 because it protects everything else being built.

**P1 — required before/during the next phase of feature work:**
5. Extend `module.guard:<slug>` to the ~11 currently-ungated modules (§20, §25).
6. Design (spike, don't schedule blind) and execute the Provider/Plan/BillingModel dimension split on `subscriptions` (§11, §27, §28) — the actual unlock for a productized Meta provider.
7. Add `deleted_at`/`SoftDeletes` to `accounts`, `invoices`, `subscriptions`, `message_templates` (§6, §28).
8. Complete the RBAC/tenant-isolation/quota test matrix from §30, not just begin it.
9. Confirm `MetaConfigController`'s current completeness before Phase 2 work starts (§11, currently UNKNOWN).
10. Build CRM's core tables (Contact/Lead/Deal at minimum) — a genuine dependency for the automation engine's named triggers (`lead.created`, etc.) and for Ads/Attribution's completion (§16, §19, §27).

**P2 — important but can wait:**
11. Standardize a response envelope for new endpoints going forward (§29).
12. Consolidate the two API-key auth mechanisms (§5, §24, §29).
13. `.gitattributes` fix for the CRLF/LF noise (§24) — cheap, improves every future code review.
14. Add FK on `activity_logs.agent_id`, resolve the `message_dispatch_logs` template/group-name FK question (§6, §28).
15. Add a `backend-api` health-check endpoint and basic uptime monitoring (§31).
16. Add error-tracking (Sentry or equivalent) (§31).
17. Automate the deploy runbook into a checked-in script (§31).
18. Fix the inbound-webhook dedup gap (WAMID guard) to match the pattern already used elsewhere in the same controller (§12).
19. LinkedIn OAuth, Lead Form objects, video ad creatives (§18) — narrow, specific, not structural.
20. Queue/dedicate the `NotificationBroadcastController` email-broadcast path instead of synchronous per-recipient sends (§21).

**P3 — future optimization:**
21. Document (don't convert) inconsistent status columns as Model constants (§6).
22. Consider `Route::apiResource` for new CRUD-only endpoints going forward (§29).
23. Clean up stale docblocks/comments identified in §24.
24. Real load testing to convert §22's qualitative estimates into measured thresholds, once P0/P1 land.
25. Extend CI into real CD, once the P2 deploy script exists and is trusted (§31).

---

*End of report. Every [Fact] claim above traces to a specific file and line read directly from the live repository during this pass; every UNKNOWN is flagged rather than guessed. This report intentionally does not recommend a rewrite of any working system — every action item is additive or corrective to what already exists.*
