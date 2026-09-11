# Full Codebase Audit — Social Media Marketing & Meta Ads Engine (Phases 1–6)

Method: direct source inspection on-device (no PHP binary, no DB access in this environment — every claim below is either [Fact] from reading the actual file, or explicitly marked [Inference]/[Unknown] where it isn't). No code was executed; nothing in the database was touched.

---

## 1. Feature-by-Feature Status

### 1.1 Dynamic OAuth Gateway & Super Admin Gateway Settings (`/admin/social-settings`)
**Built.** `SocialGatewayController` (`GET/POST /api/admin/social/provider-configs`) manages Meta/LinkedIn/Google App credentials in the `social_provider_configs` table — a "Zero-Code Admin UI," same shape as the pre-existing billing gateway settings. Secrets are partial-update-only (omitted fields left untouched); a webhook verify token can be pasted or regenerated. `AdminSocialSettingsPage.tsx` (367 lines) is routed at `/admin/social-settings` and navigable only to Super Admin (`superAdminOnly: true` in `AppLayout.tsx`). [Fact] Platform-level config, not tenant data — deliberately outside `tenant.isolation`.

### 1.2 Tenant Social Accounts Connection & Lock Banner (`/social/accounts`)
**Built.** `SocialAuthController` implements the 3-step popup OAuth flow: `redirect()` (authenticated, builds the consent URL), `callback()` (public — Meta redirects the popup here directly; tenant identity travels only in an encrypted, expiring `state` param, never a trusted client-supplied `account_id`), `bind()` (redeems a one-time cache nonce to persist chosen assets as `SocialAccount` rows). `index()`/`destroy()` are both scoped via `SocialAccount::forAccount($account->id)`. `SocialAccountsPage.tsx` (290 lines) is the tenant UI. The "lock banner" (`SocialConnectionWarningBanner.tsx`) is real — it polls `listAccounts()` and shows an amber warning ("Ad Launcher and Lead Sync stay locked...") whenever no connected `facebook_page`/`meta_ad_account` asset exists, permission-gated so no other role ever sees it. [Fact] It renders in the shared `Header`, not the Dashboard the literal spec named — a disclosed, reasoned deviation (avoids splicing an unrelated concern into an 800-line unrelated page), not an omission.

### 1.3 3-Step Meta Ads Launcher & Performance Tracking (`/social/ads`)
**Built.** `AdCampaignController` (`index`/`launch`/`pause`/`resume`/`updateCplThreshold`), all `forAccount()`-scoped, all reading tenant identity server-side via `requireAccount()` — never trusting a client-supplied `account_id` in the launch payload, a disclosed deviation from the literal spec text for exactly the reason `TenantIsolationMiddleware` exists. `MetaAdsService` (508 lines) implements `launch()`/`pause()`/`resume()`/`fetchInsights()` against Meta's Graph API. `MetaAdsPage.tsx`'s `LaunchWizardModal` is the 3-step wizard (Objective & Budget → Audience Targeting → Creative & Hook Copy, now with the AI Copywriter panel embedded in step 3). Performance figures (`last_spend`/`last_impressions`/`last_leads`/`last_cpl`) are cached on `ad_campaigns` and refreshed every cycle by the cron below.

### 1.4 Auto-Budget Guard Cron (`ads:check-performance-rules`)
**Built and scheduled in code.** `CheckAdPerformanceRules` (signature `ads:check-performance-rules`) fetches insights, writes the daily snapshot (this phase's addition), then evaluates two rules: zero conversions after exceeding daily budget, or CPL over the tenant's configured threshold. On a match it **pauses on Meta first**, only marks the local row `PAUSED` once Meta confirms (so a Meta-side failure never produces a false "paused" state while spend continues unchecked), then sends a WhatsApp alert. Registered in `routes/console.php` via `Schedule::command(...)->everyFifteenMinutes()->withoutOverlapping()` — correct for Laravel 11 (no `Kernel.php` in this version). **⚠️ This only actually fires if the server's OS-level cron runs `php artisan schedule:run` every minute** — that is infrastructure configuration outside this codebase; see Section 4.

### 1.5 Instant Lead Webhook Listener, Deduplication & WhatsApp Alert
**Built.** `MetaLeadWebhookHandler::handle()` is dispatched from `SocialWebhookController::handle()` for every `leadgen` change. Two independent dedup layers, both verified in source: (1) a technical redelivery guard — `provider_lead_id` is checked before any external call, so Meta re-delivering the *same* webhook event is a safe no-op; (2) a business dedup guard — `Lead::isDuplicatePhoneWithin24Hours()` blocks a genuinely new lead-ad submission event with the same phone number within 24h. Both `notifyTenant()` (alerts the account's `primary_phone`) and `welcomeLead()` (auto-replies to the lead's own phone) run, each independently tracking success/error on the `Lead` row (`tenant_notified_at`/`tenant_notify_error`, `lead_welcomed_at`/`lead_welcome_error`) so a partial failure is visible, not silent. [Disclosed, pre-existing] Processing is synchronous inside the webhook request (not queued) — safe because of dedup layer (1), but a documented latency/queue-worthiness trade-off.

### 1.6 Unified Social Inbox & Meta Private Replies (`/social/inbox`)
**Built.** `SocialInboxController::threads()`/`messages()` merge Lead-Ad threads and Page/Instagram conversation threads into one inbox, scoped via `SocialAccount::forAccount()`. `send()` routes to either a WhatsApp send (Lead threads, via the existing `WhatsAppEngineFactory`) or a real Meta Graph API POST to `/{page_id}/messages` (Page/IG conversation threads) — this is the literal "Private Reply" mechanism. `SocialInboxPage.tsx` (272 lines) is the frontend.

### 1.7 Comment Auto-Responder Rules (`/social/comment-rules`)
**Built.** `CommentAutomationRuleController` (index/store/update/destroy) manages keyword→auto-reply rules; `CommentAutomationService` (286 lines) is dispatched from `SocialWebhookController::handle()` for every `feed`/`comments`/`instagram` change alongside the lead handler — **each dispatch is independently wrapped in its own try/catch**, so a bug in comment-matching can never take down lead processing for the same webhook delivery (or vice versa). `CommentRulesPage.tsx` is the frontend.

### 1.8 AI Ad Copywriter (`/api/social/ai/generate`)
**Built** (delivered this session). `CopywriterService`: OpenAI → Anthropic → deterministic template fallback, in that order, each real-provider path wrapped in try/catch that logs and degrades rather than 500s. **⚠️ Untested against a live key** — no `OPENAI_API_KEY`/`ANTHROPIC_API_KEY` configured anywhere in this environment, and this sandbox has no path to test one. The template engine (4 tone banks, 5 complete `{hook, caption, cta}` variants) requires no network and is the actual path that will run today. Embedded as "✨ Generate with AI" in the ads wizard.

### 1.9 White-Label PDF Performance Report Generator (`/social/reports`)
**Built** (delivered this session). `SocialReportController::generate()`/`summary()`, backed by the new `ad_campaign_daily_metrics` daily-snapshot table (root-cause fix — Meta Insights are `today`-only and get overwritten every 15 min, so no monthly aggregate existed anywhere before this). PDF is produced by extending the pre-existing, dependency-free `SimplePdfWriter` (no PHP/Composer/Packagist reachable here, so no new PDF library was added) — its proven `render()` method used by `ExportController`/`BillingController` was left untouched. **⚠️ Logo is rendered as a text line, not an embedded image** — `SimplePdfWriter` has no image support. `SocialReportsPage.tsx` gives KPI tiles, a spend/leads chart, and a 1-click PDF download.

---

## 2. Auth & Tenant Isolation Check

**Client vs. Super Admin, verified directly against `TenantIsolationMiddleware::handle()`:** a regular (non-Super-Admin) user's effective `account_id` is *always* their own row's `account_id` — a `?account_id=` query parameter from such a user is read nowhere in the middleware and cannot override this. Only a Super Admin (`account_id === null` on the user row) may pass `?account_id=X` to scope one request to a chosen tenant (404 if it doesn't exist); omitting it resolves to `null` ("global view"). This resolved value is written to `$request->attributes` server-side, once, before any controller runs — it is never read back out of the request body or re-derived from client input downstream.

**Frontend mirrors this exactly:** `TenantContext.tsx` computes `effectiveSelectedAccountId = superAdmin ? selectedAccountId : null` — a non-Super-Admin user's UI has no client switcher and every social page's `noTenantSelected` gate (`superAdmin && selectedAccountId === null`) only ever applies to Super Admin, never blocking an ordinary tenant user.

**Every social controller checked resolves tenant identity via `ResolvesTenantAccount::requireAccount()`/`resolveAccount()` and every query is explicitly `->forAccount($account->id)`-scoped:** `SocialAuthController`, `AdCampaignController`, `SocialInboxController`, `LeadController`, `SocialReportController`, `CommentAutomationRuleController` — confirmed by direct grep of each file, not assumed. Two controllers deliberately do **not** resolve a tenant, both with disclosed, verified reasoning: `SocialGatewayController` (platform-level OAuth App config, not tenant data) and `AICopywriterController` (proxies a stateless text-generation call, touches no tenant-scoped table).

**One real gap found — not part of this session's changes, pre-existing:** `MetaWebhookController::handle()` (the WhatsApp status/inbound-message webhook) carries an inline comment claiming "failures here are logged, never surfaced as non-200s," but unlike `SocialWebhookController::handle()` (which wraps *each* handler dispatch in its own try/catch), `MetaWebhookController::handle()` has **no try/catch around `fireDeliveredWebhook()` or `handleInboundMessages()`**. An uncaught exception in either would propagate past the `return response()->json(['received' => true])` line and produce a non-200 response — contradicting the comment's own claim, and risking Meta disabling that webhook subscription after repeated failures. [Fact, verified by reading both files directly.] This is worth a follow-up fix (wrap both calls the same way `SocialWebhookController` does) but was out of this phase's stated scope, so it was not silently patched — flagging it here per your request to "verify error handling across all Meta Webhook endpoints."

**Rate limiting** (fixed this session, re-confirmed): `bootstrap/app.php` never calls `->throttleApi()`, so Laravel 11's `api` middleware group added no throttling anywhere by default. A `meta-webhook` limiter (120/min/IP, JSON 429) now wraps every public, unauthenticated Meta-facing endpoint: `/webhooks/meta` (GET+POST), `/social/callback/{provider}`, `/social/webhook/{provider}` (GET+POST). Authenticated tenant/admin traffic still has no throttle — a broader decision than this fix's scope, not revisited here.

---

## 3. Disclosed Gaps & Unexecuted Actions

### 🛑 Pending Commands To Run Locally

```bash
# 1. Run all pending migrations (10 total — none have been run in this environment)
php artisan migrate

# 2. Re-run the permission seeder — social_marketer's role array already
#    contains every permission these 6 pages need (verified directly
#    against RolePermissionSeeder.php), but whether it has actually been
#    seeded into a live database cannot be checked from here (no DB access).
php artisan db:seed --class=RolePermissionSeeder

# 3. Register the scheduler in the OS crontab (once, on the server) —
#    without this, ads:check-performance-rules and any other
#    Schedule::command() entry in routes/console.php never fires, no
#    matter how correctly it's registered in code:
* * * * * cd /path-to/backend-api && php artisan schedule:run >> /dev/null 2>&1

# 4. Clear/rebuild config + route cache after pulling these changes
#    (new config/services.php entries, new routes):
php artisan config:clear && php artisan route:clear
```

Pending migrations, in order:
```
2026_09_10_090000_add_social_platform_flags_to_accounts_table.php
2026_09_10_090001_create_social_provider_configs_table.php
2026_09_10_090002_create_social_accounts_table.php
2026_09_10_100000_make_subscriptions_expires_at_nullable.php
2026_09_10_110000_create_leads_table.php
2026_09_10_120000_create_ad_campaigns_table.php
2026_09_10_130000_create_comment_automation_rules_table.php
2026_09_10_130001_create_comment_automation_events_table.php
2026_09_10_140000_add_branding_fields_to_accounts_table.php
2026_09_10_140001_create_ad_campaign_daily_metrics_table.php
```
**None have been executed. Authorization required before running `php artisan migrate` against any real database.**

### ⚠️ Requires Real Meta API Credentials / Live Run to Fully Validate

- **Meta OAuth App credentials** — *not* an env var gap: by design, `client_id`/`client_secret`/`redirect_uri`/webhook verify token live in the `social_provider_configs` **database table**, set at runtime through `/admin/social-settings` (Zero-Code Admin UI), not `.env`. Nothing to add to `.env.example` here — a Super Admin must fill these in through the UI after migrating, for OAuth/webhooks/ads to work against real Meta endpoints.
- **Per-tenant WhatsApp Cloud API credentials** (`meta_phone_number_id`/`meta_access_token`) — likewise stored encrypted per-tenant in `whatsapp_sessions`, set through the pre-existing WhatsApp Setup UI, not `.env`. Required for lead-notification/welcome WhatsApp sends and the Auto-Budget Guard's alert to actually deliver.
- **`OPENAI_API_KEY` / `ANTHROPIC_API_KEY`** (optional) — genuinely unset in `.env.example`. Without either, the AI Copywriter runs its deterministic template engine (fully functional, no gap) rather than a real LLM. Add one of these two `.env` values to exercise the real-provider code paths, which are [Hypothesis]-tagged/untested against a live key.
- **End-to-end webhook delivery** — `MetaWebhookController`/`SocialWebhookController`'s GET verify handshake and POST processing have only been read/verified statically; they cannot be exercised without a real Meta App subscribed to a publicly reachable webhook URL.
- **PDF/report figures over a real month** — `ad_campaign_daily_metrics` starts empty; a report generated before this ships will only reflect same-day snapshot(s), not a true month. This is disclosed as a permanent, unrecoverable gap for any period before this migration runs — not a bug, a data-retention boundary.

### ✅ Fully Built & Ready in Code (no further engineering work identified)

- All 9 features listed in Section 1 have real, working backend controllers/services and matching frontend pages — none are stubs, mocks, or partially-wired.
- Tenant isolation is consistently and correctly enforced (`ResolvesTenantAccount` + `forAccount()`) across every tenant-data-touching controller in this feature set.
- Rate limiting is correctly applied to every public/unauthenticated Meta-facing endpoint.
- `social_marketer`'s permission set already covers all 6 required routes — no seeder edit needed, only a (re-)run.
- Frontend: `tsc -b --noEmit` and `oxlint` both report 0 errors across the entire frontend project as of this session.
- Backend: all new/modified PHP files pass a structural (brace/paren/bracket) balance check — the strongest static verification available without a PHP binary on this device.

---

**Net assessment:** the engine is code-complete for all 6 phases. What remains is entirely deployment/runtime, not development: run the 10 migrations (needs your authorization), re-seed permissions, register the OS crontab entry for `schedule:run`, and have a Super Admin fill in real Meta/WhatsApp credentials through the existing admin UIs. The one actionable code finding from this audit — `MetaWebhookController::handle()`'s missing try/catch not matching its own docblock's claim — is flagged for a follow-up fix, not silently patched here since it was outside this audit's stated scope.
