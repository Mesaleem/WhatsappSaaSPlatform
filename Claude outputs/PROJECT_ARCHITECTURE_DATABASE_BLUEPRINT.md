# wa-saas-platform — Project Architecture & Database Blueprint

**Generated:** 2026-09-16
**Purpose:** Single source-of-truth context file. Feed this file to Claude (or any engineer) at the start of any future task on this project instead of re-deriving the architecture from scratch. Everything below was verified by direct inspection of this project's own source files on 2026-09-16 — migration files (all 70, read in full), `database/seeders/*.php` (read in full), `config/permission.php`, `config/database.php`, `.env`/`.env.example`, `app/Models/Account.php`, and `routes/api.php` — plus cross-checked against a real MySQL dump of the working local database (`wa_saas_platform.sql`, committed at repo root, 50 `CREATE TABLE` statements). Where something could not be directly verified, it is marked **[Unknown]** rather than assumed.

---

## 1. System architecture

Three independent services:

| Service | Stack | Role |
|---|---|---|
| `backend-api` | Laravel 11.31, PHP ^8.2 | System of record. All business logic, the database, Sanctum-authenticated REST API. |
| `qr-engine-service` | Node.js, Baileys | Holds live WhatsApp Web sockets (the "qr" engine). Talks to `backend-api` over an internal, shared-secret-authenticated HTTP hop. |
| `frontend-app` | React 19 + TypeScript SPA | The UI. Talks to `backend-api` over Sanctum Bearer-token REST, plus a separate Socket.IO channel for live QR pairing status. |

`backend-api` also has a second, independent WhatsApp pathway — the Meta Cloud API ("meta" engine) — which talks directly to Meta's Graph API rather than through `qr-engine-service`. A tenant account runs exactly one engine at a time, recorded on `whatsapp_sessions` and `subscriptions.engine_type`.

**Database:** MySQL/MariaDB in every environment this project defines. Confirmed directly: `backend-api/.env` and `.env.example` both set `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=wa_saas_platform`; `config/database.php`'s own fallback default is `sqlite`, but every actual environment file overrides it to `mysql`. A stale, unused `database/database.sqlite` file exists in the repo from an earlier setup step and is not what the application reads — do not use it as a schema reference.

---

## 2. The Account / Agent 3-tier hierarchy

Three levels: **Super Admin** (platform operator) → **Agent** (Reseller) → **Client** (end tenant). A Client can also sit directly under the platform with no Agent at all ("direct client").

- **Super Admin** is a `User` row with `account_id = null`, carrying the Spatie role `super_admin`. Super Admin has never had an `Account` row of its own — `User::isSuperAdmin()` (`hasRole('super_admin')`) is the canonical check, and `TenantIsolationMiddleware` keys off it directly, not off any Account.
- **Agent** and **Client** are both `Account` rows, distinguished by `accounts.account_type` (enum: `super_admin | agent | client`, default `client`; the `super_admin` value exists in the enum for schema completeness only — no real Account row is expected to carry it).
- `accounts.agent_id` (nullable, self-referencing FK to `accounts.id`, `ON DELETE CASCADE` on MySQL/PostgreSQL — see §5.3) points a Client at the Agent that provisioned it. Null means a direct/platform client.
- Model relations: `Account::agent()` (`belongsTo(self::class, 'agent_id')`), `Account::subClients()` (`hasMany(self::class, 'agent_id')`), plus query scopes `Account::scopeAgentsOnly()` (`where('account_type','agent')`) and `Account::scopeOwnedByAgent(int $agentId)` (`where('agent_id', $agentId)`).
- An Agent's own primary User is *additionally* assigned the `admin` Spatie role at creation time (on top of the seeded `agent` role, which grants only `manage-accounts` + `manage-templates`) — this is what lets that one user both operate their own account normally (billing, team, WhatsApp setup — via `admin`) and provision/manage their Sub-Clients (via `agent`). Passing the `permission:manage-accounts` gate does **not** by itself grant unrestricted access to every account: `AccountController`'s own `account_type`-driven scoping (`TenantIsolationMiddleware` + `callerAgentScopeId()`) confines an Agent to its own `agent_id` subtree regardless of role/permission names.
- **Invariant enforced in `AccountController::store()`/`update()`** (fixed this session, see §8, Module 5): an Agent-type account can never itself have a non-null `agent_id` — an Agent is always top-level, directly under Super Admin, never nested under another Agent. There is no DB-level constraint for this (no FK/CHECK); it is enforced only at the application layer via an explicit `abort_if(...)` guard.
- **Analytics rollup** (this session, Modules 3–4): `AnalyticsController::summary()`/`charts()` accept `?client_id=` (any specific Account, Agent-or-Super-Admin caller, scoped so an Agent can only select its own Sub-Clients) and rely on `TenantIsolationMiddleware`'s `agent_scope_id` request attribute (the caller's own `account_id` when the caller is an Agent, else null) for automatic Agent→its-Sub-Clients-only rollups with no explicit `client_id`. `Account::hasModuleEnabled()` always evaluates against the *resolved target account*, never the calling user — verified by direct code trace this session (no method in this chain reads off `$request->user()`).

---

## 3. Module gating architecture

Two independent, additive layers cap what a tenant can access. Both must allow a module for it to actually be usable — this is a narrowing intersection, never a union.

**Layer 1 — per-account allowlist.** `accounts.allowed_modules` (nullable JSON array of slugs from `Account::MODULES`). `null` means "every module enabled" — the permanent zero-regression default; a Super Admin narrows a specific account's modules via `PATCH /api/admin/accounts/{id}/permissions`.

`Account::MODULES` (the full slug catalog, `app/Models/Account.php`): `dashboard, whatsapp_setup, send_alert, analytics, chatbot, billing, developer_api, team_management, notifications, meta_ads, social_inbox, lead_crm, comment_automation, reports, templates, device_settings, social_accounts, message_logs, contact_groups`.

**Layer 2 — dynamic, DB-driven kill switch** (`route_categories` + `system_routes`, shipped 2026-09-15, "Fully Dynamic Categorized Route Master & Nested Permission Matrix"). `SystemRoute::activePermissionKeys()` returns the current set of `permission_key`s whose row (and whose parent category) both have `is_active = true`, or `null` if the table has never been seeded (pure no-op, matching Layer 1's existing behavior exactly). A Super Admin can flip a route/category inactive from the Route Master UI to instantly kill that capability platform-wide, independent of any account's own `allowed_modules`.

**Resolution — `Account::effectiveModules()`:**
1. Start from `$this->allowed_modules ?? self::MODULES` (Layer 1).
2. Intersect with `SystemRoute::activePermissionKeys()` if non-null (Layer 2).
3. If this account has an `agent_id`, repeat steps 1–2 for the parent Agent's own Account, then intersect the two results — a Client can never exceed what its own Agent is itself allowed. A dangling `agent_id` (parent Agent no longer resolves) fails closed to an empty set, not "unrestricted" (currently unreachable in practice — no account-deletion endpoint exists anywhere in this codebase).
4. `Account::hasModuleEnabled(string $module)` is simply `in_array($module, $this->effectiveModules(), true)`.

**Route Master seed data** (`RouteMasterSeeder`, idempotent via `firstOrCreate` only — never `updateOrCreate`, so a Super Admin's later customization via the UI is never silently reverted by a re-run):

| Category (`category_code`) | Routes (`permission_key` → title) |
|---|---|
| `core_common` — Core Common Features (Shared) | `dashboard`→Dashboard, `analytics`→Analytics, `billing`→Billing & Plans, `team_management`→Team Users |
| `whatsapp_suite` — WhatsApp Messaging Suite | `whatsapp_setup`→WhatsApp Setup, `send_alert`→Send Alert, `chatbot`→Chatbot Rules, `templates`→Template Manager, `device_settings`→Device Settings, `message_logs`→Message Logs & Audit Trail, `contact_groups`→Custom Contact Groups (Paid Addon) |
| `social_suite` — Social Media Suite | `social_accounts`→Social Accounts, `meta_ads`→Meta Ads Launcher, `lead_crm`→Instant Lead CRM, `social_inbox`→Unified Social Inbox, `comment_automation`→Comment Rules, `reports`→Social Reports |
| `platform_other` — Other Platform Features | `notifications`→Notifications, `developer_api`→Developer API |

(`notifications` and `developer_api` are real `Account::MODULES` slugs with real nav gates but were absent from the frontend's original 3 hardcoded category groups — backfilled into a 4th category here specifically so seeding this table doesn't silently strip them from every account the moment it runs.)

---

## 4. Roles & permissions catalog (Spatie laravel-permission)

`config/permission.php` confirmed: default table names (`permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`), **`'teams' => false`** — roles are global across the whole platform, never tenant-scoped (this is *why* `RoleController`'s mutating endpoints needed the extra `role:super_admin` gate added this session — see §8).

**Full permission catalog** (`RolePermissionSeeder::PERMISSIONS`): `manage-accounts, manage-subscriptions, send-messages, view-analytics, view-logs, manage-billing-settings, manage-developer-settings, manage-chatbot, manage-team, manage-roles, manage-templates, view-audit-logs, manage-notifications, manage-social-settings, manage-social-accounts, launch-meta-ads, manage-social-leads, view-social-analytics, manage-comment-automation, whatsapp.view, whatsapp.create, whatsapp.edit, whatsapp.delete, social_ads.view, social_ads.launch, social_ads.edit_budget, social_ads.delete_rules, view-activity-logs`.

**Default seeded roles** (`RolePermissionSeeder::DEFAULT_ROLES` — only these four are seeded; any other role, e.g. "Sales"/"Support"/"Manager", is created dynamically by a Super Admin via the Role Management endpoints, subject since this session to the `role:super_admin` gate on create/update):

| Role | Permissions |
|---|---|
| `super_admin` | Every permission in the catalog above. |
| `admin` (Client Admin) | `manage-subscriptions, send-messages, view-analytics, view-logs, manage-developer-settings, manage-chatbot, manage-team, manage-roles, view-audit-logs, manage-notifications, manage-social-accounts, launch-meta-ads, manage-social-leads, view-social-analytics, manage-comment-automation` |
| `user` (standard staff) | `send-messages, view-analytics, view-audit-logs` |
| `agent` (Reseller) | `manage-accounts, manage-templates` — deliberately minimal; an Agent's primary user is *additionally* assigned `admin` at account-creation time, which covers everything else. |
| `social_marketer` (restricted access) | `manage-social-accounts, launch-meta-ads, manage-social-leads, view-social-analytics, manage-comment-automation, manage-team` |

`whatsapp.*` and `social_ads.*` exist purely so `Rule::exists('permissions','name')` accepts them from `TeamController`/`RoleController` for the **Client Admin Granular Permission Matrix** — a per-user *direct* Spatie permission grant (`/team/users/{id}/permissions`), never edited onto a global Role row (since `teams=false`, a Role edit is platform-wide). `social_ads.delete_rules` has no corresponding backend action yet — kept in the catalog only so the UI checkbox is storable.

Legacy role names (`Super Admin`, `Admin`, `User`) are renamed in place by the seeder on every run, never re-created as duplicates — this is what makes `RolePermissionSeeder` safe to re-run against an older, already-seeded database (see the production deployment guide).

---

## 5. Database schema — full current state

All 49 application tables (plus Laravel's own `migrations` tracking table, created automatically, not listed below). Grouped by domain. Every column/index/foreign-key below was verified against both the migration source files and an actual `SHOW CREATE TABLE`-equivalent dump of the working database (`wa_saas_platform.sql`) — the two agree exactly on every table checked.

### 5.1 Framework & auth

| Table | Key columns |
|---|---|
| `users` | `id, account_id (FK→accounts, null=Super Admin, SET NULL)`, `name, email (unique), phone_number (nullable,32), email_verified_at, password, is_active (bool, default true), remember_token`, timestamps. Index: `(account_id, is_active)`. |
| `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | Laravel framework defaults, unmodified. |
| `personal_access_tokens` | Sanctum default (`morphs('tokenable')`, hashed `token`, `abilities`, `expires_at` indexed). |
| `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` | Spatie laravel-permission, default table/column names, no `team_id` column (`teams=false`). |

### 5.2 Accounts & billing

| Table | Key columns |
|---|---|
| `accounts` | `id, account_type (enum: super_admin/agent/client, default client), agent_id (nullable, self-FK, CASCADE), company_name, logo_url, brand_accent_color(7), gemini_api_key (text), primary_phone, status (default active), is_platform_device (bool), api_rate_limit_per_minute (default 60), allowed_modules (json, nullable), max_users_limit (nullable), module_assignment (default 'both'), allow_facebook/instagram/linkedin/youtube (bool, default false)`, timestamps. Indexes: `status`, `agent_id`, `account_type`. **Legacy note:** the original `name` column was renamed to `company_name`, and `subscription_status`/`expires_at` were dropped in favor of the dedicated `subscriptions` table — a production database still carrying the old names/columns predates that migration and needs manual reconciliation before the catch-up migration runs (see the migration file's own docblock). |
| `subscriptions` | `id, account_id (FK, CASCADE), engine_type (default 'qr'), billing_model (default 'flat_quota'), rate_per_message (decimal 8,4, nullable), total_allocated_messages (nullable), used_messages (default 0), price_paid (decimal 10,2), payment_mode (default 'cash'), starts_at, expires_at (nullable), status (default 'active')`. Indexes: `(account_id, starts_at)`, `status`. |
| `invoices` | `id, account_id (FK, CASCADE), invoice_number (unique), plan_key, plan_label, amount, tax_amount, total_amount, currency(3, default INR), payment_gateway, gateway_order_id (nullable, unique), gateway_payment_id, status (default pending), paid_at, gateway_raw_response`. Indexes: `(account_id,status)`, `(account_id,created_at)`, `(status,paid_at)`. |
| `payment_gateway_settings` | One row per gateway (`gateway` unique: razorpay/stripe). Test+live key pairs, `mode`, `is_enabled`. |
| `quota_requests` | `id, account_id (FK CASCADE), requested_by (FK users CASCADE), requested_extra_messages, reason, status (default pending), reviewed_by (FK users, SET NULL), reviewed_at, invoice_id (FK invoices, SET NULL)`. Indexes: `(account_id,status)`, `(status,created_at)`. |

### 5.3 WhatsApp core

| Table | Key columns |
|---|---|
| `whatsapp_sessions` | `id, account_id (unique, FK CASCADE), meta_phone_number_id, meta_waba_id, meta_access_token (text), meta_webhook_verify_token (unique), status (default disconnected), last_connected_at`. Index: `status`. |
| `message_dispatch_logs` | Unified cross-channel send log. `id, account_id (FK CASCADE), source (web_ui\|web_template\|api\|chatbot\|journey), api_key_id (FK api_keys, SET NULL), recipient_phone, recipient_type (default individual), group_id (FK contact_groups, SET NULL), group_name, recipient_count (default 1), success_count (nullable), failure_count (nullable), status, error_reason, has_media (bool, always false today — no driver actually sends media yet), reference_type, reference_id, template_name, message_preview(200), sent_at, gateway_message_id`. Indexes: `(account_id,created_at)`, `(account_id,status)`, `(account_id,source)`, `gateway_message_id`. **This is the table Modules 2–4 (this session) fixed the analytics rollup against** — a group-send batch is one row with `success_count`/`failure_count`, not one row per recipient; counting rows instead of summing those two columns undercounts group-heavy accounts (fixed earlier this session, see §8). |
| `message_templates` | `id, account_id (nullable FK, CASCADE — null=global template), industry_type, title, template_body, variables_schema (json, nullable), header_type (enum text/image/document, default text), header_media_url, status (enum: pending, pending_agent_review, pending_admin_review, pending_meta_approval, approved, rejected — widened from the original 3-value set for the Tiered Template Approval Workflow), is_super_admin_tested (bool), tested_at, rejection_reason, created_by (FK users, SET NULL)`. Indexes: `(account_id,status)`, `status`, `industry_type`. |
| `contact_groups` | `id, account_id (FK CASCADE), name, group_type (default 'internal_segment', or 'native_wa_group'), wa_group_jid, invite_link, sync_status, sync_error, is_default (bool)`. Indexes: `(account_id,is_default)`, `(account_id,group_type)`. |
| `contact_group_members` | `id, group_id (FK contact_groups, CASCADE), phone_number, name`. Unique: `(group_id, phone_number)`. No `account_id` — tenant scoping goes through `group_id → contact_groups.account_id`. |
| `chatbot_rules` | `id, account_id (FK CASCADE), name, match_type (exact/contains/starts_with/regex/fallback), keywords (json), response_type, response_payload (json), priority (default 100, lower = evaluated first), is_active`. Index: `(account_id,is_active,priority)`. |
| `chatbot_logs` | `id, account_id (FK CASCADE), chatbot_rule_id (nullable FK, SET NULL), sender_phone, incoming_message, reply_sent, status (replied/ignored/failed)`. Index: `(account_id,created_at)`. |
| `whatsapp_flows` | No-Code Journey Builder. `id, account_id (FK CASCADE), name, trigger_type (keyword/ctwa_referral/default), trigger_value, graph_data (json — nodes+edges), is_active`. Index: `(account_id,is_active,trigger_type)`. |
| `whatsapp_flow_sessions` | `id, account_id (FK CASCADE), flow_id (FK whatsapp_flows, CASCADE), phone_number, current_node_id, context_data (json), status (active/completed/expired — no scheduled sweeper exists for 'expired' yet, disclosed gap), last_interaction_at`. Index: `(account_id,phone_number,status)`. |

### 5.4 Developer platform

| Table | Key columns |
|---|---|
| `api_keys` | `id, account_id (FK CASCADE), name, key_prefix(32), key_hash(64, unique), secret_prefix(32, nullable), secret_hash(64, nullable, unique — dual-factor x-api-key/x-api-secret scheme, only populated once a key holder explicitly regenerates a secret), last_used_at, expires_at, revoked_at`. Index: `(account_id,revoked_at)`. |
| `webhook_subscriptions` | `id, account_id (FK CASCADE), url, secret (text, encrypted-cast), events (json), is_active`. Index: `(account_id,is_active)`. |
| `webhook_deliveries` | `id, webhook_subscription_id (FK CASCADE), event, payload (json), response_code, status, attempt (default 1)`. Index: `(webhook_subscription_id,created_at)`. |

### 5.5 Notifications & audit

| Table | Key columns |
|---|---|
| `mail_settings` | Single platform-wide SMTP config row. |
| `notification_templates` | Reusable mail content, platform-wide (not account-scoped). |
| `notification_broadcasts` | One row per sent campaign. `template_id, account_id (nullable — snapshot for a single-tenant broadcast, null for multi-client/platform-wide), channels (json), target_type, recipient_count, email_sent_count, email_failed_count, sent_by, sent_at`. |
| `in_app_notifications` | Per-user inbox fan-out. `broadcast_id (SET NULL), user_id (CASCADE — deleted with the user), title, body, is_read, read_at`. |
| `mail_logs` | Per-recipient send-attempt log. `broadcast_id, account_id, recipient_email, status (enum sent/failed), error_message, sent_at`. |
| `login_audit_logs` | One row per login attempt (success/failed). `user_id, account_id, role, email, ip_address, user_agent, status, logged_in_at`. |
| `activity_logs` | Global CRUD/mutation audit trail (distinct from `login_audit_logs`, which only covers authentication events). `user_id (SET NULL), account_id (SET NULL), agent_id (no FK), module_name, action_type (create/update/delete/toggle), route_path, ip_address, old_values (json), new_values (json)`. Indexes: `(account_id,created_at)`, `(agent_id,created_at)`, `(module_name,created_at)`, `action_type`. |

### 5.6 Dynamic Route Master

| Table | Key columns |
|---|---|
| `route_categories` | `id, category_name, category_code (unique), icon_name, sort_order, is_active`. |
| `system_routes` | `id, category_id (no DB-level FK — enforced at application layer only), route_title, route_path, permission_key, is_agent_assignable, is_active, sort_order`. Indexes: `category_id`, `permission_key`. |

### 5.7 Social Media Marketing & Meta Ads

| Table | Key columns |
|---|---|
| `social_provider_configs` | Platform-level OAuth app vault, one row per provider (`meta`/`linkedin`/`google`), `client_id`/`client_secret` encrypted-cast text. |
| `social_accounts` | Tenant-bound external assets. `id, account_id (FK CASCADE), provider, asset_type (facebook_page/instagram/meta_ad_account/linkedin_page/youtube_channel), provider_id, access_token/refresh_token (encrypted text), token_expires_at, health_status`. Unique: `(account_id,provider,provider_id)`. |
| `leads` | Instant Lead Bridge from Meta Lead Ads. `id, account_id (FK CASCADE), social_account_id (SET NULL), provider_lead_id (unique — technical dedup), lead_name/phone/email, raw_field_data (json), tenant_notified_at, lead_welcomed_at`. Index: `(account_id,lead_phone,created_at)` (24h business-level dedup is application-level, not a DB constraint). |
| `ad_campaigns` | `id, account_id (FK CASCADE), social_account_id (SET NULL), meta_campaign_id (unique), name, objective, status (default ACTIVE), daily_budget, cpl_threshold, last_spend/last_impressions/last_leads/last_cpl (cron-refreshed cache, ≤30min stale), auto_paused_at, auto_pause_reason`. |
| `ad_campaign_daily_metrics` | Daily snapshot (not present before this table existed — no historical backfill possible). `ad_campaign_id (FK CASCADE), metric_date, spend, impressions, leads, cpl`. Unique: `(ad_campaign_id,metric_date)`. |
| `comment_automation_rules` | `account_id (FK CASCADE), keyword ('*' = wildcard fallback), public_reply_template, private_dm_template, is_active`. |
| `comment_automation_events` | Redelivery-guard log. `comment_id (unique)`, per-comment reply/DM status+error. |
| `organic_posts` | Organic (non-paid) multi-channel publishing attempt log — logs failures too, unlike `ad_campaigns`. `provider, platform, caption, media_url, status (pending/published/failed), external_post_id, error_message, published_at`. |

### 5.8 Payment alerts (legacy, still active — distinct from `message_dispatch_logs`)

| Table | Key columns |
|---|---|
| `payment_alerts` | The original "Send Alert" feature table — customer-payment-notification sends specifically. `account_id (FK CASCADE), recipient_phone, customer_name, amount, payment_ref (unique per account), status, cost_deducted, gateway_message_id, error_reason, raw_response, sent_at`. Powers the existing "Recent Transactions" grid/export — deliberately kept separate from `message_dispatch_logs` (different, payment-specific required columns). |

---

## 6. API surface — route groups and their gates

All routes live in `backend-api/routes/api.php` (856 lines). Every tenant-facing group sits behind `auth:sanctum` → `tenant.isolation` (+`subscription.guard` for most). Selected groups, with their permission/role gate and any `module.guard`:

| Path prefix | Gate(s) | Notes |
|---|---|---|
| `/v1/*` (external API) | `auth.apikey` or `auth.apisecret` + `throttle:external-api` | Developer Portal / dual-factor endpoints. |
| `/billing/*` | `permission:manage-subscriptions` | |
| `/account/api-key/*` | `permission:manage-developer-settings` | |
| `/roles` (GET) | `permission:manage-roles` | Read-only, unchanged. |
| `/roles` (POST/PUT) | `permission:manage-roles` **+ `role:super_admin`** | **Hardened this session** — see §8, P0 fix: `admin` role held `manage-roles` but roles are global (`teams=false`), so a Client Admin could mutate platform-wide role definitions before this fix. |
| `/whatsapp/meta-config/*` | `role:admin` | |
| `/chatbot/rules` (mutations) | `permission:manage-chatbot` \| the matching `whatsapp.*` granular permission | |
| `/whatsapp/flows/*` | `module.guard:chatbot` + `permission:manage-chatbot\|whatsapp.*` | |
| `/social/ads/*` | `module.guard:meta_ads` + `permission:launch-meta-ads` \| matching `social_ads.*` | |
| `/social/organic-posts/*` | `module.guard:social_accounts` + `permission:manage-social-accounts` | |
| `/team/*` | `permission:manage-team` | |
| `/alerts/*` (Send Alert) | `permission:send-messages` | |
| `/groups/*` | `permission:send-messages` + `module.guard:contact_groups` | |
| `/analytics/*` | `permission:view-analytics` | Supports `?client_id=`/hierarchical rollup — see §2, §8. |
| `/message-logs` | `permission:view-logs` + `module.guard:message_logs` | |
| `/audit-logs/*` | `permission:view-audit-logs` | Login-attempt history — distinct from `/admin/*` activity logs below. |
| `/notification-templates/*`, `/notification-broadcasts/*` | `permission:manage-notifications` | |
| `/admin/accounts/*` | `permission:manage-accounts` | Super Admin (unrestricted) or Agent (own subtree only, via `agent_scope_id`). |
| `/admin/*` (activity logs) | `permission:view-activity-logs` | Super-Admin-only in practice (not granted to any other seeded role). |
| `/message-templates/*` | `permission:manage-templates` | |
| `/admin/whatsapp/devices`, `/admin/whatsapp/self-device/*` | `role:super_admin` | Platform's own test WhatsApp device — precedent reused for the `/roles` fix above. |
| `/admin/billing/*` | `permission:manage-billing-settings` | |
| `/admin/quota-requests/*` | `permission:manage-accounts` | |
| `/admin/social/*` | `permission:manage-social-settings` | |

---

## 7. Full DB migration inventory (chronological, for reference)

70 migration files exist in `backend-api/database/migrations/`, spanning `0001_01_01_*` (framework defaults) through `2026_09_15_140000` (header media URL). One file (`2026_09_11_165503_add_performance_optimization_indexes.php`) is a stub with an empty `up()`/`down()` — contributes nothing. Several later migrations (everything touching `message_dispatch_logs` from `2026_09_13_130000` onward, and `2026_09_15_100000`'s status-enum widening) carry explicit in-code disclosures that they were authored without being run against any database at the time ("pending authorization", "no php binary reachable") — **this is the direct, confirmed root cause of the local/production schema drift this blueprint's companion migration exists to fix.** Cross-checking against the actual `wa_saas_platform.sql` dump (committed 2026-09-15) confirms all of these have since actually been applied to the local MySQL database — every column, index, and foreign key they add is present in that dump byte-for-byte. Production's state is **[Unknown]** — this is exactly why the catch-up migration is defensive rather than assumed necessary or unnecessary.

---

## 8. This session's completed work (2026-09-16)

Five scope-locked fix/verification tasks were completed today, each confirmed against actual source before any change and each staying strictly within its stated file scope:

**Module 2 — Runtime Graph/Count Data & API-Level Group Module Protection** (`AnalyticsController.php`, `Api/V1/GroupController.php`). Confirmed the earlier same-session fix (`resolveRecipientAwareTotals()`, summing `success_count`/`failure_count` for group-dispatch rows instead of counting rows) is intact and correct. Flagged, but did not fix (outside scope lock), a hypothesis that MySQL session timezone could affect date-range boundary queries if the live server's `time_zone` isn't UTC — recommended remedy: add `'timezone' => '+00:00'` to `config/database.php`'s mysql/mariadb connection arrays, pending confirmation. Confirmed `GroupController::create()` already correctly gates on `contact_groups` module.

**Module 3 — Hierarchical Analytics Rollup** (`AnalyticsController.php` only). Added `?client_id=` query param support and a new private `resolveHierarchicalScope()` method so Super Admin can view any specific client's analytics, an Agent can view any of its own Sub-Clients', and (with no `client_id`) an Agent's own analytics automatically roll up across its whole Sub-Client subtree via the pre-existing (but previously unconsumed) `agent_scope_id` request attribute.

**Module 4 — Super Admin "Any Client" Scope & Module Gating Verification** (`AnalyticsController.php` only, verification task). Confirmed, by full code trace, that `$groupModuleEnabled` always evaluates against the selected target Account (never the caller) and that Super Admin's `?client_id=` path is genuinely unrestricted to any account. No behavior change needed — added two comment-only clarifications at the exact points traced.

**Module 5 — SA Direct Client Agent Field & Subscription Overview** (`AccountController.php` only). Confirmed `agent_id` and the `agent:id,company_name,account_type` relation already serialize on every account list/detail response (corrected the task's own premise: `accounts.name`/`accounts.email` don't exist — `company_name` is the real column, email lives on the owning `User`). Found and fixed one real gap: neither `store()` nor `update()` stopped an Agent-type account from also having a non-null `agent_id` of its own (nested-Agent bug) — added an explicit `422 abort_if` guard in both methods, plus eager-loaded `agent` on both methods' own JSON responses (previously required a follow-up request to resolve).

**Also completed earlier the same session, outside the numbered Module sequence** (evidence: dated reports in `Claude outputs/`, no file exists titled "Module 1" — presented here rather than invented):
- **P0 Security Fix — RoleController Cross-Tenant Privilege Escalation** (`routes/api.php` only, +33/-0 lines): the seeded `admin` role held `manage-roles`, and since Spatie roles are global (`teams=false`), any Client Admin could mutate platform-wide role/permission definitions via `POST/PUT /api/roles`. Fixed by layering the existing `role:super_admin` middleware (already used identically for `/admin/whatsapp/*`) in front of the two mutating routes only; `GET /roles` is unchanged.
- **P0 Fix — Group Messaging Undercount**: introduced `resolveRecipientAwareTotals()` in `AnalyticsController` (the fix Module 2 later re-confirmed).
- Additional analytics/UI polish: `ANALYTICS_COMPLETE_BREAKDOWN`, `ANALYTICS_GROUP_KPI_CARDS`, `GROUP_KPI_AND_CHART_CLARITY`, `JOURNEY_BUILDER_AND_SIDEBAR_AND_ACTIVITY_UI_FIX` (see `Claude outputs/` for each report's full detail).

---

## 9. Production data-safety rules (standing, non-negotiable)

**We cannot afford any data loss on production** (`accounts`, `users`, `message_dispatch_logs`, and every other table listed in §5). The following are permanently disallowed against production, without exception or override:

- `php artisan migrate:fresh`
- `php artisan db:wipe`
- Any raw `DROP TABLE`, `TRUNCATE`, or unscoped `DELETE FROM`
- `php artisan db:seed` (unscoped — runs the full `DatabaseSeeder`, which creates demo/test accounts and a hardcoded Super Admin with a placeholder password). Only `--class=RolePermissionSeeder` and `--class=RouteMasterSeeder` are safe to re-run against production (both verified `firstOrCreate`-only, never overwriting existing rows).

The companion files to this blueprint implement this safely:

- **`2026_09_16_999999_sync_production_baseline_schema.php`** — a single idempotent migration. Every table creation is wrapped in `Schema::hasTable()`; every column addition in `Schema::hasColumn()`; every index/foreign-key addition in a custom `information_schema`-backed existence check. Safe to run via `php artisan migrate --force` regardless of which of the 70 preceding migrations production has or hasn't already applied — it only ever adds missing structure, never touches existing rows, never drops or renames anything.
- **`PRODUCTION_DEPLOYMENT_COMMANDS_2026_09_16.md`** — the exact, ordered SSH command sequence: pre-flight `migrate:status` check, mandatory `mysqldump` backup, maintenance mode, code deploy, `composer install`, the migration above, file permission/ownership fixes, cache clear+rebuild (config/route/view/event), the two safe seeders, service restart, and post-deploy verification.

---

## 10. Known, disclosed gaps (carried forward — not fixed by this session's work)

- Zero real automated test coverage (two stock Laravel example test files only); CI runs them but they assert nothing meaningful.
- `qr-engine-service` session files (`sessions/<id>/creds.json`) are unencrypted at rest; the service is single-instance-only by construction (in-memory session map).
- `module.guard` middleware has not been backfilled onto some older route groups (billing/team/analytics groups predate it).
- Inbound Meta webhook messages are logged but not persisted to a dedicated table.
- `whatsapp_flow_sessions.status = 'expired'` is a modeled value with no scheduled command yet sweeping stale `'active'` sessions into it.
- `.gitattributes` line-ending normalization has been recommended twice across prior audits and still hasn't been applied — causes recurring false-positive `git diff` noise on Windows-authored files.
- `backend-api/database/database.sqlite` is a stale, unused file (the app runs on MySQL everywhere) — safe to delete, but left in place as of this writing; do not treat it as a schema reference.
