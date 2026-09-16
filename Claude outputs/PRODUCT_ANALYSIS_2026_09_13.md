# Product Analysis — `wa-saas-platform`

**Method:** Direct source inspection on-device (`sl-laptop-d61`, `C:\xampp\htdocs\wa-saas-platform`) — full `routes/api.php`, `app/` directory tree, `README.md`, `git log`/`git status`, and every prior audit in `Claude outputs/` (10 files, the most recent dated 2026‑09‑13). No code executed, no DB queried, nothing mutated. Claims are tagged **[Fact]** (verified against an actual file), **[Inference]** (a conclusion drawn from facts), or **[Hypothesis]** (plausible, unverified — flagged for you to confirm). This is a fresh pass layered on top of the existing `Claude outputs/FULL_PROJECT_ANALYSIS.md` — where that report's findings are still accurate they're carried forward and marked as such; where the code has moved on, that's called out explicitly.

---

## 1. What this product actually is

**[Fact]** The README frames this as a WhatsApp alerting tool. The code says something considerably bigger: a **multi-tenant SMB/BFSI marketing-automation suite** built around WhatsApp, with Meta Ads and organic social layered on top. Three independently-run services, one repo:

| Service | Stack | Size |
|---|---|---|
| `backend-api/` | Laravel 11.31, PHP ^8.2, Sanctum, spatie/laravel-permission | 45 controllers, 26 models, 63 migrations |
| `frontend-app/` | React 19.2 + TypeScript + Vite 8, react-router 7 | ~14,900 LOC across `pages/` alone |
| `qr-engine-service/` | Node (ESM) + Express 5 + Socket.IO 4 + Baileys `^7.0.0-rc14` | thin, stateful WhatsApp-socket holder |

`backend-api` is the sole system of record; every tenant account is configured with one of two **engines** — `qr` (unofficial WhatsApp Web via Baileys, held by `qr-engine-service`) or `meta` (official WhatsApp Cloud API, called directly). This architectural boundary is sound and consistently enforced (Sanctum bearer token, `X-Internal-Secret` for the internal hop).

---

## 2. Full feature inventory (product view, not code view)

Grouped by what a tenant actually uses, verified against `routes/api.php` (540+ lines) and the matching frontend `pages/`:

**WhatsApp core**
- Account connection: QR pairing (live Socket.IO status) or Meta Cloud API credential vault, one engine per tenant.
- Payment alerts: single-send, CSV bulk-upload, `payment_ref` dedup, anti-ban send pacing.
- Message templates: dynamic variables, Super-Admin approval workflow, a "1 template per client" testing gate against a platform test device.
- **Group messaging (new, uncommitted — see §5):** contact groups, add-contacts, bulk group dispatch, a unified `message_dispatch_logs` audit trail across every send pathway.
- Chatbot rule engine: keyword/intent auto-reply + execution log.
- No-code **WhatsApp Journey Builder**: multi-turn flow engine (`WhatsAppJourneyEngine.php`, 28.8 KB — the single largest backend service file), with per-session state and a "test this flow" action.

**Billing & quota**
- Razorpay/Stripe checkout, invoices, quota top-up request/approval workflow, `unlimited` tier, Super-Admin billing overview across all clients.
- Three hardcoded plans (`PlanCatalog.php`, **[Fact]**): Starter (₹499/mo, 500 msgs, QR engine), Growth (₹1,999/mo, 2,500 msgs, QR engine), Business (₹7,999/mo, 10,000 msgs, Meta engine).

**Analytics & compliance**
- KPI dashboard, charts, CSV/PDF export, row-level message logs (permission-gated separately from aggregates), role-scoped audit-log trail with CSV/PDF export.

**Notifications**
- Broadcast engine + mail templates, in-app notification inbox (deliberately reachable even after subscription lapse), SMTP config, mail send log.

**Developer Portal**
- External API keys (SHA-256 hashed), outbound webhook subscriptions with delivery logs, a versioned external API (`/api/v1/*`: send-payment-alert, send-template, send-message with group routing).

**Social Media Suite** — this is the part the README doesn't mention at all:
- OAuth account linking (Meta/LinkedIn/Google), Meta Ads Launcher (create/pause/resume campaigns) + an **Auto-Budget Guard** (`ads:check-performance-rules`, 15-min cron, auto-pauses campaigns breaching spend/CPL rules and fires a WhatsApp alert).
- AI Ad Copywriter (Gemini-backed, `CopywriterService.php`, 20.7 KB).
- Organic multi-channel publishing, ad comment auto-responder, a unified Social Inbox (FB/IG DMs + lead-gen threads in one view), an Instant Lead CRM, white-label PDF/chart social reporting.

**Platform administration**
- Multi-tenancy via `TenantIsolationMiddleware`, per-tenant `allowed_modules` toggles (17 module slugs, `Account::MODULES`), RBAC via `spatie/laravel-permission` (Super Admin / Admin / User + dynamic roles) with a **granular per-module permission matrix** (view/create/edit/delete per feature area, not just role-level).

**[Inference]** The scope has clearly grown session-by-session well past the README's "WhatsApp alerts" framing into a bundled WhatsApp + social-ads + light-CRM suite. The README (§1–§7) is now materially out of date as a description of the product — it doesn't mention the Social Suite, Journey Builder, or Group Messaging at all.

---

## 3. Business / market positioning

**[Inference, from code evidence]** This targets the same buyer Simple Logic already serves (BFSI/SMB) with a WhatsApp Business API platform, but bundles in social-ads management and a basic CRM — closer to a combined Interakt/AiSensy/WATI + a lightweight Buffer/Meta-Ads-Manager than a single-purpose WhatsApp tool. That bundling is a real differentiator *if* every bundled piece works end-to-end (see §6 for where it currently doesn't).

**[Fact]** Pricing is engine-tiered, not feature-tiered: the two cheapest plans (₹499, ₹1,999) are locked to the unofficial `qr` (Baileys/WhatsApp-Web-automation) engine; only the ₹7,999 plan uses the official Meta Cloud API. **[Hypothesis, flagged for your judgment, not verified against any external ToS text]** Baileys automates WhatsApp Web against WhatsApp's unofficial protocol; number bans/rate-limiting on that channel are a known industry risk for competitors using the same approach (Baileys/whatsapp-web.js-based tools). Worth an explicit business decision on how that risk is disclosed to Starter/Growth customers, independent of anything in this codebase.

**[Fact]** No `plans` table exists — pricing lives in PHP (`PlanCatalog::PLANS`), documented in the code itself as a deliberate trade-off ("if the platform later needs per-tenant custom pricing, promotional plans, or admin-editable plans without a deploy, that's the trigger to promote this into a real table"). **[Inference]** Fine at 3 static plans; a real constraint the moment you want a sales team running custom quotes or a promo code.

---

## 4. Delta vs. the last full audit (`Claude outputs/FULL_PROJECT_ANALYSIS.md`)

That report is thorough and still mostly accurate, but the codebase has moved since it was written. Re-verified against current source:

| Finding in that report | Status now |
|---|---|
| §1: Super Admin Socket.IO RBAC bug (`user.role` vs `user.roles`) | **[Fact] Fixed.** `backendClient.js::verifyAccountAccess()` now correctly does `roles.some(r => r.name === 'super_admin')`, with a docblock explaining the prior misdiagnosis. |
| §2: `routes/api.php` had no throttle on the `api` middleware group | **[Fact] Fixed.** `AppServiceProvider` now registers `RateLimiter::for('meta-webhook', …)` (120/min by IP) and `RateLimiter::for('external-api', …)` (per-account, DB-driven). |
| §2: Meta webhook signature not verified | **[Fact] Fixed for `/api/webhooks/meta`** — HMAC-SHA256 via `hash_equals()`, credential pulled from `social_provider_configs`, fails closed on missing/misconfigured secret. (See `METAWEBHOOK_HMAC_HARDENING_SUMMARY.md` / `_DBREFACTOR_SUMMARY.md` in `Claude outputs/` for the full history.) |
| §2: `frontend-app/.env` not gitignored | **[Fact] Still open** — confirmed by reading `frontend-app/.gitignore` directly; no `.env` entry. |
| §2: Internal-secret comparisons use `!==`/`===`, not constant-time | **[Fact] Still open** on both sides — `VerifyInternalSecret.php` (`!==`) and `qr-engine-service/src/server.js` (`provided !== INTERNAL_API_SECRET`). Low severity (private service-to-service hop), cheap to fix. |
| §2: Baileys sessions unencrypted on disk | **[Fact] Still open**, unchanged. |
| §3: Zero real test coverage | **[Fact] Still true** — no CI config found anywhere in the repo either (searched for `*.yml`/`*.yaml`, none outside `node_modules`/`vendor`), so nothing is currently gating merges even on the tests that don't exist yet. |
| §4: Large frontend page files | **[Fact] Worse, not better.** `JourneyBuilderPage.tsx` (new since that audit) is now the single largest page at **1,202 lines**, ahead of `DashboardPage.tsx` (1,121, itself grown from 956). |

---

## 5. New, uncommitted work sitting in the working tree

**[Fact]**, from `git status`: the entire **Group Messaging** feature — `ContactGroupController`, `ContactGroup`/`ContactGroupMember` models, `ProcessGroupDispatchJob`, the `Services/Groups/` directory, `MessageDispatchLogController` + model, 7 migrations dated 2026‑09‑13, plus the corresponding frontend (`ContactGroupsPage.tsx`, `MessageLogsPage.tsx`, two new services/types) — is untracked or modified in the working tree, not committed. `git log` shows only 6 commits total, the latest two both from today, neither of which include this work.

**[Inference]** This is real, working, wired-up code (it's reachable from `routes/api.php` and the frontend nav), not a stray draft — but it exists nowhere except this one machine's disk. If this laptop has a problem before it's committed, this feature is gone. This is the single most actionable item in this report: commit it (to a branch, if you're not ready to merge to whatever `d1289df` is on).

---

## 6. What's missing — prioritized

Ranked by product/business impact, each grounded in a specific file, not speculation:

1. **[High] Chatbot rules and the Journey Builder likely don't fire for QR-engine tenants at all — i.e., your two cheapest plans.** Traced end-to-end: `ChatbotEngineService::handleInboundMessage()` is only ever called from two places — `MetaWebhookController` (Meta engine, wired and working) and `WhatsAppInboundController` (the intended entry point for QR-engine inbound messages). `WhatsAppInboundController`'s own docblock discloses: *"qr-engine-service does not yet have an inbound message listener wired to actually call this endpoint."* **[Inference]** Since Starter and Growth (₹499/₹1,999, both `qr` engine) are the plans this gap applies to, a tenant on either plan who builds a chatbot rule or a Journey today will find it never triggers — the feature is sold but not reachable on 2 of 3 plans. Worth confirming with a real end-to-end test before this is demoed or sold as working; if confirmed, this is a backend (Laravel — no more work needed there) + `qr-engine-service` fix (wire Baileys' inbound-message event to POST `/api/internal/whatsapp-inbound`).
2. **[High] Uncommitted Group Messaging feature (§5)** — no backup, no code review, no PR trail.
3. **[Med] `/api/social/webhook/{provider}` (lead-gen/comment events) still has no signature verification** — self-disclosed in `SocialWebhookController`, and the code itself now notes the fix is cheap (`social_provider_configs.client_secret` is already being read for the sibling `/api/webhooks/meta` endpoint's verification). Anyone can currently POST a fabricated lead/comment event to that endpoint and have it processed as real.
4. **[Med] Zero real automated tests, and no CI pipeline at all** — `phpunit`/`pest` are installed but unused (2 stub files, 45 lines, no real assertions); no test runner exists in `frontend-app`; nothing in the repo runs any of this on push/PR. For a codebase this size (45 controllers, RBAC/quota/billing logic that has already needed several rounds of audit-and-fix per `Claude outputs/`), this is the highest-leverage structural gap.
5. **[Med] Baileys session credentials stored unencrypted** (`qr-engine-service/sessions/<account_id>/`) — equivalent to a live WhatsApp session token per tenant. Needs an explicit decision (disk encryption, restrictive file perms, or an encrypted store) before a shared/production host.
6. **[Med] No `messages`/message-log table for inbound Meta events** — `MetaWebhookController` logs delivery/read statuses via `Log::info()` only; nothing is persisted, so there's no queryable inbound-message history for the Meta engine either, independent of finding #1 above.
7. **[Low] Pricing is hardcoded PHP, not data** — fine today (3 plans), blocks custom quotes/promos without a deploy.
8. **[Low] `frontend-app/.env` not gitignored** — currently harmless content, but the gap means a future secret dropped in there (matching the pattern used in the other two services) lands in git history.
9. **[Low] Internal-secret comparisons aren't constant-time** — `hash_equals()`/`crypto.timingSafeEqual` cost nothing to add.
10. **[Low] Frontend page-file bloat continuing** — 6+ pages now 800–1,200 lines each, and the ones already re-opened repeatedly by prior audits (per `FULL_PROJECT_ANALYSIS.md` §4) are the ones costing the most on every subsequent change.

---

## 7. What's already solid — don't second-guess this

**[Fact]**, carried forward from the prior audit and re-checked: route middleware composition (`tenant.isolation` → `subscription.guard` → `permission:x` → `module.guard:x`) is layered consistently across the entire route file; mass-assignment is clean across all 26 models; no raw SQL takes request input; the payment-webhook idempotency logic (HMAC-verified, ack-then-process, `gateway_order_id`-keyed) is production-grade; and — genuinely unusual for a codebase this size — nearly every controller and route carries a docblock that names *why* a decision was made and explicitly flags what was deliberately left out of scope, which is exactly how the gaps in §6 were findable by reading the code rather than guessing.

---

## 8. If you do one thing after reading this

Commit the Group Messaging work (§5), then verify finding #1 (§6) with a real QR-engine tenant sending an inbound WhatsApp message and watching whether any chatbot rule or Journey step fires. That single test will tell you whether two of your three priced plans currently deliver a feature you're selling.
