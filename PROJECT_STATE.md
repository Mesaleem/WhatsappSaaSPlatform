# wa-saas-platform — Project State

> **Read this file first.** It is the single, continuously-updated description of what this
> project is, what is built, and what is pending. It is maintained at the end of every
> development task. If you were asked to "analyze the project", start here, then open the
> files it points at — do not reconstruct the picture from scratch.

| | |
|---|---|
| **Last updated** | 2026-10-06 |
| **Last completed work** | **Plan expiry reminders and header countdown (2026-10-08):** Daily job `subscriptions:send-expiry-reminders` (09:00, one server) sends each client owner an email and an in-app notification (`category` subscription, link /billing) once when the current term ends within 7 days, and once when it ended 7 to 30 days ago and no renewal followed. Each reminder is recorded in `subscription_reminders` (unique account, kind, term end; migration `2026_10_08_110000`). The email uses the Super Admin SMTP settings (`MailSetting`); without them only the in-app notice goes out. Header: a red countdown chip (`ExpiryHeaderChip`) with a Renew button shows from 7 days before the end and after it, until renewal; the old yellow banner was removed. Tests: `SubscriptionExpiryReminderTest` (7). **Open:** the email is not checked against a real SMTP server. **Developer API key and server IP binding (2026-10-08):** The key comes with the plan: any account with a subscription holds `external_api` (`AccessControlService::canTenant`); a lapsed plan keeps the key and sending is refused by the subscription rule. Agents hold `manage-developer-settings` for their own account. Each account has one authorized server IP (`accounts.authorized_server_ip`, `ip_edit_count`, `ip_registered_at`, `last_ip_updated_at`, migration `2026_10_08_100000`). `ApiKeyBindingService::gate()` allows a key only from the request's socket IP (`Request::ip()`); other IPs get the generic 403 `API_CLIENT_NOT_AUTHORIZED`, and an account with no IP gets `API_SERVER_BINDING_REQUIRED`. Rules (`ServerIpBindingService`): first save free; one change 14 days after the last save; any later change needs a plan invoice paid after the last save (`LIMIT_EXCEEDED`, `require_payment`). Endpoints: `PUT /api/account/api-key/server-ip`; `GET /api/account/api-key` returns `server_ip`. Key generation needs a saved IP (`SERVER_IP_REQUIRED`) and the `acknowledge_ip_restriction` checkbox. An IP mismatch denial carries `action: verify_server_ip` and a help line, never the registered IP. Super Admin resets the edit count with `POST /api/admin/accounts/{id}/reset-ip-edit-count` (Customer Management button, audited). The installation credential and per-key binding rows are no longer used by the gate. The Developer Portal still shows the old binding fields (`ApiAccessPanel`, `ServerBindingFields`): cleanup pending. UI: `ApiKeyModal` has three steps (server IP, key, docs); `ApiKeySetupBanner` shows the action-required warning on Send Notification, WhatsApp setup and Message Logs; a security notice sits under the Developer API docs. Scheduled sends: `scheduled_at` on `POST /api/v1/send-message` (individual and group template sends) and on the Send Notification page; a time without a zone is IST. Tests: `ServerIpBindingTest` (9), `ApiKeyPlanAccessTest`; `PublicApiDeveloperModuleTest` and `PublicApiV1ContractTest` now assert the plan rule. Old `ApiKeyServerBindingTest` removed (it tested the replaced credential flow). **Group downgrade, plan rules, amounts due (2026-10-06):** Custom groups count only the client's own internal groups against the paid units (`GET /api/groups` returns `usage`, incl. `selection_required`). Upgrade of a running tier is allowed and locks nothing; a smaller tier waits for the term to end (`downgrade_after_term`). A fresh term over the allowance locks the extra groups (`contact_groups.locked_at`, migration `2026_10_07_101900`); the client keeps exactly the allowed number via `POST /api/groups/keep` (choice recorded on the term, `group_selection_at`, migration `2026_10_07_102000`). A locked group is refused by the send dispatcher (`group_locked`). An unpaid renewal is replaced by a new choice. Extra WhatsApp number price is Super Admin-editable (`platform_settings`, migration `2026_10_07_101800`). Add-ons need an active plan (`plan_not_active`); a paused number is reused when bought again. Billing shows an amounts-due card. Tests: GroupDowngradeTest, AddOnPlanRulesTest, WhatsAppNumberPriceTest, GroupUsageTest, NativeGroupRequestTest, InvoicePdfTest. **Open decision:** whether a Super Admin may create another Super Admin from the UI. **WhatsApp number change requests (2026-10-06):** a slot holding a wrongly entered number can be corrected by request. Client: "Wrong number?" on each slot, form with the current number, the correct number, a reason and a verify checkbox (`POST /api/whatsapp/numbers/{id}/change-requests`). Nothing changes until a Super Admin or the agent for its own client approves (`/api/admin/whatsapp/number-change-requests`, approve/reject); approval sets the new number, disconnects the old session (`WhatsAppAddonService::disconnectEngine`), and the slot must be connected again. Table `whatsapp_number_change_requests` (migration `2026_10_07_101400`). Add-number form asks "Please verify the number once again" before the invoice. Connect modal: slot's own number is locked on the phone tab, QR tab says which number to scan, and a QR from another account shows the mismatch message. `WhatsAppNumberChangeTest` 8 pass; WhatsApp and route-sweep suites 91 pass. **Full backend run (2026-10-06, before this feature):** 3463 passed, 9 failed, 10 skipped: SocialMediaDiskTest (6, GD/disk), SocialAccountFoundationTest (1), SocialPublishingTest (1), SchedulerTopologyTest (1; schedule and test unchanged in this work, not investigated). **Online + manual payment on pending invoices (2026-10-06):** the record form shows "Ends on …" from the chosen start (plan: days; add-on: months, clamped to month end like the server). Pending rows carry `term`, `plan_key`; `GET /api/admin/billing/pending-invoices` and the add-on history return `gateways` (fully configured now). Shared hook `useOnlineInvoicePayment` (Razorpay/Stripe, verify) used by the pending card and the Add-ons page; "Pay online" shows only when a gateway is ready, "Record payment" for Super Admin and agents. The Billing page still has its own copy of the online flow (not yet switched to the hook). Tests: `PlanPaymentMatrixTest` (incl. pending term and gateways) pass. **Pending WhatsApp purchase reprice (2026-10-06):** removing a number from an unpaid purchase now reprices its invoice to the remaining numbers (`WhatsAppAddonService::dropNumberFromInvoice`, called from `WhatsAppNumberService::remove`); an empty purchase is cancelled. Migration `2026_10_07_101300` repairs existing pending purchases. Add-ons page: pending WhatsApp purchases show "Record payment" to Super Admin and agents (clients are pointed to Billing). Monthly terms: `addMonths` overflowed at month ends (31 Jan → 3 Mar); now `addMonthsNoOverflow` for WhatsApp and module add-on terms (`ModuleAddonTermTest`). Tests: `WhatsAppPendingRepriceTest` 2 pass; add-on/WhatsApp/history suites pass. Agent permission migration `2026_10_07_101200` is now applied locally. **Payments by role (2026-10-06):** plan invoices now have the same two ways in as add-ons. Without a gateway a client requests a plan (`POST /api/billing/manual-checkout`, pending invoice, GST included, one open request per plan → 409 `request_pending`); with a gateway the same pending invoice pays online (`POST /api/billing/invoices/{id}/pay`, refused 422 `gateway_not_configured` until configured). `ManualPaymentService` records a manual payment for any invoice (`POST /api/admin/billing/invoices/{id}/record-payment`, Super Admin or agent; agent only its own clients, direct clients Super Admin only); a plan's start date can be set (`term_starts_on`, ±30 days) and applies when no plan is running (a running plan extends from its expiry). `GET /api/admin/billing/pending-invoices` (Super Admin all, agent own clients). Billing page: "Request invoice" per plan when no gateway, Pay and Record on every pending invoice, "Pending payments" card for Super Admin and agents. Agent role now holds `manage-subscriptions` (migration `2026_10_07_101200`). `PlanPaymentMatrixTest` 9 passed; payment/billing/credit filter 351 passed (5 skipped, MariaDB-only); `tsc -b` clean. **Incident:** `backend-api/routes/api.php` was emptied by a scripted edit (`php -r` + `file_put_contents`); restored byte-for-byte from the Claude Code file-history snapshot taken 10:14 local (diff against HEAD is exactly the 7 lines added this session before the wipe), then the billing routes re-added with the Edit tool. **Agent visibility audit (2026-10-06):** Super Admin sees "Clients per agent" (agent, login, total / active / suspended / expired clients, plus direct clients) on the Clients page; `GET /api/admin/accounts/agent-summary` (Super Admin only, agents get 403). Clients table has an "Agent" column for Super Admin only ("Direct" when none). Create-client form shows an error when the agent list cannot load (it used to fail silently). `AgentVisibilityTest` 4 passed. Agent access rules re-checked by the existing `CriticalRouteAuthorizationSweepTest` (76 with add-on suites, passing). **Add-ons page & history (2026-10-06):** Billing shows invoices only. Offer prices and tiers moved to the Plans page (`ModuleOfferAdminCard`, Super Admin). New `/add-ons` page (sidebar "Add-ons"): request queue (admin/agent, scoped by API) and history of every add-on request with requested / approved / paid dates, plus WhatsApp numbers bought (one row per purchase, numbers and term). Migration `2026_10_07_101100` adds `module_addon_requests.decided_at`. `GET /api/module-addons/history` (client, own account) and `GET /api/admin/module-addons/history` (super admin all, agent own clients; `?account_id=` narrows). `ModuleAddonHistoryTest` 3 passed; add-on and route-sweep suites 76 passed; `tsc -b` clean. Client card shows "Already requested on …" and links to the history. **Earlier (2026-10-06):** Units-based group tiers: Custom Contact Groups is priced by the number of groups the client needs. Tiers live in `module_addon_tiers` (migration `2026_10_07_101000`, seeded 1–5 → ₹99, 6+ → ₹199, GST included). `ModuleAddonService::priceFor`/`priceForRequest` price a request by its `units` (no tier → 422 `no_tier`); the approved invoice keeps its price and its description names the group count. The group limit is the paid term's units (`activeUnitLimit`). Super Admin edits tiers via `PUT /api/admin/module-offers/{module}/tiers` (overlaps refused → 422 `overlapping_tiers`; agents refused). Client: `ModuleAddonCard` shows the tier table, asks "How many groups do you need?", and sends `units`. Queue: `ModuleAddonQueueCard` shows units and total. Super Admin: `ModuleOfferAdminCard` has a "Price by units" editor. Backend: `ModuleAddonTierTest`, `ModuleAddonPriceTest`, `ModuleAddonTest` green (92 with the add-on and group-capability suites). Frontend: `npx tsc -b` clean; no browser or Vitest run. **Round 2 (2026-10-05):** notifications carry a `link` (migration `2026_10_07_100800`) and clicking one opens its page (`NotificationBell`). Module add-on: the 5-group limit is ENFORCED while a paid term runs (`POST /api/groups/create` → 422 `group_limit_reached` at 5); a term that ends switches the module off and issues a renewal invoice at the current price (`ModuleAddonService::issueInvoice`, shared with approval). Tests: `ModuleAddonTest` + `ModuleAddonPriceTest` (incl. renewal and group limit) 23+ pass. **NOT done — API Access page:** (a) restyle to the standard page layout; (b) client/agent key creation and rotation UI; (c) server IP **and domain** saved once, later changes only by request with reason (both old and new values), Super Admin modifies directly, agent modifies its own clients' requests, requests shown in notifications and on the page; the server already has a binding + change-request flow for IP only, so a domain field and the agent scope are new. **Previous:** **Paid module add-ons, complete (2026-10-05).** Offers are stored and editable by Super Admin (`module_addon_offers`: label, price incl. GST, term, units included, on sale; `PUT /api/admin/module-offers/{module}`; agents may read only). Current offer: Custom Contact Groups ₹99 incl. GST, 1 month, 5 units included — the owner's wording was "5 group chat ₹99"; **the 5-unit limit is shown, NOT enforced** (owner to confirm what it should limit). Changes show on every screen at once and apply to new requests; issued invoices keep their price. Client: request, status, Pay Invoice on the Billing page (enabled only when a gateway is configured; `POST /api/module-addons/invoices/{id}/pay`). Notifications (in-app, `in_app_notifications`) on invoice ready, not approved, active, and term ended. Config file removed; the table is the single source. Tests: `ModuleAddonTest` 20/20; billing, payment, WhatsApp, module, plan and group suites 616 pass. **Previous:** **Paid module add-on request flow (2026-10-05):** a client requests a paid module (Custom Contact Groups) from the Contact Groups page; a Super Admin, or an agent for its own clients, approves (creates one invoice), rejects, or records the payment (same details and rules as the WhatsApp add-ons); payment, manual or online, switches the module on for one term; an hourly `modules:enforce-addons` switches it off when the term ends unless another paid term is running. Tables: `module_addon_requests`; config `config/module_addons.php`. **PRICE AND TERM (₹99, 1 month) ARE PLACEHOLDERS — the owner must confirm.** Online pay for module invoices goes through the existing gateway confirmation hook but has no Pay button yet. Tests: `ModuleAddonTest` 14/14. **Previous:** **Super Admin native-group sends (2026-10-05):** a Super Admin using the UI (Contact Groups send, Send Alert group send) may send to a native group of a client without the `whatsapp_groups` capability (`superAdminBypass` on `GroupMessageDispatcher` / `GroupDirectMessageDispatcher`, set only from `is_super_admin` in the two UI controllers). API-key and agent sends are unchanged and still refused. Create, list, and import for a Super Admin already bypassed the capability middleware. Note: list and import need the client's WhatsApp session connected (`WHATSAPP_NOT_CONNECTED` otherwise). Tests: `WhatsAppGroupCapabilityTest` 19/19 (incl. the new Super Admin send). **Previous:** **Decisions applied (2026-10-05):** (1) **Online Pay** — `POST /api/whatsapp/addon-invoices/{id}/pay` creates a gateway order for a pending add-on invoice, only when a gateway is fully configured (422 `gateway_not_configured` otherwise); the billing button is disabled while none is configured. Confirmation (verify or webhook) reaches `WhatsAppAddonService::activateOnlinePayment` through `InvoiceCreditService::markPaidAndCreditQuota`. Not tested against a live gateway. (2) **Agent commission** for agent-recorded payments: NOT generated, by decision. (3) **Pending add-on invoices list** — `GET /api/admin/whatsapp/addon-invoices/pending`: Super Admin sees every account; an Agent sees only clients it created (`accounts.agent_id`); others refused. (4) **Starter** priced ₹599 base; 18% GST added on top and shown on the invoice (catalog, seeder, and migration `2026_10_07_100500` for an existing ₹499 row). Add-on ₹99 stays GST-inclusive with no tax line (earlier decision). Tests: `WhatsAppAddonTest` 29/29; plan/billing/checkout/WhatsApp groups pass. **Previous:** **Manual payments for add-on invoices (2026-10-05).** `manual_payments` (amount, method cash|bank_transfer|upi|cheque|other, transaction_id UNIQUE, paid_on, term_starts_on, recorded_by, recorded_by_role). Who may record: Super Admin for any account (incl. an agent's client when the agent does not respond); an Agent only for a client it created (`accounts.agent_id` = the agent's account); a self-signup account (no agent) only by a Super Admin. Checks: amount = invoice total exactly; transaction ID required and unique; method known; date paid not in the future; term start may move ±30 days from the date paid (default: the date paid); invoice must be pending (no double payment). Route `POST /api/admin/whatsapp/addon-invoices/{id}/record-payment?account_id=` moved OUT of subscription.guard (it checked the Agent's own subscription and blocked every agent payment). Billing screen: a pending manual invoice shows "Record payment" (Super Admin / Agent, opens a form with every detail) or "Awaiting approval" (others); the old "Pay Invoice" button, which only downloaded the PDF, is now "View invoice" for non-manual invoices. Tests: `WhatsAppAddonTest` 21/21; billing + payment + WhatsApp + guard groups 279 pass. **Not done:** the online gateway is unchanged and still not configured; the plan checkout still adds 18% GST on top of the plan price (needs your decision); agent commission for agent-recorded payments; a list of all pending invoices for an agent across clients (an agent selects a client in the header to see its invoices). **Previous:** **Multi-number WhatsApp — Phase 3 (2026-10-05): paid extra numbers.** Rules as agreed: ₹99 per extra number, GST INCLUDED, invoices show only the total; each extra number has its own one-month term from payment, independent of the plan's expiry (it keeps running after the plan ends, and renewing the plan does not extend it); the included number pauses when the plan is not active; a pause never removes a number; extra numbers can be bought mid-plan (own invoice, never changes the included number or the default). Built: `WhatsAppAddonService` (purchase → one invoice with one line per number, numbers reserved `pending_payment`; cancel unpaid; `recordManualPayment` → paid, numbers `unlinked`, `term_ends_at` = +1 month, `locked_at` set; `enforceTerms` → pause expired add-ons and an inactive included number, with an engine logout), `config/whatsapp_numbers.php` (price 99, term 1 month, max 10 per purchase), `invoice_line_items`, routes `GET /whatsapp/numbers/addon-price`, `POST /whatsapp/numbers/purchase`, `DELETE /whatsapp/addon-invoices/{id}` (permission manage-subscriptions), `POST /admin/whatsapp/addon-invoices/{id}/record-payment` (role super_admin|agent, Agent scoped via requireTargetAccount), `php artisan whatsapp:enforce-terms` (hourly). UI: the card's Add number button is live (purchase form, total, cancel unpaid). Tests: `WhatsAppAddonTest` 14/14; WhatsApp + guard + journey + group suites 192/192. **Not built yet:** an admin/agent screen to record a payment (the endpoint exists, use the API for now); the combined invoice for plan + add-ons at checkout; the existing plan checkout (`PaymentGatewayController::createOrder`) still adds 18% GST on top of the plan price (the agreed rule is GST-inclusive; needs a decision for all plans); commission for agent-recorded add-ons; rollback of the add-on migration not run; Agent scoping of record-payment not tested. **Previous:** **Multi-number WhatsApp — Phase 2 (2026-10-05): the engine and every caller are keyed by NUMBER SLOT (`whatsapp_numbers.id`), not account.** `whatsapp_engine_auth_states` rebuilt keyed by `whatsapp_number_id` (refused while it holds rows; it was empty). Engine: `session_id` = slot id (`account_id` kept for callbacks); start/logout/send/group routes and the Socket.IO connection use the slot; a socket must belong to the account (`/api/internal/whatsapp-numbers/{id}`); a slot whose login is a different number is refused and logged out (`NUMBER_MISMATCH`); backend status webhook records per-slot status and refuses a mismatched number (`number_mismatch`); the default slot mirrors to `whatsapp_sessions` so the status page still works; sends and group calls go through the default slot. Tenant start/logout take `number_id` (default otherwise); `GET /whatsapp/status` returns `number_id`; the connect modal sends it with the live stream. Your number 917718967080 is the included slot for account 1 (added manually, status unlinked — link it once). Tests: WhatsApp + guard + journey + group suites 178/178; engine storage 8/8; engine start/logout smoke test OK. **Not built yet:** the number-list UI on WhatsApp Setup (add/remove/default screen), checkout, invoices, renewal/pause (Phases 3–4). **Previous:** Multi-number WhatsApp — Phase 1 of 4 (2026-10-05, backend data model + rules + API; billing/checkout/engine NOT yet built). Agreed rules: the Starter plan is ₹599 incl. GST and includes one number; each extra number is ₹99 incl. GST, offered at purchase and skippable; the default is chosen before payment only when there is more than one number and then locked until the plan expires; each billing period is one invoice showing plan + add-on lines; cash/manual recording by Super Admin or Agent until a gateway exists; numbers are linked after payment; when the plan expires all numbers are PAUSED, never removed. Built now: `whatsapp_numbers` (unique phone number across ALL accounts, `locked_at`, `is_included`, `is_default`, status `pending_payment|unlinked|linked|paused`), `WhatsAppNumberService` (format, global uniqueness, first number is included+default, lock, only unpaid extras removable, other tenants' ids → 404), tenant routes `GET|POST /api/whatsapp/numbers`, `PUT /numbers/{id}/default`, `DELETE /numbers/{id}`. 10 tests (`WhatsAppNumberTest`); 104 WhatsApp/guard tests pass. **Not built yet:** Phase 2 engine keyed by number + linking extra numbers, checkout UI, Phase 3 invoices/add-on billing/manual payments, Phase 4 renewal job + pause on expiry; existing account 1's number is not yet backfilled into `whatsapp_numbers`. **Previous:** **WhatsApp QR correction (2026-10-05)** — the boot resume and `/paired` listed only accounts with `creds.registered === true`, but Baileys 7 rc14 sets `registered` only for the phone-link flow; QR-paired accounts were never auto-resumed (the earlier "resumes after redeploy" claim was wrong for them). Now "paired" = `registered` OR `creds.me.id` (both backend `/paired` and engine `isPairedCreds()`); a phone login on a disconnected account always starts pairing. Modal: shows "engine not responding" with the URL after 10 s of silence, closes if a phone request finds the account already connected, and stops waiting for a code after 60 s. Tests: Node 8/8, backend `WhatsAppEngineAuthStateTest` 18/18. **Open: the owner's browser showed "Waiting for a QR code" while the engine reported `connected` — `frontend-app/.env.production.local` points `VITE_QR_ENGINE_WS_URL` at `whatsappsaasplatform.onrender.com`, a different host from the local engine; confirm which URL the browser uses.** Earlier: **WhatsApp QR follow-up (2026-10-05, owner report: Disconnect failed and no number shown)** — root cause: migration `2026_10_06_100100` had not been run on the owner's DB, so every credential call returned 500 (the "404" and "timeout" in the owner's log). Fixed: logout now always notifies the backend "disconnected" even if the stored-credential delete fails (retried 3×; the device was already unlinked by `sock.logout()`); auth-state calls have a 15 s timeout; a pre-migration `sessions/<id>/` folder is resumed at boot and imported; logging out also removes that folder. New: the linked number (`connected_phone_number`, from `sock.user.id`) is reported on connect, cleared on disconnect, returned by `GET /api/whatsapp/status` and shown on WhatsApp Setup as "· +<number>". **The number appears only after the next connect**, so an account connected before this change shows none until it reconnects. Tests: 19 backend (`WhatsAppEngineAuthStateTest` +3), 7 Node. **Previous: WhatsApp (QR engine): phone-number login + durable sessions (2026-10-05)** — (1) **Pairing-code login** as a second option beside QR: the tenant enters the full WhatsApp number (country code, no `+`), WhatsApp returns an 8-character code, the user types it under Linked devices → Link with phone number; Baileys `requestPairingCode()` via `sessionManager.js` `requestPairingCode()`, broadcast as `pairing_code` on the existing Socket.IO stream; 45 s timeout → `pairing_code_timeout`; QR stays the default and unchanged. Tenant endpoint `POST /api/whatsapp/start-session` takes an optional `phone_number` (validated, forwarded normalised). Super Admin self-device stays QR-only. (2) **Sessions no longer live on the pod's disk**: credentials are stored encrypted (`APP_KEY`, `encrypted` cast) in `whatsapp_engine_auth_states` via internal routes `/api/internal/whatsapp-auth/{accountId}` (GET/PUT/DELETE) and `/paired`, reached through the existing `internal.secret` gate; `qr-engine-service/src/services/authStore.js` (`QR_SESSION_STORE=backend`, default; `file` = old local folder, dev only). A redeploy or pod recreation resumes every paired account without a re-scan; an existing local `sessions/<id>/` is imported once on first start. Logout deletes the stored rows. Writes are awaited before Baileys proceeds, retried, and ordered per account. **Operational constraint: run exactly ONE qr-engine-service replica with a `Recreate` rollout strategy** (two live sockets for one device get replaced by WhatsApp). 14 backend feature tests (`WhatsAppEngineAuthStateTest`) + 5 Node tests (`qr-engine-service` `npm test`, restart simulation against a fake backend). Frontend `tsc` clean outside the pre-existing test-only packages; the modal has no automated test (vitest deps are not installed in this environment) — manual check needed. Also fixed a guard-test gap: `RecoveryConfigurationTest` now recognises the `EncryptedOrNull` cast (the 2026-10-05 uncommitted social-credential change had broken it). **Not yet run on the real DB; not yet verified against a real WhatsApp account or a real Kubernetes rollout.** Previous: **WhatsApp reliability + resend owner requests (2026-10-05)** — (1) qr-engine-service `resumeAllSessions()` (`sessionManager.js`), fired once on process boot (`server.js`): walks `sessions/<accountId>/` on disk and auto-resumes (`startSession()`, no QR) every account whose `creds.json` shows a COMPLETED pairing (`registered: true`) — a deploy/restart no longer leaves a previously-connected tenant stuck showing disconnected until someone manually clicks Connect; an account the user explicitly disconnected is never touched (`logoutSession()` already deletes its session folder, so there's nothing on disk to resume) — no DB flag needed; a resumed session that turns out logged-out-on-the-phone-side still flows through the existing `connection.update` close handler exactly as a live disconnect does. (2) Message Logs "Resend" action: `POST /api/message-logs/{id}/resend` (`MessageDispatchLogController::resend`, gated by the existing `view-logs`/`module.guard:message_logs` group plus `permission:send-messages`) re-sends a **failed, non-group-aggregate** row's exact stored text/media (`message_body`/`message_preview` + `media_url`) via `DirectMessageDispatcher` — works identically whichever pathway produced the original row (template, Send Alert's no-template option, chatbot, journey, API), writes a brand-new log row for the retry and leaves the original failed row untouched; frontend `MessageLogsPage.tsx` gained a Resend button next to View for `status === 'failed' && recipient_type !== 'group'` rows. No migration, no permission/plan change; not yet covered by automated tests (no `php`/test runner available in this environment this session — manual verification needed before relying on it in production, including confirming INTERNAL_API_SECRET/NODE_ENV are set on the real qr-engine-service deployment so `resumeAllSessions()` actually runs there as intended).** Previous: **Super Admin Global View fix (2026-10-01) — `target.account:social_accounts` added to `social/analytics/*` and `social/organic-posts` insights routes (Global View now uses the Platform account like every other Social route instead of 422); axios layer rewrites the developer-style "select a client … ?account_id=" 422 text to plain wording (status/error_code unchanged); shared `DismissibleAlert` (cross button, reappears on a new message) now wraps ~104 error banners in 55 files; no migration, no permission/plan change; 1 backend + 9 frontend tests.** Previous: **Phase 12 Task 1 (2026-10-01) — multi-instance queue/scheduler/shared-state readiness: dedicated `database_long`/`redis_long` queue connections (retry_after 960s, `QUEUE_LONG_RETRY_AFTER`) used by ProcessKnowledgeDocumentJob, PublishScheduledPostJob, RefreshPostInsightsJob and ReconcilePlanAccountsJob (follows QUEUE_CONNECTION); `onOneServer()` on the 9 singleton scheduled commands (queue drains stay parallel-safe); social media upload routed through configurable disk `SOCIAL_MEDIA_DISK` (default `public`, behavior unchanged); read-only `php artisan ops:check-topology [--multi-instance] [--json]` (config `topology.multi_instance` / `TOPOLOGY_MULTI_INSTANCE`); worker supervision runbook in README §7.1.1; no migration, no QR/provider/plan changes; 37 new backend tests; two existing social tests updated to assert `database_long`.** Previous: **Phase 11 Task 5 (2026-10-01) — generic collection lifecycle on `BillingCollectionService`: derived `open | partially_paid | paid | overdue` plus stored `cancelled` (3 additive columns on `collection_charge_assignments`: `cancelled_at`, `cancelled_by_user_id`, `cancellation_reason`); `cancelAssignment` / `reinstateAssignment` (row-locked, only while no payment exists, refunds out of scope); Education adapter `cancelFee` / `reinstateFee` + `POST /students/{id}/fees/{fee}/cancel|reinstate`; strict 422 for unsupported body fields on the new endpoints; no capability/permission/plan change; 16 backend + 1 frontend tests; cancel/reinstate-vs-payment race scenarios added to the real-MariaDB probe.** Previous: **Phase 11 Task 4 (2026-10-01) — generic Billing & Collections core (`collection_charge_items` / `_assignments` / `_payments`, customer = CRM contact via composite tenant FK, `BillingCollectionService`, exact minor-unit money, row-locked payments, idempotency keys) + Education fees adapter (`EducationFeeService`, `fees` module, Fees tab); new shared capability `billing_collections` required by the `fees` module in addition to `industry_education`; 33 backend + 23 frontend tests; real-MariaDB concurrency probe.** Previous: **Phase 11 Task 3 (2026-10-04) — Education attendance: `education_attendances` (unique account+student+group+date, composite tenant FKs), bulk idempotent sheet save (one transaction, atomic upsert), per-student history with totals; `attendance` module shipped (`view-education` / `manage-education`, no new permission); Attendance + Attendance history tabs; 25 backend + 24 frontend tests.** Previous: **Phase 11 Task 2 (2026-10-03) — Education foundation: students (a profile on a CRM contact), parents/guardians (CRM contacts), classes/batches, membership; first shipped industry module (`education.students` + `education.batches`); `view-education` / `manage-education`; /education page + nav; 29 backend + 33 frontend tests (§8.1 Phase 11 table).** Previous: **Phase 11 Task 1 (2026-10-02) — Industry Modules foundation: code-owned industry registry, `account_industries` pivot, `industry_*` capabilities, `industry_modules` account module, one `IndustryAuthorizer`, `industry.guard` middleware, `/auth/me` `industry_modules`; NO industry feature built; 18 new tests (§8.1 Phase 11 table).** Previous: **Phase 10 CLOSED (Task 7, 2026-10-01) — final audit passed; `lead_crm` gate added to the conversion-value editor; 5 new tests (§8.1 row 8).** Previous: **Phase 10 Task 6 (2026-10-01) — Ads end-to-end hardening: reads contract aligned, CTWA auto-pause fixed, scheduler lock 30 min, converted_at index, frontend read-only gating, 25 new tests (see §8.1 row 7).** Previous: **Phase 10 owner requests (2026-09-30) CLOSED — Super Admin platform account & plan bypass, Ads launcher upgrades**: (1) **Super Admin** — with no client selected, a Super Admin works on its own "Platform (Super Admin)" account (the existing `PlatformCrmAccount` row) on every Social / Ads route (`target.account` falls back to it): connect its own Facebook / Instagram / Ad Account (the platform account may connect every Meta asset), post, launch ads, see the dashboard; with a client selected, the client's **plan (capability) and subscription are no longer enforced for a Super Admin** (SocialTargetGate, `target.account`, EnsureCrmTargetAccount) — suspension and module switches still are; a post a Super Admin scheduled keeps the bypass in the worker (`created_by_user_id`); tenants unchanged. (2) **Ads launcher** — Meta location search (`GET /api/social/ads/locations`, countries / states / cities with Meta keys → `geo_locations` countries / regions / cities); manual placements (Facebook / Instagram feed, stories, reels → publisher_platforms + positions; none = automatic); the ad's button chosen per campaign (per-objective list, CTWA fixed to WhatsApp); budgets in the ad account's own currency (`GET /api/social/ads/account` → currency, status, amount spent, spend cap, balance; daily budget converted with Meta's currency offset; launch refused before any Meta write when the ad account is not runnable or the budget exceeds the spend cap left; currency stored on the campaign → list / dashboard show ₹ instead of $); Instagram identity (`instagram_actor_id`) sent when an Instagram account is connected and the ad may run on Instagram. (3) **Frontend** — launch form rendered in the page (no modal) with errors at the top and under each field (server 422s jump to the right step), ad account panel (INR default), location picker, placements, button selector driving the live preview; Social pages treat a Super Admin with a Platform account as "own account". Migration 133 `2026_09_30_150000_add_currency_to_ad_campaigns`. `SuperAdminPlatformAdsTest` (14) + `LaunchWizardOwnerRequests.test.tsx` (8); 19 existing backend tests updated to the new Super Admin rule, 3 frontend test files to INR / the in-page form, `AdCopywriterMeteredAiTest` to the Platform-account rule · **Phase 10 Task 3 CLOSED — Ads dashboard & attribution reporting**: `GET /api/social/ads/dashboard` (`App\Services\Ads\AdsDashboardService`, `AdsDashboardController`) — stored data only (`ad_campaigns` status, `ad_campaign_daily_metrics` spend/impressions, `ad_attributions` funnel), never a Meta call; 7d / 30d / 90d / custom (≤ 366 days, not in the future); campaign counts by current status, total + daily spend (missing days NULL), funnel Ads → Referrals → Conversations → CRM leads → Journey starts → Conversions (each counted independently), conversion rate, conversion value (NULL unless recorded), cost per lead and ROAS computed only on launcher campaigns with stored spend (ROAS only with real spend AND real value), bounded per-campaign breakdown (200 rows); every unknown value is `{value: null, status: not_fetched|unavailable|not_applicable}`, never 0; 6 fixed aggregate queries regardless of data size (tested). Same gates as the campaign list (launch-meta-ads\|social_ads.view, meta_ads, ads, target.account, Super Admin must select a client; reads survive an expired subscription). Frontend: new `/social/ads/dashboard` page (Ads Dashboard nav item) with period selector, cards, spend trend, funnel, campaign table, empty / unavailable / loading / error states, client-keyed loading (stale answers dropped); **gap fixed**: a `social_ads.view` user can now open `/social/ads` (ProtectedRoute / nav gained any-of permissions) with launch / pause / budget / organic actions hidden per their own permission. No migration (EXPLAIN on MariaDB: existing account-scoped indexes serve every query). `AdsDashboardTest` (13) + `AdsDashboard.test.tsx` (10) · **Phase 10 Task 2 CLOSED — Ads entitlement enforcement & campaign lifecycle hardening**: every Meta Ads launcher route (`/api/social/ads` list / launch / pause / resume / cpl-threshold) now also requires the existing `ads` capability — `capability.guard:ads` (tenant) + `target.account:meta_ads,ads` (Super Admin's selected client: active, `meta_ads`, `ads`, subscription for writes); reads keep the legacy expired-subscription semantics; the `social` capability grants nothing here. Launch lifecycle: local prerequisites (Ad Account, token, connection health, objective, Facebook Page, CTWA number) checked BEFORE any Meta write (no more orphaned campaigns for a missing Page); a `LAUNCHING` row is recorded before the first Meta write and settles to `ACTIVE` / `FAILED` (Meta rejection; created Meta ids kept for review) / `UNCONFIRMED` (no response, 5xx or missing id — never resent, 502 `AD_PROVIDER_OUTCOME_UNKNOWN`); optional `Idempotency-Key` (unique per tenant) replays the stored launch without calling Meta. Pause/resume (manual + the auto-pause rule) go through one guarded transition `MetaAdsService::changeStatus()`: per-campaign cache lock (409 `AD_CAMPAIGN_BUSY`), no Meta call when already in the target state, only ACTIVE↔PAUSED (409 `AD_CAMPAIGN_INVALID_STATE`), status written only after Meta confirms and only from the state read, Meta 100/33 → `UNAVAILABLE` (409 `AD_CAMPAIGN_UNAVAILABLE`), unknown outcome → status unchanged, not retried. `AdCampaign` saving guard: the Ad Account must belong to the campaign's tenant. Stored provider errors are safe summaries (HTTP status + Meta code only). Frontend: `/social/ads` route + nav need the `ads` capability; launch keeps its Idempotency-Key until a definitive answer; unconfirmed outcomes explained; pause/resume only for ACTIVE/PAUSED; stale list responses dropped and the wizard closed on client switch. Migration 132 (not run on the real DB). `AdCampaignLifecycleTest` (34) + `AdsLifecycle.test.tsx` (7) · **Phase 10 Task 1 CLOSED — Ads & Attribution foundation**: new `ad_attributions` (one row per click-to-WhatsApp referral: provider, source type/id, click id (`ctwa_clid`), provider message id, receiving WhatsApp session + customer phone, same-account launcher campaign, CTWA capture + CRM lead, journey session, conversion; unique per (account, provider, message id)); `App\Services\Ads\AdAttributionService` (lifecycle: referral → CRM lead → journey start → conversion via CrmLead status, best-effort, no provider calls, nothing fabricated — conversion value / ROAS stay null); hooks in the Meta CTWA webhook (tenant = owner of the receiving `phone_number_id`), the journey engine (ctwa start, save_lead) and `CrmLead` saved events; read-only `GET /api/social/ads/attribution` + `/summary` behind the Ads gates (launch-meta-ads|social_ads.view, `meta_ads` module, `ads` capability, `SocialTargetGate::denialFor`, Super Admin target required). No capability/plan change (the `ads` capability and its Business-plan / Meta-provider assignment already existed and are editable in Plan Management). Migration 131 (new table, not run on the real DB). `AdAttributionTest` (19) · **Phase 6 Task 1 (scope lock, state only — no code) — 2026-09-30**: Phase 6 CRM baseline recorded in §8.1 ("Phase 6 scope baseline"): implemented scope = Tasks 1–12 + closure; Deals / Tasks deferred (backlog §8.4); Notes out of Phase 6 (owner decision 2026-09-23); configurable pipelines / stages, activity entities and custom fields = owner decision required; **P6-1 and P6-3 are not defined anywhere in the repository → OWNER DECISION REQUIRED** · **Phase 9 Task 6 CLOSED — Social authorization consistency & analytics UX hardening**: (1) every Social controller (Organic Posts, Insights, Analytics, Social Accounts, Ads, Inbox, Comment Rules, Social Leads, Media, AI copy, Reports) now resolves its target with the new `ResolvesTenantAccount::requireTargetAccount()` — no APP_ENV=local first-account fallback, so a Super Admin without a selected client gets 422 on every environment; (2) new `target.account[:module[,capability]]` middleware (`EnsureTargetAccountMiddleware`): a Super Admin acting on a selected client is checked exactly like that client's own users (active; route module; route capability where the route has one; subscription for writes — reads stay allowed) on every pre-Phase-9 Social group and on the Social Accounts / Organic Posts management groups (owner decision: "mirror tenant checks"; tenants unchanged, no capability added to legacy routes); (3) frontend: the Organic Posts panel is remounted per selected client (the previous client's posts stayed visible after a switch — fixed), Social Accounts links / the panel also require the `social` capability, the analytics refresh note is tied to its client. `SocialAuthorizationConsistencyTest` (11) + `SocialAccess.test.tsx` (6) + 3 in `SocialAnalyticsPage.test.tsx`; 1 existing test changed on purpose (`SocialInboxLeadReplyDispatchTest`: a Super Admin reply for a quota-exhausted client now gets the tenant's 403 instead of the dispatcher's 422). No migration · **Phase 9 Task 5 CLOSED — social analytics dashboard & reporting**: `/social/analytics` page + `GET /api/social/analytics/dashboard` and `/top-posts`, read ONLY from the persisted Task 4 snapshots (`Insights\SocialAnalyticsService`, constant number of aggregated SQL queries, never a provider call). Population = published posts by `published_at` in 7d / 30d / 90d / custom (≤ 366 days); failed / cancelled / waiting posts only in a separate status summary. Per metric: sum of reported values, status available / unavailable / not_fetched / no_data (unavailable is never 0); engagement = reactions + comments + shares + saves with the included components listed; average watch time view-weighted; platform breakdown (one publication = one row, no double counting); daily / weekly trend (lifetime results of posts published in each period); top posts by engagement / reach / impressions / video views (only posts that reported the metric). Access = `view-social-analytics` + social_accounts module + `social` capability + SocialTargetGate on the target — **view-only analytics users no longer need manage-social-accounts** (Task 4 limitation 6 closed). Super Admin with no target → 422 on every environment (no local first-account fallback). No migration. `SocialAnalyticsDashboardTest` (22) + `SocialAnalyticsPage.test.tsx` (11) · **Phase 9 Task 4 CLOSED — social publishing analytics & post insights**: new `organic_post_insights` (one latest snapshot per organic post; every metric nullable — NULL = not available, 0 = real zero; `unavailable_metrics` with reasons; refresh bookkeeping + lease). Insights capability `Publishing\Contracts\SocialInsightsProvider`, implemented by `MetaPublisher` on the same Graph transport (Facebook post / video, Instagram image / reel; best-effort insights edge: a missing insights permission or unsupported metric makes metrics unavailable and never marks the connection revoked). `Insights\PostInsightsService`: eligibility (published + provider post id + supported platform), stored-snapshot reads, controlled refresh (5-min explicit throttle, 6-h freshness, back-off 5/30/120 min for rate limit / temporary errors, per-post lease), connection health via `SocialConnectionService` (assertUsable / observe → reconnect_required), deleted post → post_not_found. API: `GET /social/organic-posts/{id}/insights`, `POST …/{id}/insights/refresh`, `GET …/insights/summary` (`view-social-analytics` + social_accounts module + `social` capability + SocialTargetGate on the target + forAccount post lookup). Worker: `social:refresh-insights` (every 30 min) → `RefreshPostInsightsJob` (database:social). Frontend: totals strip + per-post Insights panel in `OrganicPostsPanel`. Migration 130 (new table, not run on the real DB). `SocialPostInsightsTest` (28) + `OrganicInsights.test.tsx` (9) · **Phase 9 Task 3 CLOSED — social publishing foundation & scheduling**: manual ("publish now") and scheduled organic posts share ONE lifecycle in `OrganicPublishService` (statuses scheduled → publishing → published / pending (Instagram video processing) / failed / reconnect_required / cancelled; every change a conditional UPDATE on the expected status + claim token). All Graph / LinkedIn calls moved behind `App\Services\Social\Publishing\Contracts\SocialPublisher` (`MetaPublisher`, `LinkedInPublisher` — still unreachable, no LinkedIn OAuth; `SocialPublisherFactory`). `social:publish-due` (every minute) settles stale claims, fails posts first picked up >24 h late (`missed_schedule`), claims due posts atomically and queues `PublishScheduledPostJob` on database:social; the job re-checks the TARGET account (`SocialTargetGate`, extracted from `SocialAuthController::assertTargetMayConnect`, no Super Admin bypass), the connection's ownership and health, then sends; temporary provider errors are retried with back-off (1/5/15 min, max 3 attempts), rejections fail with a safe message, no answer → `outcome_unknown` (never re-sent automatically), expired/revoked → `reconnect_required`. Per-account idempotency keys (unique index; replay returns the same post, reuse for another post → 409). API: `store` gains `scheduled_at` / `social_account_id` / `idempotency_key`, new `show` / `cancel` / `retry`, `capability.guard:social` on the group. Frontend: publish now / schedule toggle, per-submission idempotency keys, per-platform results, `OrganicPostsPanel` with Cancel / Retry. Migration 129 additive (not run on the real DB). `SocialPublishingTest` (40) + `OrganicScheduling.test.tsx` (9) · **Phase 9 Task 2.2 CLOSED — shared Meta asset webhook tenant routing**: every Meta webhook lookup of a social connection by asset id (Lead Ads Page, Facebook Page comments, Instagram comments) now goes through `App\Services\SocialAuth\WebhookAssetResolver` — exactly one connection holding (provider, asset_type, provider_id) → that tenant; none → dropped as before; more than one → NOT processed (no `->first()`, no tie-break), safe `Log::warning` + platform-level `activity_logs` row (account_id NULL, module "Social Webhook Routing", action `denied`, category `ambiguous_owner`, event reference, owning social_account / account ids) for manual resolution; webhook still 200. Meta's signed payload carries no tenant discriminator (single platform app), so no deterministic routing exists for a shared asset. Schema already tenant-scoped (`social_accounts` unique `(account_id, provider, provider_id)`), no migration. `SocialWebhookTenantRoutingTest` (11, incl. a source guardrail against provider_id lookups outside the resolver). Also (2026-09-30): `tests/TestCase.php` refuses to run the suite with a cached config, APP_ENV ≠ testing, or a database that is not SQLite :memory: / named *test*/*throwaway* — added after a cached `config:cache` made `php artisan test` run `migrate:fresh` on the owner's real `wa_saas_platform` (owner restored the 18 Sep dump into `wa_saas_restore`) · **Phase 9 Task 2.1 CLOSED — Meta connection error write-back completed**: the Lead Ads form-data fetch (`MetaLeadWebhookHandler::fetchLeadFieldData`, now given the owning `SocialAccount`) and the comment auto-replies (`CommentAutomationService` public + private reply, now given the `SocialAccount` instead of a bare token) use `SocialConnectionService` — `assertUsable()` before the call, `observe()` on failure (classification stays in `MetaOAuthProvider::classifyApiFailure`). The connection is the one resolved from the signed webhook entry's Page / Instagram id (no client value). Webhook context: expired/revoked is persisted (health_status, safe status_reason, status_checked_at) and logged with its safe code; the webhook still answers 200 (no 409 — nothing is returned to a user); a comment event records the safe reconnect message and the private reply is not attempted with a dead token; other failures unchanged. `SocialConnectionWriteBackTest` (11); frontend `PaidLauncherReconnect.test.tsx` (6: expired/revoked 409 notice, reconnect link in a new tab, wizard values kept, external / protocol-relative paths rejected). No migration. Phase 9 Task 2 connection-health coverage complete · **Phase 9 Task 2 CLOSED — social connection health & token lifecycle**: `SocialConnectionService` (provider-agnostic, `SocialOAuthProviderInterface` only) decides and persists connection health — scheduled `social:check-connections` (every 15 min, `withoutOverlapping`) claims due `connected` rows with one conditional UPDATE on the new `health_check_attempted_at` (interval `SOCIAL_CONNECTION_CHECK_INTERVAL_MINUTES`, default 60, floor 5; batch `SOCIAL_CONNECTION_CHECK_BATCH_SIZE`, default 100) and queues `CheckSocialConnectionJob` on database:social (new scheduled worker, `$tries = 1`). Transitions: connected→expired (token_expires_at passed — decided locally without a provider call — or provider says expired), connected→revoked (grant/permission removed), manual Check/reconnect → connected; unreachable / rate-limited / 5xx / provider unavailable → no status change (only the attempt timestamp moves, so it is retried once per interval). Results are applied under a row lock only if the row still holds the checked token (a reconnect or disconnect meanwhile wins). Contract gained `classifyApiFailure()` — Meta's 190/463/10/2xx mapping lives only in `MetaOAuthProvider` (checkConnection uses it too). Feature integration: `ProviderRequestFailed` (keeps the previous message, carries only Meta's `error` object) from Ads (`MetaAdsService` launch/pause/resume/insights), Organic (`OrganicPublishService` FB/IG publish, IG video job) and Inbox (`SocialInboxController` threads/messages/send); `assertUsable()` refuses a known-dead connection before any Meta call; an expired/revoked failure is persisted and answered as `SocialConnectionException` → 409 `SOCIAL_CONNECTION_EXPIRED|REVOKED` with a safe message, connection id/type/status and `reconnect_path`; other Meta rejections keep their previous 422. Inbox threads skip dead connections and return `connection_issues`. Manual `POST /social/accounts/{id}/check` now goes through the same service. Frontend: Social Accounts shows Expired / Access revoked with a safe explanation (stored reason or a default) + Reconnect, Connected with "last checked" + Check, quiet re-read on tab focus/visibility (≥ 60 s apart, no polling); Meta Ads launcher + Organic modal show `SocialReconnectNotice` (reconnect link opens Social Accounts in a new tab, the form stays); paid/organic prerequisite checks use `connection_status`; Inbox shows reconnect banners. Migration 128 (additive: `health_check_attempted_at` + index). `SocialConnectionHealthTest` (23), `SocialConnectionHealth.test.tsx` (8) · **P5-B + P5-C CLOSED — remaining Phase 5 authorization gaps**: P5-B: every `/api/v1` route (single-factor `auth.apikey` group incl. `/v1/crm/leads`, and dual-factor `auth.apisecret` `/v1/whatsapp/*`) now carries the existing `module.apikey:developer_api` (after throttle, before idempotency — a refused call is logged and rate-limited, stores no idempotency row); `/account/api-key` carries `module.guard:developer_api`, and `/regenerate` also `subscription.guard`. Profile modal hides the Client API Key section when the module is off. P5-C (owner scope decision: `whatsapp_groups` = Native WhatsApp Groups; `internal_segment` contact lists stay behind `module.guard:contact_groups` + `send-messages` on every provider): `capability.guard:whatsapp_groups` on `groups/available-native`, `groups/import-native`, `groups/{id}/recreate`; `NativeGroupEntitlement` (existing `AccessControlService::canTenant()` + `EntitlementAuditLogger`, 403 `CAPABILITY_NOT_ENTITLED`) for native `groups/create`, `groups/add-contacts` on a native group, `/v1/whatsapp/groups/create` native, and in `GroupMessageDispatcher` / `GroupDirectMessageDispatcher` for a native group before any reservation (existing `group_access_denied` → 403). Super Admin keeps the capability.guard bypass on tenant routes; API keys and dispatchers never bypass. `PublicApiDeveloperModuleTest` (11), `WhatsAppGroupCapabilityTest` (18); 7 existing suites' fixtures updated (developer_api kept on / whatsapp_groups granted so they still test their original gate). No migration · **P5-A CLOSED — Meta Lead Ads quota & dispatch logging** (from the 2026-09-29 Phase 5 + 6 final verification audit): `MetaLeadWebhookHandler::send()` (tenant notice + lead welcome) called `WhatsAppEngineFactory::make()->sendMessage()` directly — no quota, no dispatch log. It now calls `DirectMessageDispatcher::dispatch(…, 'text', …, source: 'meta_lead_ads')` (same path as P5-2): subscription/quota gate, disconnected-QR check, one `MessageQuotaService::consume()` on a confirmed send, `message_dispatch_logs` row on every terminal branch, WAMID kept. Capture/CRM promotion unchanged; lead `*_error` text now carries the dispatcher's message. `meta_lead_ads` + `social_inbox` added to the Message Logs source filter (`MessageDispatchLogController::VALID_SOURCES`) and to the frontend source label maps (the three pages indexed an unknown source and would throw on these rows). `MetaLeadAdsDispatchTest` (9). No migration · **Phase 8 CLOSED · Phase 9 Task 1 CLOSED — provider-agnostic social account foundation** (reuses the Phase 1 Social stack: `social_accounts`, `SocialAccount`, `social_provider_configs`, `SocialOAuthProviderInterface`/`MetaOAuthProvider`/`SocialOAuthProviderFactory`, `SocialAuthController`, `AssetSelectionModal`, `SocialAccountsPage`): the contract now owns every provider decision (`key/label/assetTypes/capabilities`, `isEnabledFor`, `callbackError` incl. cancellation, `exchangeCodeForToken`, `fetchAssets`, `credentialsForAsset`, `checkConnection`, `revoke`); `SocialAuthController` is provider-free (test-enforced). Meta: long-lived user token (`fb_exchange_token`, ~60 days; short-lived kept on failure), per-Page token moved into the driver with no expiry when long-lived, Graph 190/463 → expired, other 190 / 10 / 2xx → revoked, revoke deliberately not done at Meta (per-user revocation would cut every tenant/asset). Security: redirect() no longer returns the nonce (it reaches the SPA only via the popup postMessage), state carries the initiating user and bind() requires that user + account, postMessage `*` fallback only in local/testing, generic callback messages (provider detail logged, never shown), `no-store` popup, SPA accepts messages only from the popup it opened. Authorization: routes add `module.guard:social_accounts` + the EXISTING `social` capability (`capability.guard:social`; no new capability — seeded, in every baseline plan, editable per plan); connect steps also check the TARGET account (active, subscription, module, `social`, provider flag — no Super Admin bypass). New `GET /social/providers`, `POST /social/accounts/{id}/check`; API adds `connection_status` / `status_reason` / `status_checked_at` / `capabilities`, never tokens. Migration 127 (additive: `status_reason`, `status_checked_at`, `connected_by_user_id`, `metadata`). UI: connecting / failed (Try again) / cancelled / popup-closed states, provider-not-configured / not-enabled / not-entitled explained, per-connection expired/revoked + reason + Reconnect, Check, confirmed Disconnect with a disconnecting state · **§23 Frontend actionability / disabled-button audit CLOSED** (frontend only, no backend change): all 241 `disabled` occurrences + disabled-looking styles classified (§8.3 "Frontend actionability audit"). Root cause of both reported buttons [Fact]: `Connect Meta Account` and `Paid Meta Ad Campaign` (and `Organic Post`, `Add Rule`) were disabled ONLY for a Super Admin in "All Clients (Global View)" (`noTenantSelected`), with a hover-only tooltip pointing at a Header switcher that is hidden on small screens; `Connect Meta Account` could also stay disabled forever after the OAuth popup was closed without reporting back. Now: shared `ActionGate` (`ClientPickerModal`, `GateNoticeModal`, `SelectClientNotice`) + `actionGateHooks` (`useClientGate`, `useUpgradePath`, `safeReturnTo`); the buttons are clickable → client picker → original action continues; the Meta Ads launcher checks the prerequisites the backend enforces (connected `meta_ad_account`, its health; `facebook_page` recommended) and explains the missing one with a link to the connect flow (`/social/accounts?returnTo=/social/ads?open=paid`, in-app paths only), which offers "Continue where you left off" after binding and reopens the launcher; popup-closed detection; actionable empty states (Social Accounts, campaigns, all CRM/social no-client states); CRM "Add lead" clickable for no-client (picker) and no-CRM target (explanation + upgrade path); Journey palette entitlement-locked nodes clickable with an inline reason + upgrade path; read-only banner gains "Renew subscription" (Billing) / "contact your administrator". Backend authorization unchanged and authoritative · **Phase 8 Task 11 CLOSED — AI Agent Registry + controlled agent execution**: account-scoped `ai_agents` + immutable `ai_agent_versions` (instructions, model hint, tool allow-list, limit overrides; a new version only when the executable config changes) + `ai_agent_tool_invocations` (exactly-once record of tool calls) — migration 126, additive, composite tenant FK; `/api/ai-agents` (CRUD, enable/disable, versions, tools) authorized by `AiAuthorizer::forRequest('chatbot','manage-chatbot')` on the TARGET account (no Super Admin entitlement bypass) + per-tool grant checks (permission of the granting user, module + capability of the account). Tools: closed, code-owned `ToolRegistry` ∩ `config('ai.agents.tools')` allow-list; `ToolExecutor` re-checks on EVERY call allow-list → version grant → agent ownership/enabled → agent access (`AiAuthorizer`) → tool module/capability/permission → tool rule → JSON-schema arguments; side effect + invocation row in ONE transaction under unique `(account_id, invocation_key)`. Implemented tools: `journey.variable.get` (read one session variable), `crm.lead.find_current`, `crm.lead.capture_current`, `crm.lead.update_status` — CRM tools act ONLY on the current conversation's customer. `AgentExecutor`: bounded provider-neutral JSON protocol (tool | final) — every model call `MeteredAiService::generateStructured` key `…:v{visit}:m{n}`, tool key `…:v{visit}:t{n}`, limits `config('ai.agents')` (model calls 4/≤8, tool calls 3/≤5, 60 s per attempt, output/input/context/result caps), progress persisted per step in `context_data['@agent']` so a retry never re-charges or repeats a tool. Journey `agent` node: optional `registeredAgentId` (own-account agent, checked on save and resolved again at run time in the session's account; version pinned per session in `context_data['@agents']`); without it the Task 7 single call is unchanged. Builder: account-scoped agent selector. No persistent memory, no arbitrary code/HTTP/SQL/shell tools, no per-agent keys · **P6-2 CLOSED — CRM target account & capture authorization**: a CRM write is now authorized against the RESOLVED TARGET account's own state, not only the caller's. `EnsureCrmTargetAccount` (`crm.target`) refuses a non-GET/HEAD/OPTIONS CRM request whose target (an Agent's sub-client via `?account_id=`, a Super Admin's selected client) differs from the caller's own account and is suspended (403 `CLIENT_ACCOUNT_SUSPENDED`) or has no active subscription (403 `SUBSCRIPTION_EXPIRED`), with a P5-8 `denied` audit row (action `crm.target`); reads stay allowed; the platform CRM account is exempt. `CaptureLeadLinker::accountMayUseCrm` additionally requires the capture's own account to be active with an active subscription (fresh query), so Meta Lead Ads / CTWA / Journey captures for such an account stay unpromoted (`not_entitled`, retryable) — the capture source never authorizes. Existing isolation (tenant resolution, module, permission, capability, `findOrFail` by account, composite FKs) pinned by `CrmTargetAccountAuthorizationTest` (21). No migration · **P5-11 CLOSED — Super Admin revocation protection**: `AccountController::grantEntitlement` no longer lets an Agent undo a revocation it did not make — a manual revocation whose author (`revoked_by_user_id`) is a Super Admin, unknown/deleted, or a user of another account is refused for an Agent caller (403 `ENTITLEMENT_REVOKED_BY_SUPER_ADMIN`, row untouched) and recorded as a P5-8 `denied` decision (category `revoked_by_super_admin`); an Agent's own revocations and plan-downgrade revocations stay re-grantable; a Super Admin revoke now also claims an already-revoked row. No migration · **Phase 8 Task 10 CLOSED — Journey `rag` node executable**: `rag` is in `RUNTIME_EXECUTABLE_TYPES` (+ frontend mirror). Config `{knowledgeBaseId (positive id of the journey's OWN knowledge base — checked on save and again at run time), queryVariable, topK (1..`KNOWLEDGE_MAX_RESULTS`, default 3), outputVariable}`. Runtime (journeys worker only — the inbound path hands off exactly like prompt/agent): `JourneyAiNodeRunner` → `AiAuthorizer::forAccount(session account)` → `KnowledgeRetriever::retrieve` (query embedding billed, key `…:v{visit}:q`) → hits cached in `context_data['@rag']` (same guarded write) so a retry re-reads them via the new `KnowledgeRetriever::passages()` without re-embedding → fixed RAG instructions + numbered passages + the question → `MeteredAiService::generateText` (key `…:v{visit}:g`, operation `journey.rag`) → answer in `outputVariable`. No passage → output `''`, no generation (node result `rag_no_context`); unknown/foreign/unindexed knowledge base or empty query → permanent `invalid_configuration`. Builder: account-scoped knowledge-base selector (`GET /api/knowledge-bases`). No migration · **Phase 8 Task 9 CLOSED — Knowledge Base / Retrieval foundation**: account-scoped `knowledge_bases` / `knowledge_documents` / `knowledge_chunks` (migration 125; composite tenant FKs; versioned documents with a live `indexed_version`); provider-neutral contracts `DocumentTextExtractor` (plain text), `TextChunker` (deterministic character windows), `VectorStore` (development `DatabaseVectorStore`: unit float32 vectors on the chunk rows, bounded brute-force cosine scan), `EmbeddingProvider` (new, separate from `AiProvider`; implemented by `OpenAiProvider` and `GeminiProvider`, resolved by `AiManager::embeddingProvider()`, called through `AiService::embed()` and billed through the new `MeteredAiService::embed()` — same ledger, `ai_operations`, idempotency and abandonment as generation); `KnowledgeRetriever` completed (`RetrievalQuery` with explicit target account → `RetrievalResult`/`RetrievedChunk`, implemented by `DatabaseKnowledgeRetriever`); `ProcessKnowledgeDocumentJob` on `database:knowledge` + `knowledge:recover-documents`; minimal `/api/knowledge-bases` API (CRUD, documents, reprocess, search) authorized by `AiAuthorizer::forRequest('chatbot','manage-chatbot')` — no new capability/module/permission (`ai` reused). Journey `rag` stays non-executable · **Phase 8 Task 8 CLOSED — Gemini provider**: `App\Services\Ai\Providers\GeminiProvider` (extends the shared `HttpAiProvider`; Gemini API `generateContent`, key in the `x-goog-api-key` header, native JSON mode for `generateStructured()`), registered in `AiManager` as `gemini`; `config('ai.providers.gemini')` (`GEMINI_API_KEY` — platform env only, `AI_GEMINI_MODEL`, `GEMINI_BASE_URL`, `GEMINI_API_VERSION`), `gemini` added to the default `AI_ENABLED_PROVIDERS`. Usage normalized inside the provider (thinking tokens counted as output; unreported counts stay null → existing missing-usage rule). No consumer, billing, authorization, plan or schema change: Journey AI nodes and the Ad Copywriter use Gemini purely by configuration (`AI_PROVIDER=gemini`). No migration · **Phase 8 Task 7 CLOSED — Journey AI nodes (prompt / agent; rag stays unavailable)**: `prompt` and `agent` are runtime-executable through `WhatsAppJourneyEngine` → `JourneyAiNodeRunner` → `AiAuthorizer::forAccount(session account, 'chatbot', 'journey')` → `MeteredAiService` (never a vendor, key or ledger call in Journey code). The session's own account pays. On the immediate (webhook / manual test) path an AI node is handed to the existing scheduler (session `waiting`, due now, `ResumeJourneySessionJob` queued) — no provider call on the request path; the journeys worker runs it with the existing claim/lease/retry/backoff. Reply stored in the node's `outputVariable` (existing `context_data` variables); operation key `journey:f{flow}:s{session}:n{hash(node)}:v{visit}` with the per-node visit counter advanced in the same guarded write as the next checkpoint (retry = same key, never charged twice; loop/new session = new key). Insufficient credits / expired subscription → retried `quota_failure`, then failed; provider failure → hold released, retried `provider_failure`; lost reply after a charge → failed, not re-run. `rag` stays non-executable with an explicit `KnowledgeRetriever` contract (no retrieval backend exists). No migration · **Phase 8 Task 6 CLOSED — Ad Copywriter on metered AI**: `POST /api/social/ai/generate` now runs `AiAuthorizer::forRequest(meta_ads, launch-meta-ads)` (on top of the unchanged `permission:launch-meta-ads` route gate) → `CopywriterService` → `MeteredAiService::generateStructured()`; the direct Gemini/OpenAI/Anthropic HTTP calls and the per-tenant Gemini key resolution are removed. Prompts, variant validation, template fallback and the `{data:{provider, variants}}` contract are unchanged; the target account (tenant / selected client / agent's sub-client) pays; one charge per `Idempotency-Key` (frontend sends one per click; 10-second input-fingerprint window without it). New: `ai` capability + `meta_ads` module + AI credits are required (403/422 instead of free copy). `MeteredAiService` gained an optional `accept` callback (unusable answer = malformed = not charged) · **Phase 8 Task 5 CLOSED — AI usage metering + credit consumption**: `App\Services\Ai\Billing\MeteredAiService` is the one entry point for billable AI (manual and automated alike): claim the operation key → `AiCreditPricing` upper-bound estimate → existing `CreditConsumptionService::reserve()` gated by `CreditEntitlementService` (insufficient → `INSUFFICIENT_CREDITS` 422, provider never called) → `AiService` → consume the actual token-based cost from the hold (rest released) or release it on failure. New `ai_operations` table (migration 124) correlates each operation with its reservation + consumption entry (metadata only) and is the durable retry state for "answer delivered, ledger write failed" (`ai:settle-operations`, every 5 min). Pricing centralized in `config('ai.credits')` (placeholder values — owner decision). Providers untouched. MariaDB probe `ai_credit_concurrency_probe.php` 12/12 ×3 · **Phase 8 Task 4 CLOSED — AI Foundation & Provider Abstraction** (issued by the owner as "Phase 8 Task 1"; the project's Phase 8 Tasks 1–3 are the credit system, so it is recorded here as Task 4): provider contract `App\Services\Ai\Contracts\AiProvider` (text + structured JSON), `OpenAiProvider` / `AnthropicProvider` over shared `HttpAiProvider`, `AiManager` (config-selected, `extend()` seam, singleton), `config/ai.php` (env credentials only, AI off when `AI_PROVIDER` is empty), `AiService` (requires an `AiAuthorization`; no credit, no feature coupling), `AiAuthorizer` (existing predicates only: target account per tenant-isolation rules, account active, current subscription, caller's module + permission, `ai` capability on the target; Super Admin needs a selected client and gets no entitlement bypass; automated paths via `forAccount()`), `AiException` codes, metadata-only `AiOperationLogger` + `AiOperationPerformed` event. Reuses the existing `ai` capability and plan-management path — no new capability/module/permission, no migration — see §5 "AI foundation" · **P5-10 CLOSED — foundation seeder safety**: `Phase1FoundationSeeder` is insert-only for plans (`Plan::firstOrCreate`; bundle `attach()`ed only for a plan it just created). Re-running it no longer resets any existing plan's price/quota/duration/billing model/rate/engine/label/description/`included_credits`/`is_active`, no longer re-attaches a capability an administrator removed, and no longer resets `plan_entitlements.usage_limit`. A missing baseline plan is still created with its bundle. Capability/provider/provider_capabilities catalog rows unchanged (still upserted — code-owned). No migration — see §8.1 P5-10 · **P5-9 CLOSED — blocking sleep / request-path waits removed**: the 4 audited `sleep()`s and the `InboundEventGate` 10 s lease poll are gone from production code (token-scanned by `BlockingWaitRemovalTest`). Group jobs pace consecutive sends with a **delayed continuation slice** (same P5-3 claim hand-over; `config/messaging.php` 3–8 s); payment alerts are **dispatched with the jitter as a queue delay** (a CSV upload is spaced 3–8 s per alert); the Instagram video wait is a **chain of queued checks** (`CompleteInstagramVideoPostJob`, same 10 × 3 s bounds; the post stays `pending` meanwhile); a busy conversation makes the gate return **at once** and `ChatbotEngineService` defers the message to `ProcessDeferredInboundMessageJob` on the database `journeys` queue (was: 10 s wait inside the webhook, then dropped). No migration — see §8.1 P5-9 · **Phase 8 Task 3 CLOSED — Credit Ledger, Reservation & Consumption**: `CreditConsumptionService` is the one spending contract (reserve · partial consume · settle · release · direct spend), account-bound (`tenant_mismatch` / `reservation_not_found`), every movement still a `CreditService` ledger operation under the credit-account lock; direct spend = a reservation created already consumed (a consumption always references a reservation); partial consumption keeps a reservation open until nothing remains; optional product gate (`CreditSpendGate`, AI = `CreditEntitlementService`) evaluated under the lock after the idempotency lookup; caller-set reservation `expires_at` + scheduled `credits:release-expired-reservations`; stable failure codes; verified on MariaDB 10.11.14 and MySQL 8.0.46 — see §5 "Credit spending (Phase 8 Task 3)" · **Phase 8 Task 2 CLOSED — Credit Plans, Entitlements & Limits**: `plans.included_credits` (explicit 0 for every existing plan — owner decision), captured on the invoice at order time, allocated once per paid period (`plan_allocation` ledger type, key `plan-allocation:{subscription}:{invoice}`, period row in `usage_quotas`) inside payment fulfilment; `ai` capability vs credits kept as two checks (`CreditEntitlementService`); plan API/UI edit the allowance; `credits:backfill-plan-allocation` (dry-run, repeat-safe); verified on MariaDB 10.11.14 AND MySQL 8.0.46 — see §5 "Credit plans (Phase 8 Task 2)" · **Phase 8 Task 1 CLOSED — Credit System Foundation**: per-account `credit_accounts` (integer balance/reserved), append-only `credit_ledger_entries`, `credit_reservations` (reserved → consumed \| released), `App\Services\Credits\CreditService` as the only writer (row-locked transaction per operation, DB-enforced idempotency), Super Admin grant/adjust/refund API, tenant read API; proven on MariaDB by `tests/Probes/credit_concurrency_probe.php`; plan integration deferred to Task 2 — see §5 "Credits (Phase 8 Task 1)" · **P5-8 CLOSED** — entitlement audit logging: every allow/deny decision at the entitlement boundaries (capability/module guards incl. API-key siblings, route permission refusals, Agent target-account refusals, Journey save/publish/activate node authorization, cross-tenant journey access, Journey runtime node / runtime-entitlement / send-gate decisions) recorded in the existing `activity_logs` trail via `EntitlementAuditLogger` (module "Entitlement Authorization", action_type `allowed`/`denied`, allow-listed payload, never throws) — see §4 and §8.1 P5-8 · **P5-6 + P5-7 CLOSED** — P5-6: `api`-node credential headers/query values encrypted at rest (`JourneySecrets`, Laravel `Crypt`), masked in every journey response and audit row, kept on update via the mask, refused in URLs · P5-7: backend runtime truth (`JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES`), publish/activate refused for non-executable or malformed graphs (422 `JOURNEY_NOT_PUBLISHABLE`, drafts still save), run-time node re-authorization for every catalog node, first-node permanent failure no longer consumes the message, `default` journeys step aside for specific chatbot rules, deterministic trigger selection, question sessions expire after 24 h (`reply_timeout`) — see §8.1 P5-6 / P5-7 · **P5-5 CLOSED** — payment fulfilment exactly-once, now also serialized per account (`InvoiceCreditService` locks the invoice owner's account row); proven on MariaDB by `tests/Probes/payment_fulfillment_concurrency_probe.php` · **Phase 7 CLOSED** (Task 10 release-readiness audit; 2 cross-task regressions fixed — see §8.1 Phase 7 closure record) · Phase 7 Task 9 — Journey production hardening: one session state machine (`WhatsAppFlowSession::TRANSITIONS`, terminal saves refused), guarded checkpoint, recovery of interrupted immediate runs (run lease + `recoverInterruptedRuns()` in `journeys:resume-due`), manual test serialized and replaces every open session, race-free restore events; MariaDB cross-process + deploy probes (`tests/Probes/`) · Phase 7 Task 8 — one quota/entitlement classification for every Journey send (`JourneySendGate`; quota exhausted/plan expired → retried `quota_failure`, suspended/no subscription → failed `entitlement_blocked`; node capability via `JourneyNodeAuthorizer::runtimeDenialFor`) · Phase 7 Task 7 — Journey observability: durable `journey_execution_events` history, failure categories, retry visibility, read-only `GET /whatsapp/flows/{id}/sessions/{sessionId}`, opt-in `journeys:prune-history` · Phase 7 Task 6 — palette `text`/`image`/`video`/`document`/`audio` nodes executed; one completion/terminal contract (`transition()` compare-and-set: ended sessions are never rewritten) · Phase 7 Task 5 — Journey action execution hardening (`JourneyActionConfig`, checkpoint per node, immediate-path failures retried via the Task 1 model) · Phase 7 Task 4 — Journey condition/branching hardening (`JourneyConditionEvaluator`; `conditional` node executed) · P5-4 — orders fulfilled from plan terms captured on the invoice at checkout · P5-3 — group jobs: atomic claim, failure settlement from recipient rows, bounded slices, stale-batch recovery · P5-2 — Social Inbox lead reply now goes through `DirectMessageDispatcher` (quota + dispatch log) · Phase 7 Task 3 — durable inbound idempotency (`inbound_message_events`) + per-conversation lease · Task 2 Journey versions · P5-1 · Tasks 1–1.6 |
| **Backend test suite** | **Phase 12 T1: 2978 tests, 0 failures — SQLite 18760 assertions / 10 skipped; MariaDB 10.11.14 (throwaway `wa_throwaway_test`) 18815 assertions / 1 skipped; frontend 791 Vitest, tsc and build clean.** Previous: **Phase 11 T5: 2941 tests, 0 failures — SQLite 18433 assertions / 10 skipped; MariaDB 10.11.14 (throwaway `wa_throwaway_test`) 18488 assertions / 1 skipped; real-MariaDB probe 51/51 checks; vitest 791; tsc + build OK.** Previous: **Phase 11 T4 (+integrity fix): 2925 passed (MariaDB: 1 skipped; SQLite: 10 skipped), 0 failures; vitest 790; tsc + build OK. (MariaDB full run needs a DB named `wa_throwaway_*` containing "test", e.g. `wa_throwaway_test`, so the `*_on_real_mariadb` tests can run.)** Previous: **Phase 11 T3: 2885 passed (MariaDB: 1 skipped; SQLite: 9 skipped), 0 failures; vitest 767; tsc + build OK.** Previous: **Phase 11 T2: 2860 passed (MariaDB: 1 skipped; SQLite: 9 skipped), 0 failures; vitest 743; tsc + build OK.** Previous: **Phase 11 T1: 2831 passed (MariaDB: 1 skipped; SQLite: 9 skipped), 0 failures; vitest 710; tsc + build OK.** Phase 10 CLOSED (Task 7): 2808 passed (MariaDB: 1 skipped; SQLite: 9 skipped), 0 failures; vitest 702; tsc + build OK.** Task 6: 2804 / vitest 701. Earlier: 2785 passed + 1 skipped (opt-in live Gemini smoke), 0 failures** (MariaDB 10.11.14, authoritative; 16765 assertions) · 2777 + 9 skipped (SQLite) · MySQL 8.0.46 not re-run since Phase 8 T3 (last: 2075 + 3 pre-existing JSON key-order failures, §8.4) · frontend 681 passed |
| **Migrations** | 157 files (newest = `2026_10_07_101000_add_group_tiers_to_module_addons`: `module_addon_tiers` table, `module_addon_requests.units`, seeded group tiers; applied to the owner's local DB). Earlier count: 148 (148th = `2026_10_07_100300_add_addon_terms_to_whatsapp_numbers_and_create_invoice_line_items`: `whatsapp_numbers.addon_invoice_id` + `term_ends_at`, new `invoice_line_items`; applied to the owner's local DB; rollback not run) · 147 (147th = `2026_10_07_100200_key_engine_auth_states_by_whatsapp_number`: rebuilds `whatsapp_engine_auth_states` keyed by `whatsapp_number_id`; refuses while it holds rows; 146th = `2026_10_07_100100_backfill_included_whatsapp_numbers` (data, reversible)) · 145 (145th = `2026_10_07_100000_create_whatsapp_numbers_table`, additive: WhatsApp number slots, globally unique phone number; applied to the owner's local DB) · 144 (144th = `2026_10_06_100200_add_connected_phone_number_to_whatsapp_sessions_table`, additive nullable `whatsapp_sessions.connected_phone_number`; the linked number shown on WhatsApp Setup) · 143 (143rd = `2026_10_06_100100_create_whatsapp_engine_auth_states_table`, additive new table with an encrypted `value` column and a unique (account, name) index; run on the owner's local `wa_saas_restore` 2026-10-05 after a "table not found" failure; verified up/down via the suite on SQLite) · 142 (142nd = `2026_10_01_110200_add_lifecycle_to_collection_charge_assignments`, Phase 11 T5, adds 3 nullable cancellation columns + 1 FK; sorts after the T4 migration it alters; not run on the real DB; `up()`/`down()`/`up()` verified on a throwaway MariaDB) · 141 (141st = `2026_10_01_110100_register_billing_collections_capability`, 140th = `2026_10_01_110000_create_collections_tables`, Phase 11 T4; not run on the real DB; verified up/down/up on MariaDB) · 139 (139th = `2026_10_04_100000_create_education_attendances_table`, Phase 11 T3; not run on the real DB; verified up/down/up on MariaDB) · 134 (134th = `2026_10_01_100000_add_converted_at_index_to_ad_attributions`, index only; run with the pending ones) · 133 (133rd = `2026_09_30_150000_add_currency_to_ad_campaigns` (owner requests 2026-09-30: nullable `ad_campaigns.currency`; not run on the real DB; verified up/down/up on SQLite and MariaDB); 132nd = `2026_09_30_140000_harden_ad_campaign_lifecycle` (Phase 10 T2: `ad_campaigns.meta_campaign_id` → nullable (global unique kept), + `launch_request_id` (unique with `account_id`, index leads with the key so it never replaces the account FK's index), `last_provider_error`, `last_provider_error_at`; `down()` refuses — before changing anything — while rows without `meta_campaign_id` exist; not run on the real DB; verified up/down/up on SQLite and MariaDB); 131st = `2026_09_30_130000_create_ad_attributions_table` (Phase 10 T1, new table only — not run on the real DB; verified up/down/up on SQLite and MariaDB); 130th = `2026_09_30_120000_create_organic_post_insights_table` (Phase 9 T4, new table only — not run on the real DB; verified up/down/up on SQLite and MariaDB); 129th = `2026_09_30_110000_add_publishing_lifecycle_to_organic_posts` (Phase 9 T3, additive: 12 nullable/defaulted columns on `organic_posts`, a per-account idempotency unique index, 2 indexes, 1 FK — not run on the real DB; verified up/down/up on SQLite and MariaDB); 128th = `2026_09_30_100000_add_health_check_claim_to_social_accounts` (Phase 9 T2, additive: 1 nullable column + index — not run on the real DB); 127th = `2026_09_29_130000_add_connection_lifecycle_to_social_accounts` (Phase 9 T1, additive: 4 nullable columns — not run on the real DB); 126th = `2026_09_29_120000_create_ai_agent_tables` (Phase 8 T11, additive: 3 new tables — not run on the real DB); 125th = `2026_09_29_110000_create_knowledge_base_tables` (Phase 8 T9, additive: 3 new tables — not run on the real DB); 124th = `2026_09_29_100000_create_ai_operations_table` (Phase 8 T5, additive: 1 new table); 123rd = `2026_09_25_120000_add_credit_reservation_expiry` (Phase 8 T3, additive: nullable `credit_reservations.expires_at` + index; CHECK consumed ≤ amount on MySQL/MariaDB); 122nd = `2026_09_25_110000_add_plan_credit_allocation` (Phase 8 T2, additive: `plans.included_credits`, `invoices.plan_included_credits`, 4 columns on `usage_quotas`); 121st = `2026_09_25_100000_create_credit_system_tables` (Phase 8 T1, additive: 3 new tables); 120th = `2026_09_24_170000` journey execution events (Phase 7 T7); 119th = `2026_09_24_160000` invoice plan-terms snapshot (P5-4); 118th = `2026_09_24_150000` group-batch claim columns (P5-3); 117th = `2026_09_24_140000` inbound events + conversation locks; 115th/116th = journey versions + backfill; 114th = `group_dispatch_recipients`; 113th = `qr → journey_automation`; 112th = temporal columns; 111th = platform CRM account) — 111th–127th **not yet run on the real DB** |
| **Next up** | **Owner (group tiers, 2026-10-06):** confirm the ₹99 (1–5) and ₹199 (6+) tier prices, that "5+" means 6 and above, and that a request above the paid units is blocked; then browser-test request → approve → invoice → pay → group limit, and tier editing. **Pending build:** API Access page (standard layout; server IP and domain saved once, later changes by request; awaiting the owner's answer on domain check by DNS lookup vs stored). **Owner (WhatsApp QR deploy, 2026-10-05):** (1) run migration `2026_10_06_100100` with the pending ones; (2) set `BACKEND_API_URL` and the same `INTERNAL_API_SECRET` on the qr-engine-service pod (and `NODE_ENV=production`); keep `QR_SESSION_STORE` unset or `backend`; (3) run **one** replica with a `Recreate` strategy (never two — the second socket for the same device is replaced by WhatsApp); (4) after the first deploy, confirm the log line `AUTH_STATE_IMPORTED` (local sessions moved) or `RESUME_ALL_SESSIONS resuming N paired session(s)`; (5) test pairing-code login and a pod restart with a real account before relying on it; (6) `CACHE_STORE` must not be `array` for the Meta connect flow (already required). Phase 12 Task 2 (not started). Owner (T1 deploy): no migration; set `QUEUE_LONG_RETRY_AFTER` if needed, restart workers on a supervisor, run `php artisan ops:check-topology --multi-instance` before enabling a second instance. Earlier: owner (Phase 11 T5 deploy): run migration `2026_10_01_110200` with the pending ones (no new capability, permission or plan change) · owner (Phase 11 T4 deploy): run migrations `2026_10_01_110000` + `2026_10_01_110100` with the pending ones; Education fees need BOTH `industry_education` AND `billing_collections` (grant via Plan Management plan capabilities or an account entitlement — no plan bundles either) · Phase 11 T5+: next industry or next consumer of the collections core · owner (Phase 11 T3 deploy): run migration `2026_10_04_100000` with the pending ones (no new permission/capability) · Phase 11 T4+: next Education module (`fees` is the remaining planned one) or next industry · owner (Phase 11 T2 deploy): run migrations `2026_10_03_100000` + `2026_10_03_100100` with the pending ones; Education is sold ONLY by adding `industry_education` to a plan (Plan Management) or granting it to an account — no plan bundles it; assign the vertical with `PUT /api/admin/accounts/{id}/industries` · Phase 11 T3+: next Education feature (attendance/fees) or next industry · Phase 11 T2+: first industry module (pick one from `config/industries.php`, flip `available`, add its permission + nav item, anchor on CRM contact/lead) · owner (Phase 11 T1 deploy): run migrations `2026_10_02_100000` + `2026_10_02_100100` with the pending ones; grant `industry_*` only via plans/entitlements · owner (2026-09-30 requests deploy): run migration 133 with the pending ones; make sure the Platform account exists (`php artisan crm:platform-account`, or migration `2026_09_23_130000` on a database with a Super Admin) — without it a Super Admin still has to pick a client; reconnect the Meta account once so the token carries `ads_management` · Phase 10 T3 needs no migration and no deploy step beyond the pending ones · owner (Phase 10 T2 deploy): run migration 132 with the other pending migrations · **owner decision / notice (Phase 10 T2)**: the Ads Launcher now requires the `ads` capability — tenants whose plan does not bundle it (Starter, Growth; only Business does) lose `/social/ads` unless `ads` is granted (and QR-engine accounts cannot be granted `ads` by the provider rules) · owner (Phase 10 T1 deploy): run migration 131 with the other pending migrations · owner (Phase 9 T4 deploy): run migration 130 with 129; impressions / reach / clicks need `read_insights` (Pages) and `instagram_manage_insights` added to the Meta connect scopes + reconnect — until then those metrics show "not available (permission)" (decision pending, §8.3) · owner (Phase 9 T3 deploy): run migration 129 with the other pending migrations (`php artisan migrate`, on the database `.env` points at — currently `wa_saas_restore`); scheduled posts need the existing `schedule:run` cron + the `database:social` worker line already in `routes/console.php` · owner (P5-B/P5-C deploy): accounts whose `allowed_modules` omit `developer_api` lose `/api/v1` and the Client API Key; native WhatsApp group actions need an active `whatsapp_groups` entitlement — run `php artisan entitlements:backfill-plan --dry-run` then without `--dry-run` before deploying · owner (Phase 9 T2 deploy): run migration 128 with the other pending migrations; make sure `schedule:run` runs every minute (it drives `social:check-connections` and the new database:social worker); optionally set `SOCIAL_CONNECTION_CHECK_INTERVAL_MINUTES` / `SOCIAL_CONNECTION_CHECK_BATCH_SIZE` · **Phase 9 Task 3** (not specified; see §8.4 "Phase 9 Task 2 follow-ups") · owner: BEFORE deploying Phase 9 T1 run `php artisan entitlements:backfill-plan --dry-run` (then without `--dry-run`) — the social connection routes now require the `social` capability, and tenants whose plan entitlements were never back-filled would lose access; set `FRONTEND_URL` in every non-local environment (the OAuth popup withholds its result without it) · owner: run migrations 125–127 only with the other pending migrations; review `AI_AGENT_TOOLS` / `AI_AGENT_*` limits; set `AI_EMBEDDING_PROVIDER` · owner: choose the production AI provider/model (`AI_PROVIDER`, `AI_GEMINI_MODEL` default `gemini-3.5-flash` is a placeholder) · owner: set real AI pricing (`AI_TOKENS_PER_CREDIT`, `AI_MIN_CREDITS_PER_OPERATION`, `AI_MISSING_USAGE_CHARGE`) and plan `included_credits` · owner: set real `included_credits` values, then `credits:backfill-plan-allocation --dry-run` · remaining final-audit items P6-1 and P6-3 (P5-11, P6-2 closed), then **Phase 8** (not yet specified). Phase 7 is closed in code; its production rollout (10 pending migrations, sequence in §8.1 Phase 7 closure record) is an owner action and has NOT been performed. Owner decisions open: (1) run migration 120 on the real DB; (2) whether to schedule `journeys:prune-history --force` (dry run by default, not scheduled). Owner decisions pending from Task 1 — see §8.1 "Journey (Phase 7)". CRM Notes are backlog (§8.4). |

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

### 3.4 Modules (18, server-side gated)

`analytics`, `billing`, `chatbot`, `comment_automation`, `contact_groups`, `developer_api`,
`lead_crm`, `message_logs`, `meta_ads`, `notifications`, `reports`, `send_alert`,
`social_accounts`, `social_inbox`, `team_management`, `whatsapp_setup`, and (Phase 11) `industry_modules`.

Module-off means **backend-blocked**, not merely UI-hidden.

---

## 4. Database

**142 migrations**, **80 Eloquent models** (`account_industries` added in Phase 11 T1). Grouped by domain:

| Domain | Key tables |
|---|---|
| Tenancy & RBAC | `accounts`, `users`, `permission_tables`, `activity_logs`, `login_audit_logs`, `system_routes`, `route_categories` |
| Billing | `subscriptions`, `plans`, `plan_entitlements`, `account_entitlements`, `usage_quotas`, `invoices`, `payment_gateway_settings`, `quota_requests` |
| Agent/reseller | `agent_selling_entitlements`, `agent_commission_rules`, `agent_commissions`, `agent_commission_payouts`, `agent_commission_payout_items` |
| Entitlement core | `capabilities`, `providers`, `provider_capabilities` |
| WhatsApp | `whatsapp_sessions`, `whatsapp_engine_auth_states` (2026-10-05: encrypted Baileys credentials for the QR engine, one row per account and key file), `message_templates`, `message_dispatch_logs`, `group_dispatch_recipients` (P5-1), `whatsapp_flows`, `whatsapp_flow_versions` (Phase 7 T2), `whatsapp_flow_sessions`, `inbound_message_events` + `journey_conversation_locks` (Phase 7 T3), `journey_execution_events` (Phase 7 T7, append-only) |
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
- **Knowledge bases** (Phase 8 Task 9, migration 125 docblock). `knowledge_bases` (per account, unique name
  per account; `embedding_provider/model/dimensions` locked by the first indexed batch), `knowledge_documents`
  (per knowledge base; `source_key` unique per knowledge base — caller key or `sha256:<content hash>`; normalized
  `content` kept for re-indexing, hidden from API output; `version` / `indexed_version`; status
  pending → processing → ready | failed with safe `error_code`/`error_message`), `knowledge_chunks` (per
  document version; unique `(document, version, chunk_index)`; `embedding` = packed float32 unit vector, hidden).
  Composite FKs `(knowledge_base_id, account_id)` and `(knowledge_document_id, knowledge_base_id, account_id)`,
  CASCADE — a cross-account row is unstorable; `DELETE FROM accounts` cascades (verified on MariaDB).
- **Social accounts** (Phase 1 table `social_accounts`; Phase 9 Task 1 migration 127 docblock). One row per bound asset
  (unique account+provider+provider_id; `asset_type` facebook_page | instagram | meta_ad_account | linkedin_page |
  youtube_channel), encrypted + hidden `access_token`/`refresh_token`, `token_expires_at`, `health_status` (connected |
  token_expired | reauth_required → API `connection_status` connected | expired | revoked), + `status_reason`,
  `status_checked_at`, `connected_by_user_id`, `metadata` (non-secret). API `/api/social` (permission
  manage-social-accounts + module social_accounts + capability social): `GET providers`, `GET accounts`, `POST
  accounts/bind`, `POST accounts/{id}/check`, `DELETE accounts/{id}`, `GET oauth/{provider}/redirect`; public `GET
  social/callback/{provider}` (popup page; postMessage types social-oauth-success | -error | -cancelled).
- **AI agents** (Phase 8 Task 11, migration 126 docblock). `ai_agents` (per account, unique name per account,
  `is_enabled`, `current_version_id` — no FK, resolved only via `forAccount`), `ai_agent_versions` (immutable —
  model `updating` throws; `version` unique per agent; `instructions`, `model` hint, `tools` json list of tool ids,
  `settings` json limit overrides, `config_hash`), composite FK `(ai_agent_id, account_id)` → `ai_agents (id,
  account_id)` CASCADE. `ai_agent_tool_invocations` (per account; unique `(account_id, invocation_key)`; `tool`,
  `status` succeeded | error | denied, `arguments_hash` only — never the arguments, normalized `result`, plain
  agent/version/flow/session/actor ids so the audit record outlives a deleted agent; cascades only with its account).
  Named `ai_*` because "agent" already means a reseller account. No credential column anywhere.
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
- **`organic_posts` publishing lifecycle (Phase 9 T3, migration 129).** One row per manual or scheduled
  organic post. Status (string): `scheduled`, `publishing` (claimed; `claim_token` + `claimed_at`,
  `provider_called_at` set right before the provider call), `pending` (Instagram video still processing,
  unchanged meaning), `published`, `failed` (`failure_code`: provider_rejected, provider_transient,
  outcome_unknown, interrupted, missed_schedule, connection_missing, dispatch_failed, or a lowercased
  SocialTargetGate code), `reconnect_required`, `cancelled`. Allowed moves: `OrganicPost::TRANSITIONS`.
  `origin` (manual|scheduled) is informational only. `idempotency_key` unique per `account_id` (NULLs allowed);
  `metadata` holds the request fingerprint, Instagram container id and the Graph error code — never raw
  provider text or tokens. `claim_token` is `$hidden` (never serialized or audited).
- **`organic_post_insights` (Phase 9 T4, migration 130).** One row per organic post (unique
  `organic_post_id`, cascade), `account_id` denormalised for tenant queries, `social_account_id` (nullOnDelete),
  provider / platform / provider_post_id, `state` of the last attempt (not_fetched, ok, partial, post_not_found,
  reconnect_required, rate_limited, provider_error), nine nullable metrics (impressions, reach, reactions,
  comments, shares, saves, clicks, video_views, video_avg_watch_time_ms — NULL = not available / not fetched,
  0 = reported zero), `unavailable_metrics` {metric: not_supported|permission|unsupported_metric|not_reported},
  `metrics_fetched_at` (last success — metrics are kept across later failures), `last_attempted_at`,
  `next_refresh_at`, `attempts`, lease (`claim_token` hidden, `claimed_at`), safe `error_code` / `error_message`,
  `metadata` (Graph error code only).
- **`ad_attributions` (Phase 10 T1, migration 131).** One row per ad-click referral on a tenant's WhatsApp number:
  `account_id` (= owner of the receiving `phone_number_id`), `provider` (`meta`), `channel` (`whatsapp_ctwa`),
  `source_type` (`ad` / `post`), `source_id`, `click_id` (`ctwa_clid`), `referral_message_id` (WAMID),
  `whatsapp_session_id`, `contact_phone`, `ad_campaign_id` (only when exactly one of the SAME account's launcher
  campaigns has that `meta_ad_id`; campaign / ad set ids are read from `ad_campaigns`, not copied), `capture_lead_id`,
  `crm_lead_id`, `flow_session_id`, `referral_received_at` / `lead_linked_at` / `journey_started_at` / `converted_at`,
  `conversion_value` / `conversion_currency` (always NULL today), `metadata` (headline, source_url, media_type).
  unique(account_id, provider, referral_message_id) — per tenant, not global. Every linked row must share the
  account (model guard). All FKs nullOnDelete except account (cascade).
- **`ad_campaigns` lifecycle (Phase 10 T2, migration 132).** `status` ∈ LAUNCHING / ACTIVE / PAUSED / FAILED /
  UNCONFIRMED / UNAVAILABLE (string column, no enum). `meta_campaign_id` nullable (NULL = the launch never got a
  campaign id from Meta), still globally unique. `launch_request_id` = the launch's Idempotency-Key,
  unique(launch_request_id, account_id). `last_provider_error` / `_at` = safe summary only (HTTP status + Meta
  code). Model guard: `social_account_id` must belong to the same account.
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

### AI foundation (Phase 8 Task 4)

No route. Application code calls `AiService::generateText()/generateStructured(AiAuthorization, AiRequest, ?provider)`;
an `AiAuthorization` comes only from `AiAuthorizer`:

| Entry | For | Checks (in route-chain order) |
|---|---|---|
| `forRequest($request, $module, $permission)` | HTTP (after auth:sanctum + tenant.isolation) | target = the `account_id` attribute TenantIsolationMiddleware resolved, then as `forUser` |
| `forUser($user, $targetId, $module, $permission)` | manual | active user → target (tenant: own only; agent: own/sub-client; Super Admin: a selected client is REQUIRED) → account active → subscription current (unexpired; an exhausted message quota does not block — same rule as `CreditEntitlementService`) → module → permission → `ai` capability on the target |
| `forAccount($account, $module, $source)` | automated (no user) | account active → subscription → module → `ai` capability |

Module/permission are the calling feature's (e.g. `chatbot` + `manage-chatbot`); `ai` is always checked on top.
Every decision → `activity_logs` via `EntitlementAuditLogger` (action `ai.authorize`). Errors (`AiException`,
envelope `{success:false,message,error_code}`): `AI_CAPABILITY_UNAVAILABLE` 403, `MODULE_DISABLED` 403,
`AI_PERMISSION_DENIED` 403, `SUBSCRIPTION_EXPIRED` 403, `CLIENT_ACCOUNT_SUSPENDED` 403,
`AI_TARGET_ACCOUNT_REQUIRED` 422, `AI_TARGET_ACCOUNT_FORBIDDEN` 404, `AI_PROVIDER_UNAVAILABLE` 503,
`AI_PROVIDER_NOT_CONFIGURED` 503, `AI_PROVIDER_FAILED` 502, `AI_PROVIDER_TIMEOUT` 504, `AI_MALFORMED_RESPONSE` 502,
`AI_INVALID_REQUEST` 422. Providers: `openai`, `anthropic`, `gemini` (Phase 8 Task 8 — `GeminiProvider`:
`POST {GEMINI_BASE_URL}/{GEMINI_API_VERSION}/models/{model}:generateContent`, `x-goog-api-key` header, system prompt →
`systemInstruction`, structured → `generationConfig.responseMimeType=application/json` + the shared JSON validation;
answer = first candidate's non-thought text parts, none → `AI_MALFORMED_RESPONSE`; usage: input = `promptTokenCount`,
output = `candidatesTokenCount + thoughtsTokenCount` (or `totalTokenCount − promptTokenCount`), unreported → null;
model id validated before it enters the URL path → `AI_INVALID_REQUEST`; vendor 4xx/5xx incl. 401/403/404/429/503 →
`AI_PROVIDER_FAILED` 502, 408/504/cURL 28 → `AI_PROVIDER_TIMEOUT` — the same shared mapping as the other vendors).
Config `config/ai.php`: `AI_PROVIDER` (empty = off), `AI_ENABLED_PROVIDERS`, `AI_TIMEOUT`,
`AI_RETRIES` (connection failures only, no sleep), keys `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` / `GEMINI_API_KEY` (shared with
`config/services.php`), `ANTHROPIC_BASE_URL` without `/v1` (SDK convention). Logs: metadata only (never prompt,
answer, key, header, vendor body); `AiOperationPerformed` carries `operation_id` for metered calls.

**Billing (Phase 8 Task 5).** Features call `MeteredAiService::generateText/generateStructured(AiAuthorization,
AiRequest, ?operationKey, ?provider)` → `MeteredAiResponse {response, operation, creditsCharged, settled}` —
never `AiService` directly (static test). Flow: claim `ai_operations` row (unique `account_id+operation_key`;
running/succeeded/settled key → `AI_OPERATION_DUPLICATE` 409; failed/abandoned key → new attempt) → hold
`AiCreditPricing::estimate()` = ceil((prompt+system bytes + 64 + max_tokens) / tokens_per_credit) via
`CreditConsumptionService::reserve()` (key `ai:{op}:a{n}`, gate `CreditEntitlementService`, no `expires_at`) →
provider → success: charge = max(min, ceil((in+out tokens)/tokens_per_credit)) capped at the hold
(`credits_uncharged` records any excess), row `succeeded`, then `settle()` (existing `consume:{r}` key) → `settled`;
failure: `release` → `failed`. Missing token usage → `ai.credits.missing_usage` (`minimum` default |
`reservation`), `usage_reported=false` + warning. `ai:settle-operations` (every 5 min): settles `succeeded`,
releases holds of `failed`, abandons `running` older than `ai.credits.stale_after_seconds` (900) — release, no
charge. Errors add `INSUFFICIENT_CREDITS` 422 (the credit system's own code), `AI_OPERATION_DUPLICATE` 409,
`AI_BILLING_UNAVAILABLE` 503.

**AI agents (Phase 8 Task 11).** API `/api/ai-agents` (group tenant.isolation + subscription.guard,
`permission:manage-chatbot`, every action `AiAuthorizer::forRequest('chatbot','manage-chatbot')` on the target account;
Super Admin needs `?account_id=` and gets no entitlement bypass): `GET /` (list, ≤200), `GET /tools` (allow-listed tools +
`grantable` for this user/account), `POST /` {name, description?, instructions, model?, tools?[], settings?{max_model_calls,
max_tool_calls}, is_enabled?}, `GET|PUT|DELETE /{id}`, `POST /{id}/enable|disable`, `GET /{id}/versions`. Another account's id
→ 404 `AI_AGENT_NOT_FOUND`; errors `AI_AGENT_*` (`AgentException`). `AgentRegistry`: a version is created only when
instructions/model/tools/settings change (hash); granting a tool requires the acting user's `can(permission)` and the
account's module + capability (`AI_AGENT_TOOL_NOT_PERMITTED` 403 / `AI_AGENT_TOOL_UNAVAILABLE` 422 / `AI_AGENT_UNKNOWN_TOOL`
422); every new version and every enable re-checks the full tool list for the acting user; settings may only lower the
platform limits. Tools (`App\Services\Ai\Agents\Tools`, `AgentTool` contract: name, label, description, JSON-schema
subset, permission, module, capability, sideEffect, authorize, execute): `journey.variable.get` {name} (read-only, the
session's own non-`@` variables), `crm.lead.find_current` {} (read-only), `crm.lead.capture_current` {name?, email?}
(returns the open lead or creates one via `CrmLeadService`, source `journey`), `crm.lead.update_status` {lead_id, status,
not_converted_reason?} (via `CrmLeadService::changeStatus`); the CRM tools need `manage-crm`, `lead_crm`, `crm` and the P6-2
account/subscription rule, and resolve only the Journey session's own phone inside the session's account. `ToolExecutor`
returns `{ok, data}` / `{ok:false, error, message}` to the model; refusals at allow-list/ownership/entitlement are also
P5-8 audit rows (action `agent.tool`, new category `tool_not_allowed`, new field `tool`). Execution (`AgentExecutor`, via the
Journey `agent` node only — no chat/API execution endpoint): system = version instructions + fixed JSON protocol + that
version's tool catalog; user message = node instructions (optional task, `{{ }}` filled) + the input variable + this
execution's own decisions/results; each decision `{"action":"tool",…}` / `{"action":"final","reply"}` from
`MeteredAiService::generateStructured` (operation `agent.step`, `AI_AGENT_MAX_TOKENS` 600, a malformed decision is rejected
by `$accept` → not charged). Journey mapping: agent missing/foreign/disabled/unconfigured → permanent
`invalid_configuration` before any model call; model-call limit → permanent `execution_limit`; attempt timeout → retried
`execution_limit` (progress kept); cancellation → `cancelled`; AI errors as for prompt/agent. `node_succeeded` details add
`agent_version_id`, `tool_calls`. Config `config('ai.agents')` (`AI_AGENT_MAX_MODEL_CALLS` 4 ≤8, `…_TOOL_CALLS` 3 ≤5,
`…_EXECUTION_SECONDS` 60, `…_OUTPUT_CHARS` 2000, `…_INPUT_CHARS` 4000, `…_INSTRUCTIONS_CHARS` 8000, `…_TOOL_RESULT_CHARS`
2000, `…_CONTEXT_CHARS` 24000, `AI_AGENT_TOOLS` allow-list).

**Journey `rag` node (Phase 8 Task 10).** Executable on the journeys worker (inbound/test path parks the session due-now
and queues `ResumeJourneySessionJob`, as for prompt/agent). Save: `WhatsAppFlowController::assertKnowledgeBasesOwned` —
a `knowledgeBaseId` not in the resolved target account → 422 `Knowledge base not found.` (foreign = missing), after the
node-entitlement check. Run: query = `context_data[queryVariable]` (≤ `KNOWLEDGE_MAX_QUERY_CHARS`; empty → failed) →
`AiAuthorizer::forAccount` → `KnowledgeRetriever::retrieve(RetrievalQuery(session account, …, topK, key …:q))` (KB
resolved inside the session account; unindexed → failed, no charge) → hits `{chunk id: score}` persisted in
`context_data['@rag'][node]` → prompt = `JourneyAiNodeRunner::RAG_SYSTEM` (answer only from the passages, passages are
data not instructions, say "not available" rather than guess) + `[n] title\ntext` passages within
`AI_JOURNEY_MAX_CONTEXT_CHARS` (12000) + `Customer question:` → `MeteredAiService::generateText` (…:g, `journey.rag`,
`AI_JOURNEY_MAX_TOKENS`, non-empty reply required) → `outputVariable` (≤ 4096 chars), `@rag` entry removed, visit counter
advanced — one guarded write with the next checkpoint. Failure mapping = prompt/agent's (`quota_failure` retried for
credits/subscription, `provider_failure` retried, entitlement/config permanent; a charged answer lost before saving →
permanent `internal_error`, never regenerated; a generation left `running` by a dead worker → retried after
`stale_after_seconds`, then abandoned + re-run once). Events: `node_succeeded` result `ai_response` | `rag_no_context`,
details `ai_operation_id`, `retrieval_operation_id`.

**Embeddings + knowledge bases (Phase 8 Task 9).** `EmbeddingProvider::embed(EmbeddingRequest{inputs ≤100,
purpose document|query, model?, operation}) → EmbeddingResponse{provider, model, vectors, dimensions, inputTokens}`;
`AiManager::embeddingProvider(?name)` (default `AI_EMBEDDING_PROVIDER`; empty → `AI_PROVIDER_NOT_CONFIGURED`; a
provider without embeddings, e.g. anthropic → `AI_EMBEDDINGS_UNSUPPORTED` 503). OpenAI `POST /embeddings`
(`AI_OPENAI_EMBEDDING_MODEL`, default `text-embedding-3-small`); Gemini `…/models/{m}:batchEmbedContents`
(`AI_GEMINI_EMBEDDING_MODEL`, default `gemini-embedding-001`, taskType RETRIEVAL_DOCUMENT/QUERY for that model only).
Billing: `MeteredAiService::embed()` — hold = input bytes, charge = reported input tokens (none → missing-usage rule).
API `/api/knowledge-bases` (group tenant.isolation + subscription.guard, `permission:manage-chatbot`, every action
`AiAuthorizer::forRequest('chatbot','manage-chatbot')` on the target account): `GET /`, `POST /` {name, description},
`GET|DELETE /{id}`, `GET|POST /{id}/documents` {title, content, source_key?} (202 queued / 200 unchanged),
`GET|DELETE /{id}/documents/{doc}`, `POST /{id}/documents/{doc}/reprocess` (failed → resume same version; ready → new
version), `POST /{id}/search` {query, limit ≤ `KNOWLEDGE_MAX_RESULTS`, min_score?} + optional `Idempotency-Key`
(one charge per key). Another account's ids → 404. Errors `KNOWLEDGE_*` (`KnowledgeException`). Ingestion job:
operation key `kb:d{doc}:v{version}:b{batch}`, source `knowledge`, operation `knowledge.embed`; search:
`knowledge.query` (embedded with the knowledge base's own provider/model; an unindexed knowledge base → no call, no charge).

**Journey AI nodes (Phase 8 Task 7).** `prompt` and `agent` are in `JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES`
(frontend mirror `RUNTIME_EXECUTABLE_NODE_TYPES`); `rag` is not. Node config (`JourneyActionConfig`, run + publish):
`prompt {prompt*, outputVariable*, model?}`, `agent {instructions*, outputVariable*, inputVariable?, agentId?}`;
variable names `^[A-Za-z0-9_.-]{1,64}$` (what `{{ }}` can read). Execution (`JourneyAiNodeRunner`, resumed path only):
render `{{variables}}` (the only session context sent; no history) → `AiAuthorizer::forAccount(session account,
'chatbot', 'journey')` → `MeteredAiService::generateText(…, key journey:f{flow}:s{session}:n{sha1(node)[0..16]}:v{visit},
accept: non-empty reply)` → reply (trimmed, ≤ `ai.journey.max_output_chars`) stored in `context_data[outputVariable]`
and `context_data['@ai_runs'][node] = visit+1`, written with the next checkpoint / completion. Immediate path:
`waiting` + `wait_until = now` + `ResumeJourneySessionJob` on `database:journeys` (event `session_waiting`, result
`ai_queued`). Failures → `JourneyStepFailed`: capability/module/suspended → `entitlement_blocked` (permanent);
`INSUFFICIENT_CREDITS` / `SUBSCRIPTION_EXPIRED` → `quota_failure` (retried); invalid/empty prompt or input →
`invalid_configuration` (permanent); provider/timeout/malformed/billing → `provider_failure` (retried); duplicate key
still `running` → abandoned via `MeteredAiService::abandonIfStale()` once stale (retry scheduled no earlier —
`JourneyStepFailed::$notBefore`), already charged → `internal_error` (permanent, never re-run). Config
`config('ai.journey')`: `AI_JOURNEY_MAX_TOKENS` (500), `max_input_chars` 4000, `max_output_chars` 4096,
`AI_JOURNEY_ALLOWED_MODELS` (empty = model hint ignored). Node success event: `node_succeeded` result `ai_response`,
details `ai_operation_id`.

### Organic publishing (Phase 9 Task 3)

`/api/social/organic-posts` — `module.guard:social_accounts` + `permission:manage-social-accounts` +
`capability.guard:social`; account = `requireAccount` (Super Admin must pass `?account_id=`); every
lookup `forAccount` (foreign id → 404). `GET /` (latest 50, `?status=`), `GET /{id}`, `POST /` (publish
now, or schedule with `scheduled_at` — future, ≤ `SOCIAL_PUBLISH_MAX_SCHEDULE_DAYS` (90); optional
`social_account_id` (must be the account's own connection for that platform), `idempotency_key` or
`Idempotency-Key` header → 201, or 200 + `replayed: true` for the same request, 409
`IDEMPOTENCY_KEY_REUSED` for a different one), `POST /{id}/cancel` (scheduled / reconnect_required
only, else 409 `POST_NOT_CANCELLABLE`), `POST /{id}/retry` (failed / reconnect_required → scheduled now;
409 `POST_NOT_RETRYABLE`). Create and retry re-check the TARGET account through `SocialTargetGate`
(403 `CLIENT_ACCOUNT_SUSPENDED` / `SUBSCRIPTION_EXPIRED` / `MODULE_DISABLED` / `CAPABILITY_NOT_ENTITLED`) and
the connection (`assertUsable`, 409 `SOCIAL_CONNECTION_EXPIRED|REVOKED`). Scheduler: `social:publish-due`
(every minute) → `PublishScheduledPostJob` (database:social). Config `config/social.php` `publishing`
(`max_attempts` 3, `backoff_minutes` [1,5,15], `stale_after_minutes` 15, `late_grace_hours` 24,
`max_schedule_days` 90, `batch_size` 50). Provider calls: `App\Services\Social\Publishing\*` only.

### Ads attribution (Phase 10 Task 1)

`/api/social/ads/attribution` — `permission:launch-meta-ads|social_ads.view` + `module.guard:meta_ads` +
`capability.guard:ads` + `target.account:meta_ads,ads`; `requireTargetAccount()` (422 without a target on every env);
`SocialTargetGate::denialFor(meta_ads, ads)` on the target (403 CLIENT_ACCOUNT_SUSPENDED / SUBSCRIPTION_EXPIRED /
MODULE_DISABLED / CAPABILITY_NOT_ENTITLED — reads included). The `social` capability does not grant it. `GET /`
(paginated rows, `?source_id=`, per_page ≤ 100), `GET /summary?from&to` (≤ 366 days; per ad: referrals, conversations
(distinct phones), leads, journeys, conversions, conversion_rate = conversions / referrals, conversion_value (null),
spend (launcher campaign daily metrics in the same range, only for ads linked to a launcher campaign), cost_per_lead,
roas (null unless spend and conversion value both exist)). Data: `ad_attributions` (§4); writer
`App\Services\Ads\AdAttributionService` only.

### Ads attribution hardening (Phase 10 Task 4)

Matching rules (all inside ONE account; identifiers of another tenant are never read): journey start → (1) `referral_message_id`
= the inbound event's `wamid:` key, (2) `ctwa_clid` unique among open rows, (3) exactly one unlinked referral for the phone
(+ ad id when named) within 24 h; otherwise **unresolved** (candidates annotated `match.status = ambiguous`). A session is
linked at most once; a linked referral is never re-attached. CTWA webhook lead link requires the CRM lead's `capture_lead_id`
to equal the referral's capture. Conversion: `CrmLead.status = converted` credits the earliest attribution row of that lead
only (no double count); leaving `converted` clears it; `conversion_value` / currency stay NULL. Derived (not stored)
`status` + `match` / `campaign_match` are exposed by `GET /api/social/ads/attribution`. No schema change, no new capability.
Limitations: first-touch credit when a lead has several rows; `metadata.match` is written only by journey linking (referral
rows with no journey carry only `campaign_match`); uniqueness (click id / single candidate) is decided by `limit 2` DB queries, so a row limit can only produce "ambiguous", never an arbitrary match (only the diagnostic annotation is capped at 10 rows); Baileys/QR carries no referral data.

### Meta OAuth scopes (2026-10-01 fix)

Meta answered the connect with `Invalid Scopes: instagram_basic, pages_messaging, instagram_manage_messages`: Meta rejects the whole login when a scope is not granted by a use case on the platform Meta app. `MetaOAuthProvider` now sends the core scopes only (`pages_show_list, pages_read_engagement, ads_read, ads_management, business_management`); `instagram_basic` (`META_OAUTH_INSTAGRAM_SCOPES`) and `pages_messaging` + `instagram_manage_messages` (`META_OAUTH_MESSAGING_SCOPES`) are opt-in via `config/social.php → meta_oauth` (default off) — enable only after adding the matching use case in the Meta Developer Dashboard, then reconnect. `fetchAssets` retries `/me/accounts` without the `instagram_business_account` expansion if Meta refuses it (Pages and Ad Accounts still connect; no Instagram asset until `instagram_basic` is granted). Inbox send/read, private replies and Instagram discovery need those scopes. Not changed (documented): scopes for publishing/insights/lead ads (`pages_manage_posts`, `instagram_content_publish`, `read_insights`, `leads_retrieval`, `pages_manage_metadata`) are not requested; Graph versions in code are v18/v19 (Meta removes v20 on 2026-09-24). Tests: +5 in `SocialAccountFoundationTest` (backend 2813). No migration.

### Sidebar workflow order (2026-10-01)

`NAV_ITEMS` in `AppLayout.tsx` was reordered only (every item line byte-identical, so no visibility rule changed): Dashboard · Analytics · Message Logs · Notifications → Manage/My Clients · Team Users → WhatsApp Setup · Send Alert · Contact Groups · Template Manager · Chatbot Rules · Journey Builder → Developer API → Social Accounts · Inbox · Comment Rules · Social Analytics · Social Reports → Meta Ads Launcher · Ads Dashboard → Instant Lead CRM · CRM Leads · Pipeline · Contacts · Tags · Analytics → Billing & Plans · Plans · Admin/Social Gateway Settings · Device Settings · Route Master · Activity Logs · Audit Logs · Quota Top-Up Requests. Pinned by `SidebarOrder.test.tsx` (vitest 706 total). Frontend only.

### Phase 10 closure (Task 7)

**CLOSED 2026-10-01.** Pending migrations on the real DB (`wa_saas_restore` per `.env`; last verified 110/110 on 2026-09-23 — current state NOT re-checked, nothing was run): the 24 files from `2026_09_23_130000_create_super_admin_platform_crm_account` through `2026_10_01_100000_add_converted_at_index_to_ad_attributions` (migrations 111–134) — run `php artisan migrate --force` after a backup; all additive. **Reconciliation contract (proven in `AdsPhaseClosureTest`):** referrals, leads, journeys, conversions, valued conversions, value and spend are additive — Σ campaign rows + unlinked = account totals. **Conversations are a distinct-phone count per scope**, so one person who messaged through two campaigns counts once at account level and once per campaign (account ≤ Σ campaigns) — by design, not a defect. Account-level CPL / cost per conversion / ROAS use only launcher-linked rows against the spend of those same campaigns (unlinked conversions and value are excluded from ROAS). Limitations carried: `GET /social/ads` returns a tenant's whole campaign list (unpaginated, small by nature); a Meta-deleted campaign is detected only by pause/resume.

### Ads end-to-end hardening (Phase 10 Task 6)

**Authorization matrix (verified by `AdsEndToEndHardeningTest`, every endpoint × scenario):** unauthenticated 401/403; missing `ads` capability (the `social` capability does not help), `meta_ads` module off, suspended account, no Ads permission → 403 and nothing sent to Meta; view-only (`social_ads.view`) reads, never writes; expired subscription → every Ads/attribution/dashboard **read** 200, every **write** 403 `SUBSCRIPTION_EXPIRED`; Super Admin without `?account_id=` → 422 on every endpoint (no first-account fallback), unknown client → 404, switching clients returns exactly the selected client; Agent reaches own account + own sub-clients, a stranger's client → 404; a client user's forged `account_id` never changes its target, foreign campaign / lead / attribution ids → 404 with no state change. **Contract change:** `SocialTargetGate::denialFor()` takes `bool $read`; reads skip only the subscription-expiry check. **End-to-end:** signed CTWA webhook (delivered twice) → one attribution → conversation/CRM lead (`meta_ad`) → Journey start → lead `converted` (set twice) → value PATCH (set twice) → dashboard / attribution summary: referrals, conversions, value, spend, ROAS and `conversion_daily` equal the canonical rows, campaign rows + unlinked = account totals, first-touch timestamp unchanged, a later duplicate webhook neither resets nor doubles the conversion. Mixed currencies are never summed (value and ROAS NULL), a recorded 0 stays 0, unknown stays NULL, no spend → no ROAS; dashboard query count is constant (10 aggregate queries) regardless of campaigns/attributions.
**Auto-pause (`ads:check-performance-rules`, every 15 min, ACTIVE only):** previously judged every campaign by Meta's Lead Ads `lead` actions, so a healthy CLICK_TO_WHATSAPP campaign (Meta reports `messaging_conversation_started`) could trip the zero-conversion pause — treated as a defect. Now `MetaAdsService::fetchInsights` also returns `conversations`; for CLICK_TO_WHATSAPP / MESSAGES results = max(leads, conversations), CPL is computed from those results, and a stored attribution referral for the campaign in the last 24 h (same tenant, `forAccount`) suppresses the zero-conversion pause. Other objectives are unchanged; stored `last_leads` / `last_cpl` / daily metrics keep Meta's lead values; unknown ≠ 0; a failing campaign never stops the batch; one guarded ACTIVE→PAUSED write, never resumes. Scheduler mutex lowered to 30 minutes (a killed run no longer disables the guard for 24 h).
**Frontend:** `canLaunchAds` / `canToggleAds` / `canEditBudget` (MetaAdsPage) and `canEditValues` (AdsDashboardPage) are false when `isReadOnly()` (account not active / subscription not active), so no control the backend would reject is offered; view access stays. Error matrix (401 session-expired text, 403, 404, 422) shown with no stale data. **Limitations:** a campaign deleted at Meta (insights 404) is treated as a failed fetch, not auto-marked UNAVAILABLE (only pause/resume detect it); the CTWA rule's 24 h referral guard keys on stored referrals, so a campaign whose webhook is down relies on Meta's conversations figure.

### Ads reporting & conversion value (Phase 10 Task 5)

**Ownership:** `ad_attributions.conversion_value` / `conversion_currency` on the row that holds the lead's single conversion credit
(earliest referral, first-touch — unchanged). No migration. **Write:** `PATCH /api/crm/leads/{id}/conversion-value` `{value, currency}`
(`value` required key: number incl. 0, or null = unknown/clear; currency 3 letters, required with a value) — CRM group gates
(`crm.target`, `lead_crm`, `manage-crm`, `crm` capability) + `launch-meta-ads|social_ads.view` + `SocialTargetGate::denialFor(meta_ads, ads)`
on the target; Super Admin must pass `?account_id=` (platform fallback not used); 404 for another tenant's lead; 422 `LEAD_NOT_ATTRIBUTED` /
`LEAD_NOT_CONVERTED`; identical update = `changed:false`, no write, no audit row; real changes are audited by `LogsActivity` on `AdAttribution`
(actor, time, old/new). `GET` same path reads it. **Revert:** leaving `converted` clears converted_at, value and currency (audit keeps the old
value); converting again starts unknown. **Reporting** (`GET /api/social/ads/dashboard`, additive): `attribution.cost_per_conversion`,
`valued_conversions`, `conversion_value_currency`, `conversion_value_issue` (`mixed_currency`), `roas_issue` (`currency_mismatch`), per-campaign
`cost_per_conversion` / `valued_conversions` / `currency` / value currency + issue, and `conversion_daily` (per day: conversions, valued,
value — NULL when none valued, counted on the day recorded). Rules: no spend → no CPL / cost per conversion / ROAS; no conversions →
cost per conversion `not_applicable`; no value → value + ROAS NULL; 0 stays 0; values in different currencies are never added; value currency
vs ad-account currency mismatch → no ROAS; launcher campaigns with stored spend only for spend ratios; query count constant. **Optimization:**
`ads:check-performance-rules` audited, unchanged (ACTIVE only, tenant-scoped, lock + guarded ACTIVE→PAUSED, never resumes/relaunches,
failed fetch ≠ 0, CPL needs leads) — no conversion-value optimization (data not yet sufficient). Limitations: the auto-pause lead count is Meta's
insight `lead` actions, not CTWA referrals (**fixed in Phase 10 Task 6** for CLICK_TO_WHATSAPP / MESSAGES); the
auto-pause still runs for a lapsed-entitlement tenant (spend-reducing only, deliberate); legacy rows with a value but no currency report their sum
only while all valued rows are currency-less; the value editor lists the 50 most recent converted rows.

### Ads launcher lifecycle (Phase 10 Task 2)

`/api/social/ads` group — `module.guard:meta_ads` + `capability.guard:ads` + `target.account:meta_ads,ads`, inside
`tenant.isolation` + `subscription.guard`; per route: `GET /` `launch-meta-ads|social_ads.view`; `POST /launch`
`launch-meta-ads|social_ads.launch`; `POST /{id}/pause`, `/{id}/resume` `launch-meta-ads`; `PATCH /{id}/cpl-threshold`
`launch-meta-ads|social_ads.edit_budget`. Every action resolves `requireTargetAccount()` (Super Admin without a client
→ 422) and finds the campaign with `forAccount()` (another tenant's id → 404). Expired subscription: `GET /` allowed,
writes 403 `SUBSCRIPTION_EXPIRED` (tenant via subscription.guard, Super Admin via target.account); the attribution
endpoints keep reads on an expired subscription since Task 6 (they were denied in Task 1). Launch: optional `Idempotency-Key` header (1–100 of
`A-Za-z0-9._:-`); 201 launched; replay → 200 `replayed: true` (ACTIVE/PAUSED/UNAVAILABLE), 409
`AD_LAUNCH_IN_PROGRESS`, 422 `AD_LAUNCH_FAILED`, 502 `AD_PROVIDER_OUTCOME_UNKNOWN`; a Meta rejection keeps the
previous 422 `{message}` shape; expired/revoked connection keeps the 409 reconnect shape. Pause/resume: 200 (`message`
says when it was already in that state), 409 `AD_CAMPAIGN_BUSY` / `AD_CAMPAIGN_INVALID_STATE` /
`AD_CAMPAIGN_UNAVAILABLE`, 502 `AD_PROVIDER_OUTCOME_UNKNOWN` (status unchanged) — all `{success:false, message,
error_code, data: campaign}`. Campaign JSON adds `last_provider_error`, `last_provider_error_at`; `meta_campaign_id`
may be null. Writer: `App\Services\Ads\MetaAdsService` (`launch()`, `changeStatus()`) only;
`ads:check-performance-rules` auto-pauses through `changeStatus()` (no double pause / alert).

### Ads launcher additions (owner requests 2026-09-30)

In the `social/ads` group: `GET /account` (launch-meta-ads\|social_ads.view) — the ad account's `name`, `currency`,
`account_status` (+ label, `runnable`), `amount_spent`, `spend_cap` (null = no cap), `remaining_spend_cap`,
`balance`, in major units (Meta read, nothing changed); `GET /locations?q=` (launch-meta-ads\|social_ads.launch,
q 2–100 chars) — Meta adgeolocation search, `[{key, name, type: country|region|city, country_code, country_name,
region}]`. `POST /launch` also takes `targeting_specs.locations[] {key, type, name}` (countries OR locations
required), `placements[]` (facebook_feed, facebook_stories, facebook_reels, instagram_feed, instagram_stories,
instagram_reels; empty = automatic) and `creative.call_to_action` (per objective, `MetaAdsService::CALL_TO_ACTIONS`).
Launch refuses (422, before any Meta write) when the ad account is not active / in grace period or the daily budget
exceeds the spend cap left; daily budget × currency offset (100, or 1 for zero-decimal currencies). Campaign JSON
and the dashboard carry `currency`.

### Ads dashboard (Phase 10 Task 3)

`GET /api/social/ads/dashboard?range=7d|30d|90d|custom&from&to` — in the `social/ads` group (`module.guard:meta_ads` +
`capability.guard:ads` + `target.account:meta_ads,ads`) with `permission:launch-meta-ads|social_ads.view`;
`requireTargetAccount()` (Super Admin without a client → 422). Reads keep the list's contract (allowed on an expired
subscription). Custom range: both dates, `to` ≥ `from`, `to` ≤ today, ≤ 366 days. Response: `range`,
`campaigns_summary` (current status counts), `spend` {`total`, `impressions` as `{value, status}`,
`campaigns_with_spend`, `daily` [{date, spend|null}]}, `attribution` {ads, referrals, conversations, leads, journeys,
conversions, `conversion_rate`, `conversion_value`, `unlinked_referrals`, `cost_per_lead`, `roas`}, `funnel` (6
stages), `campaigns` (≤ 200, per campaign: spend + `spend_status`, funnel counts, conversion rate, cost per lead,
conversion value, ROAS), `campaigns_truncated`, `notes`. Status values: available / not_fetched / unavailable /
not_applicable. Rules: funnel = referrals received in the range (cohort), each stage counted on its own; conversion =
Task 1 `converted_at`; CPL / ROAS use only launcher-linked referrals of campaigns with stored spend in the range;
ROAS requires spend > 0 AND a recorded value. `App\Services\Ads\AdsDashboardService` (6 aggregate queries, all
`account_id`-filtered). Frontend `/social/ads/dashboard` (`AdsDashboardPage`).

### Organic post insights (Phase 9 Task 4)

`/api/social/organic-posts` — `module.guard:social_accounts` + `permission:view-social-analytics` +
`capability.guard:social`; `requireAccount` (Super Admin needs `?account_id=`), then SocialTargetGate on the
target (403 codes as publishing), post looked up `forAccount` (foreign id → 404). `GET /{id}/insights` (stored
snapshot, never calls Meta; `state` not_applicable + `not_applicable_reason` for failed / cancelled /
outcome_unknown / not_published / missing_provider_post_id / platform_unsupported; `metrics.{key}` =
{value, status available|unavailable|not_fetched, reason}), `POST /{id}/insights/refresh` (422
`INSIGHTS_NOT_AVAILABLE` + reason for ineligible posts; else 200 with `outcome` refreshed | recently_refreshed |
backing_off | in_progress; reconnect_required carries `reconnect_path`), `GET /insights/summary` (per-metric
totals over published posts — null when no post reported the metric — plus the latest 50 posts' snapshots).
Worker `social:refresh-insights` every 30 min (published within 30 days, snapshot missing or due; not
post_not_found). Config `social.insights` (`freshness_minutes` 360, `min_refresh_minutes` 5,
`backoff_minutes` [5,30,120], `max_post_age_days` 30, `lease_seconds` 120, `batch_size` 50).

### Social analytics dashboard (Phase 9 Task 5)

`/api/social/analytics` — `module.guard:social_accounts` + `permission:view-social-analytics` (not the management
permission) + `capability.guard:social`; account via `resolveAccount()` (422 without a target — deliberately not
`requireAccount()`, whose APP_ENV=local first-account fallback would show an unselected account), SocialTargetGate
on the target. `GET /dashboard?range=7d|30d|90d|custom&from&to` → `range`, `summary` (published, per-metric
{value, status available|unavailable|not_fetched|no_data, posts_reporting}, engagement {value, status,
components_included / _unavailable}), `coverage`, `platforms[]`, `freshness` (newest snapshot in range, never-fetched
count), `trend` (day ≤ 31 days, else week; nulls where no post reported), `top_posts` (by engagement),
`status_summary` (non-published posts created in the range), `connections` (Meta Page / Instagram counts, needing
reconnect). `GET /top-posts?metric=engagement|reach|impressions|video_views&limit≤50`. Rules documented in
`SocialAnalyticsService`'s docblock. Frontend `/social/analytics` (nav: view-social-analytics + social_accounts +
social capability); per-post Refresh reuses `POST /social/organic-posts/{id}/insights/refresh`.

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

### WhatsApp QR engine — login options & session storage (2026-10-05)

- `POST /api/whatsapp/start-session` (tenant, unchanged gates) — optional body `phone_number`: digits after normalising (`+`, spaces and dashes removed), 8–15 digits, no leading zero (422 otherwise). Absent = QR flow exactly as before. Forwarded to qr-engine-service as `phone_number`; the response is still the upstream body (HTTP 200).
- qr-engine-service `POST /api/qr/start-session` — optional `phone_number` (`^[1-9][0-9]{7,14}$`, 422 otherwise). Ignored for an already-paired account. A pairing request replaces a QR socket that is still waiting for a scan; a connected session is never touched.
- Socket.IO `connection:update` gained `pairing_code` (8 characters; also in the catch-up payload) and `error: 'pairing_code_timeout'` (no code within 45 s).
- Internal (`internal.secret` only, no user): `GET /api/internal/whatsapp-auth/paired` → `{account_ids}` (creds with `registered: true` only); `GET|PUT|DELETE /api/internal/whatsapp-auth/{accountId}` → `{entries}` / batch `{set, delete}` (≤ 1000 entries, names `[A-Za-z0-9._@+=-]{1,191}`, values ≤ 2 MB) / forget everything. `WhatsAppAuthStateController`; model `WhatsAppEngineAuthState` (hidden + encrypted `value`, not tenant-scoped, listed in `config/recovery.php`).
- Storage contract: the same JSON (`BufferJSON`) and file-name mapping Baileys uses on disk, so a `sessions/<id>/` folder imports unchanged. Credentials are never written to the pod's filesystem in `backend` mode.

## 6. Test Suite

**98 feature test files** + 2 unit files (+ `tests/Probes/`: 7 MariaDB/MySQL-only probe scripts, not PHPUnit — P5-9 added `inbound_gate_nonblocking_probe.php` and `outbound_pacing_probe.php`; Phase 8 T5 added `ai_credit_concurrency_probe.php`; Phase 8 T7 added `journey_ai_concurrency_probe.php`; Phase 8 T9 added `knowledge_concurrency_probe.php`; Phase 8 T10 added `journey_rag_concurrency_probe.php`; Phase 8 T11 added `agent_tool_concurrency_probe.php` — 11 scripts now), run with `php artisan test`.

| | SQLite (secondary) | **MariaDB 10.11.14 (authoritative)** | MySQL 8.0.46 (Phase 8 T2, T3) |
|---|---|---|---|
| Tests | 2698 passed, 9 skipped | **2706 passed, 1 skipped** | 2075 passed (not re-run since Phase 8 T3) |
| Assertions | 15585 | 15629 | 11259 |
| Failures | 0 | **0** | 3 — pre-existing JSON key-order test assumptions, fail identically on the original baseline (§8.4) |

The live Gemini smoke test (`GeminiProviderTest`, Phase 8 T8) is skipped on both engines unless `GEMINI_LIVE_SMOKE=1` + `GEMINI_API_KEY`. The 5 SQLite skips: 4 foreign-key tests SQLite cannot express + P5-5's real-concurrency test (needs MariaDB; it runs the payment probe against `wa_throwaway_probe`). **MariaDB is authoritative —
a green SQLite run does not compensate for a MariaDB failure.**

**Frontend** (`frontend-app`, vitest + Testing Library): **34 test files, 694 tests, 0
failures** (+8 owner requests 2026-09-30 `LaunchWizardOwnerRequests.test.tsx`; +10 Phase 10 T3 `AdsDashboard.test.tsx`; +7 Phase 10 T2 `AdsLifecycle.test.tsx`; +6 Phase 9 T6 `SocialAccess.test.tsx`, +3 in `SocialAnalyticsPage.test.tsx`; +11 Phase 9 T5 `SocialAnalyticsPage.test.tsx`; +9 Phase 9 T4 `OrganicInsights.test.tsx`; +9 Phase 9 T3 `OrganicScheduling.test.tsx`; +10 Phase 9 T1 `SocialAccountLifecycle.test.tsx`; §23 social test mock gained the providers preflight; +16 §23 actionability audit: `SocialActionability.test.tsx` 14, `ExpiryWarningBanner.test.tsx` 2; 4 existing assertions updated to the new clickable behaviour; +3 Phase 8 T11 agent registry/builder; +4 Phase 8 T10 rag registry/builder; +5 Phase 8 T7 registry/builder; +3 Phase 8 T6 `aiService.test.ts`; +3 P5-9 `OrganicPostModal.test.tsx`; +3 Phase 8 T2, +2 P5-8, +6 P5-6/P5-7; previously 549) (+2 Phase 7 Task 4, +2 Task 5) after the manual-source lock (417 before Task 8; +87 Task 8, +19 Task 9; Task 10 none; +1 Task 11; +11 Task 12; +7 Add-lead fix; +2 own-CRM fix; +1 source lock). `npm run build` (tsc -b + vite build)
clean; `npm run lint` (oxlint) 64 warnings / 0 errors (§23: 64 → 63; Phase 9 T3 +1: `OrganicPostsPanel` loads in an effect, the same set-state-in-effect pattern as the existing pages) — none in CRM files.

Running the suite needs `JOURNEY_REGISTRY_PATH` pointed at
`frontend-app/src/journey/nodeRegistry.tsx` (the backend asserts the journey node palette
against the frontend registry).

---

## 7. Standing Engineering Findings

### qr-engine-service must be a single replica, and its sessions must live in the database (2026-10-05)

A Baileys account is one WhatsApp Web device. Two live sockets for the same account fight each other: WhatsApp replaces one with a `connectionReplaced` close, and the loser's reconnect logic then loops. So the service must run as exactly one replica with a `Recreate` rollout (no overlap during a deploy). Credentials are stored in `whatsapp_engine_auth_states`, so a pod restart resumes the session without a QR scan. Two gaps remain: nothing in this repository enforces the replica count (there is no Kubernetes manifest here), and the `file` store is only safe on a developer machine.

### `EncryptedOrNull` is an encrypted cast

`App\Casts\EncryptedOrNull` (social credentials, 2026-10-05) encrypts like `'encrypted'`, but an unreadable value reads as null instead of throwing. `RecoveryConfigurationTest` counts both forms when it checks the recovery inventory, so a new encrypted column must be listed in `config/recovery.php` either way.

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

### The database queue stores `available_at` in whole seconds (P5-9)

`InteractsWithTime::availableAt()` truncates the target time, so `now()->addSeconds(3)`
taken at :10.9 becomes available at :13 — 2.1 s later. Any delay that replaces a `sleep()` and
must not come out shorter uses `App\Support\QueueDelay::after($seconds)` (target rounded up;
real wait in [n, n+1) s). Measured by `tests/Probes/outbound_pacing_probe.php`.

### `queue:work` inside PHPUnit stops after one job once the process passes 128 MB (P5-9)

`queue:work`'s default `--memory=128` is measured on the whole PHPUnit process. Late in a full run
`JourneyRuntimeEntitlementTest::test_one_accounts_revocation_never_affects_another_account` (two
jobs) processed only the first — reproduced on the pre-P5-9 code by running it after a 140 MB
allocation. That call now passes `--memory`. Other in-test `queue:work` calls process one job
and are unaffected today; pass `--memory` in any new multi-job one. Phase 8 T7: the full run now
crosses 128 MB before `JourneyPhase7ReleaseReadinessTest` (measured ≈115 MB live / 126.5 MB real just
before it, +1.1 MB from T7's 42 tests — no leak), whose scheduler tick drains several jobs; its
`queue:work` now passes `--memory` too (test harness only).

### Social target-account resolution (Phase 9 T6)

**Changed by owner decision 2026-09-30:** a Super Admin with no client selected acts on the Platform (Super Admin)
account on every `target.account` route (and CRM, as before); a Super Admin is not held to a selected client's
plan (capability) or subscription anywhere in SocialTargetGate / `target.account` / EnsureCrmTargetAccount —
suspension and module switches still apply; the Platform account itself has no plan or subscription to check.
Not changed: AI (`AiAuthorizer` — subscription + `ai` capability + credits billed to the target) and Journey node /
runtime entitlements.

Social controllers use `requireTargetAccount()` (never the APP_ENV=local first-account fallback of `requireAccount()`),
and every Super-Admin-reachable Social group is guarded on the TARGET: Phase 9 groups by SocialTargetGate in the
controller and/or `target.account:social_accounts,social`; legacy groups by `target.account:<route module>`. A new
Social route must do the same — `SocialAuthorizationConsistencyTest` enumerates the endpoints and scans the
controllers for `$this->requireAccount(`.

### MySQL/MariaDB silently replaces a foreign key's own index (Phase 9 T3)

When a new index can serve an existing foreign key (e.g. `(social_account_id, status)` for the
`social_account_id` FK), MySQL/MariaDB drops the FK's implicitly created index, and the new index
then cannot be dropped ("1553 … needed in a foreign key constraint") — the migration's `down()`
fails half-way (DDL is not transactional). SQLite does not show it. Before adding a composite
index whose leading column carries a FK, verify `down()` on MariaDB (T3 dropped that index).

### Queue connection matters for P5-9 behaviour (deployment prerequisite)

On `QUEUE_CONNECTION=sync` Laravel ignores queue delays: group sends go out back-to-back (no
jitter, no wait), payment alerts have no jitter, and an Instagram video's 10 checks run
immediately (it will usually fail as "timed out"). Deferred inbound messages are unaffected
(explicit `database` connection, `journeys` queue). Production needs `database`/`redis` + a
worker (README §6/§7); the local `.env` ships `sync`. **[Unknown]** which connection production uses.

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
| — Paid add-ons & WhatsApp numbers (2026-10-05/06) | Multi-number WhatsApp (₹99 extra numbers, one-month terms, pause on expiry); manual + online payments; module add-on requests → approval → invoice → payment → term → renewal; Super Admin-editable offers and units tiers (Custom Contact Groups 1–5 ₹99, 6+ ₹199); native WhatsApp Groups as a capability add-on. API Access page **not** done. Frontend `tsc -b` clean; browser verification by owner pending. |

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

#### Phase 6 scope baseline (Phase 6 Task 1, 2026-09-30 — state reconciliation only, no code changed)

Phase 6 (execution numbering; = roadmap §27 "Phase 5 — CRM", see §2) is the CRM delivered by Tasks 1–12,
the two hardening rounds and the 2026-09-23 closure, plus the 2026-09-24 final-audit item P6-2 (closed).
Phase 5 is closed and is not part of this baseline. Classification (evidence: code in `app/Http/Controllers/Api/Crm*`,
`app/Services/Crm/*`, the 15 Phase 6 migrations, `frontend-app/src/pages/crm/`, the `Crm*` feature suites, this file):

| Feature | Classification | Evidence |
|---|---|---|
| Leads (lifecycle new → contacted → converted / not_converted) | IN PHASE 6 — built | Tasks 1, 2, 5; `CrmLead::STATUSES`; `CrmLeadStatusLifecycleTest` |
| Contacts (+ lead ↔ contact linking, contact-group members) | IN PHASE 6 — built | Tasks 1, 3; `CrmContactLeadLinkingTest`, `CrmContactResolutionTest` |
| Tags (lead tags) | IN PHASE 6 — built | Task 7; `crm_tags`, `crm_lead_tags`; `CrmLeadTagTest` |
| Lead sources (manual, whatsapp, meta_ad, api, journey) | IN PHASE 6 — built | Task 2; `CrmLead::SOURCES`; `CrmLeadSourcesIndependenceTest` |
| Assignment / ownership | IN PHASE 6 — built | Task 4; `CrmLeadAssignmentTest` |
| Bulk operations | IN PHASE 6 — built | Task 9; `CrmLeadBulkOperationsTest` |
| CRM activity / history | IN PHASE 6 — built as the Activity Log (`LogsActivity` on CRM models, module "CRM") | Task 12 closure; §8.3 |
| Automated capture (CTWA / Meta Lead Ads, Journey, Developer API) | IN PHASE 6 — built | Tasks 10, 11; `CrmMetaAdsCaptureIntegrationTest`; `Api\V1\CrmLeadController`; P6-2 |
| Pipeline (status-based lead pipeline / Kanban over the lead statuses) | IN PHASE 6 — built | Task 6; `CrmPipelineController`; `CrmPipelineTest` |
| Pipeline stages (fixed = the lead statuses) | IN PHASE 6 — built as fixed statuses | Task 6 |
| Configurable pipelines / pipeline stages (own entities) | OWNER DECISION REQUIRED | Named only in roadmap §27; never in Tasks 1–12; not in backlog §8.4 |
| Deals | DEFERRED TO LATER PHASE | Backlog §8.4 "CRM Deals / Tasks — not started"; §8.2 roadmap Phase 9 blocked on a Deal model; no table |
| Tasks (CRM to-dos) | DEFERRED TO LATER PHASE | Backlog §8.4 (same row); no table |
| Notes | OUT OF PHASE 6 | Owner decision 2026-09-23 (§8.3); backlog §8.4 |
| Activities (logged calls / meetings as an entity) | OWNER DECISION REQUIRED | Not in roadmap §27, Tasks 1–12 or backlog; history is covered by the Activity Log |
| Custom fields | OWNER DECISION REQUIRED | Not in roadmap §27, Tasks 1–12 or backlog; no schema |

Authorization baseline (unchanged): CRM routes run `crm.target` + `module.guard:lead_crm` + `permission:manage-crm` +
`capability.guard:crm` inside `tenant.isolation` + `subscription.guard`; tenant-scoped queries + composite tenant FKs
(`CrmTargetAccountAuthorizationTest`, `CrmEntitlementRbacApiTest`).

**P6-1 / P6-3:** the 2026-09-24 final audit is recorded here only as the range "P6-1…P6-3"; P6-2 is the only item with a
definition. No file in the repository (this file, `Claude outputs/`, code, tests) defines P6-1 or P6-3 →
**OWNER DECISION REQUIRED** (not invented here). Consequently the Phase 6 row in the table above ("closed 2026-09-23")
predates that audit; Phase 6 is fully closed only once the owner defines or waives P6-1 and P6-3.

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
| P5-9 | Blocking waits removed from production paths — **no production `sleep()`/`usleep()` left** (token scan of `app/`). (1) `ProcessGroupDispatchJob` / `ProcessGroupDirectMessageJob`: the 3–8 s anti-ban jitter between consecutive sends is a **delayed continuation slice** (existing P5-3 `continueInNextSlice()`: claim released, frozen list, skip-recorded; a budget slice that already sent is also paced) — one send per slice while pacing is on; on the `sync` connection (no delays exist) the batch continues in-process with no wait. (2) `ProcessPaymentAlertJob`: jitter moved to dispatch — `dispatchPaced()` (single alert: 3–8 s delay; CSV upload: running offset, consecutive alerts 3–8 s apart). (3) `OrganicPublishService`: Instagram video poll → `CompleteInstagramVideoPostJob` chain (10 checks, 3 s apart, `$tries = 1`, linear chain ⇒ `media_publish` at most once; post `pending` until settled; `failed()` never leaves it pending); frontend modal shows `pending` as "Processing". (4) `InboundEventGate`: lease tried **once**; busy → `reason: busy` at once, nothing claimed; `ChatbotEngineService` defers to `ProcessDeferredInboundMessageJob` (explicit `database` connection, `journeys` queue — the scheduled worker already drains it; back-off 1,2,3,4,5 s; 120 attempts ≫ 120 s lease; then logged, as the old drop was). Journey manual test on a busy phone: refused at once (same 422). Delays use `App\Support\QueueDelay` (rounds the target up: the database queue stores `available_at` in whole seconds, which otherwise shortened a delay by up to 1 s — found by the pacing probe). `BlockingWaitRemovalTest` (32; 26 of them fail on the pre-P5-9 code), probes `inbound_gate_nonblocking_probe.php` 12/12 ×3 and `outbound_pacing_probe.php` 8/8 ×3; `journey_concurrency_probe.php` (b) now drains the deferred queue before its unchanged assertion — 8/8 ×3; deploy 14/14, payment 18/18, credit 60/60 | ✅ **CLOSED** | none | **2110** (MariaDB) · 2102 + 8 skipped (SQLite) · frontend 563 |
| P5-10 | Foundation seeder safety. **Root cause:** the plan loop used `Plan::updateOrCreate` (every run rewrote label, description, price, duration, engine, billing model, rate, total_allocated_messages — reverting any Super Admin edit made through `PlanManagementService`) and `syncWithoutDetaching` on `plan_entitlements` (re-attached capabilities an administrator removed and reset existing pivots' `usage_limit` to the seed values). **Fix:** `Plan::firstOrCreate` + `wasRecentlyCreated` guard — an existing plan is skipped entirely; a missing one is created with the defaults, `included_credits` 0, `is_active` default true, and its baseline bundle via `attach()`. Capability/provider/provider_capabilities upserts intentionally unchanged (code-owned catalog; no admin write path; seeded provider corrections such as qr→crm still reach existing installs by re-seed). The seeder calls no other seeder; it never touched modules or permissions (`RolePermissionSeeder` is separate). `FoundationSeederSafetyTest` (7; 6 fail on the old seeder); real `php artisan db:seed --class=Phase1FoundationSeeder` ×4 on throwaway MariaDB: state checksum identical after an admin edit | ✅ **CLOSED** | none | **2117** (MariaDB) · 2109 + 8 skipped (SQLite) |
| P5-11 | Super Admin revocation protection. **Root cause:** a revocation is stored the same way whoever makes it (`revoked_at`, `revoked_by_user_id`, `revoked_reason = manual`), and `grantEntitlement()`'s `updateOrCreate` cleared those columns for ANY permitted caller — an Agent authorized to sell the capability (`agent_selling_entitlements`) silently restored what a Super Admin had revoked from its client. **Fix (no schema change — the author is already on the row):** for an Agent caller, after the existing ownership (`assertCallerCanAccessAccount`) and sell-authorization checks, the client's own row for that capability is read; if it is manually revoked (`isManuallyRevoked()`: anything but `plan_downgrade`, unknown reasons included) and its `revokedBy` user is a Super Admin, missing, or not a user of the caller's Agent account → 403 `ENTITLEMENT_REVOKED_BY_SUPER_ADMIN`, nothing written, P5-8 `EntitlementAuditLogger` `denied` row (`action entitlement.grant`, category `revoked_by_super_admin`, capability, actor/target account ids, error code, 403). Never-revoked, Agent-own and plan-downgrade revocations are unchanged. `revokeEntitlement()` by a Super Admin now also claims an already-revoked row (Agent or plan-downgrade) as a Super Admin manual revocation, so it cannot be undone by the Agent or by plan reconciliation. Super Admin grant/revoke otherwise unchanged. `SuperAdminRevocationProtectionTest` (14; 7 of them fail on the pre-fix controller) | ✅ **CLOSED** | none | **2361 + 1 skipped** (MariaDB) · 2353 + 9 skipped (SQLite) |
| P5-F1/F2/F3 | (independent Phase 5 audit, 2026-10-01 — F-5.1 / F-5.2 / F-5.3, one task) **F-5.1:** `ReconcilePlanAccountsJob` went to the unnamed `default` queue, which no worker drains. **Fix:** named queue `plan-reconciliation` (`ReconcilePlanAccountsJob::QUEUE`), default connection (no hard-coded driver; `sync` still runs inline), `ShouldBeUniqueUntilProcessing` (no duplicate pending run per plan; an edit during a run queues another), `tries 3` / backoff 60,300 s / `timeout 900`, `failed()` logs plan slug only; `routes/console.php` gains `queue:work --queue=plan-reconciliation --stop-when-empty --max-time=55` (every minute, `withoutOverlapping`, skipped under `sync`). **F-5.2:** `LogsActivity` writes nothing without an authenticated HTTP user, so queue / command / payment-webhook entitlement mutations were unaudited. **Fix:** `AccountEntitlement::recordActivity()` override → `EntitlementAuditLogger::recordMutation()` (same `activity_logs` table and `AccountEntitlement` module, exactly one row per mutation, LogsActivity itself untouched); `new_values.audit` = action grant/revoke/restore, capability, previous/new state, actor_type user|system, source, `initiated_by_user_id`; `user_id` is `Auth::id()` only (never a placeholder); `App\Support\EntitlementAuditContext` labels `queue:plan_reconciliation`, `command:entitlements:*`, `payment_fulfilment`, `system:platform_crm_account`, else `http` / `console` / `system`; the write never throws and never alters the mutation. Existing test `EntitlementAuditLoggingTest::test_a_forged_account_id…` now compares against the pre-request count (fixture grants are rows now). **F-5.3:** `JourneySecrets` missed `Ocp-Apim-Subscription-Key`, `x-functions-key`, `code`, map-shaped headers/query and the `api` node `body`. **Fix (same mechanism):** names ending in `key` + `code`/`otp`/`pin` are credentials; list-of-pairs AND name=>value map shapes; `body` parsed as JSON or form-encoded and only credential-named fields (and everything under a credential-named object) encrypted — `{{variable}}` references and other fields untouched, unparseable plain text is NOT inspected; same keep-masked / replace / reject-fake-mask semantics (body mask errors on `data.body`); credentials already stored in an `api` URL are masked on every read/audit (new ones still refused 422). `Idempotency-Key`-style headers are over-matched (encrypted + masked, harmless). | ✅ closed in code, NOT deployed | none | **2736** (+29 new: 8 queue, 10 audit, 11 secrets) |
| P5-A | (2026-09-29 final audit) Meta Lead Ads tenant notice + lead welcome sent through the driver directly (no quota, no dispatch log). **Fix:** `MetaLeadWebhookHandler::send()` → `DirectMessageDispatcher::dispatch()` with `source = meta_lead_ads`; source registered in the Message Logs filter and frontend label maps. `MetaLeadAdsDispatchTest` (9) | ✅ **CLOSED** | none | **2451 + 1 skipped** (MariaDB) · 2443 + 9 skipped (SQLite) · frontend 604 · build clean · lint 63 / 0 |
| P5-B + P5-C | (2026-09-29 final audit) P5-B: `developer_api` module not enforced on `/api/v1` or `/account/api-key`. P5-C: `whatsapp_groups` capability never enforced. **Fix:** existing `module.apikey` / `module.guard` / `subscription.guard` / `capability.guard` on the routes; `NativeGroupEntitlement` (canTenant + audit) where one endpoint serves both group types. `PublicApiDeveloperModuleTest` (11), `WhatsAppGroupCapabilityTest` (18) | ✅ **CLOSED** | none | **2480 + 1 skipped** (MariaDB) · 2472 + 9 skipped (SQLite) · frontend 604 · build clean · lint 63 / 0 |
| P6-1 | **Not defined anywhere in the repository** (only the range "P6-1…P6-3" above) | ⚠️ **OWNER DECISION REQUIRED** (Phase 6 Task 1, 2026-09-30) | — | — |
| P6-3 | **Not defined anywhere in the repository** | ⚠️ **OWNER DECISION REQUIRED** (Phase 6 Task 1, 2026-09-30) | — | — |
| P6-2 | CRM target account & capture authorization. **Root cause:** (A) `subscription.guard` checks the CALLER's own account (`$user->account`) and `crm.target` checked module + capability for a Super Admin only, so an Agent acting on its sub-client (`?account_id=`) or a Super Admin acting on a client could create/update/delete CRM data in a suspended or unsubscribed target account; (B) `CaptureLeadLinker::accountMayUseCrm` checked module + capability but not account status / subscription, so automated capture promoted leads for accounts a manual CRM write could not touch. Target resolution itself was already server-side (TenantIsolationMiddleware; no CRM form request accepts `account_id`; controllers `findOrFail` by the resolved account; composite FKs). **Fix (no schema change):** `EnsureCrmTargetAccount::guardForeignTarget()` — for every caller, a non-safe-method request whose resolved target ≠ caller's own account is refused when the target (fresh read, not `findCached`) is suspended → 403 `CLIENT_ACCOUNT_SUSPENDED`, or `! hasActiveSubscription()` → 403 `SUBSCRIPTION_EXPIRED`; P5-8 `denied` row (`action crm.target`, category `account_suspended` / `no_active_subscription`, actor/target ids). Reads allowed (oversight); platform CRM account exempt (no subscription by design). `accountMayUseCrm` requires active account + active subscription before module + capability (`not_entitled` failure, retry promotes after renewal). `CrmTargetAccountAuthorizationTest` (21: SA/Agent/client targeting, forged `account_id`/`tenant_id`/`agent_id`/lead ids, capability/module/permission, manual + automated capture for 3 sources, no lead on any denial); 4 existing capture fixtures given a subscription | ✅ **CLOSED** | none | **2382 + 1 skipped** (MariaDB) · 2374 + 9 skipped (SQLite) |
| P5-5 | Payment fulfilment exactly-once — same-invoice duplicates were already safe (invoice row lock + `isPaid()`), but two different invoices of one account fulfilled at once rolled one back (entitlement unique-key clash, then an FK-lock deadlock): a paid order stayed pending. Fixed by locking the owner's account row (before the invoice write) and the current subscription. | ✅ **CLOSED** | none | **1958** (MariaDB) · 1953 + 5 skipped (SQLite) · payment probe 18/18 ×3 |

#### AI / Credit (Phase 8) task-by-task

| # | Task | Status | Migration | Suite total after |
|---|---|---|---|---|
| 1 | Credit System Foundation (accounts, immutable ledger, reservations, idempotency, Super Admin ops, RBAC) — `CreditSystemFoundationTest` (23) + `credit_concurrency_probe.php` | ✅ **CLOSED** | `2026_09_25_100000_create_credit_system_tables` (additive; fresh / upgrade-with-data / rollback / re-apply verified on throwaway MariaDB) — **not run on the real DB** | **2034** (MariaDB) · 2027 + 7 skipped (SQLite) · frontend 557 · probes: credit 24/24 (×3 rounds), journey concurrency 8/8, deploy 14/14, payment 6/6 |
| 2 | Credit Plans, Entitlements & Limits — `CreditPlanEntitlementTest` (21) + 5 plan-allocation probe checks | ✅ **CLOSED** | `2026_09_25_110000_add_plan_credit_allocation` (additive; fresh / upgrade-with-data / rollback / re-apply on MariaDB 10.11.14 and MySQL 8.0.46) — **not run on the real DB** | **2055** (MariaDB) · 2048 + 7 skipped (SQLite) · MySQL 8.0.46 2052 + 3 pre-existing · frontend 560 · probes: credit 39/39 ×3 rounds (MariaDB and MySQL 8.0.46) |
| 3 | Credit Ledger, Reservation & Consumption — `CreditConsumptionService`, partial consumption, direct spend, `CreditSpendGate`, failure codes, reservation expiry + cleanup — `CreditConsumptionTest` (23) + 7 spending probe checks | ✅ **CLOSED** | `2026_09_25_120000_add_credit_reservation_expiry` (additive; fresh / upgrade from pre-Phase-8 / upgrade from T2 schema with credit data / rollback / re-apply / no-op re-migrate on MariaDB 10.11.14 and MySQL 8.0.46) — **not run on the real DB** | **2078** (MariaDB) · 2070 + 8 skipped (SQLite) · MySQL 8.0.46 2075 + 3 pre-existing · frontend 560 (unchanged, no frontend change) · probes: credit 60/60 ×3 rounds (MariaDB ×3, MySQL 8.0.46 ×2) |
| 4 | AI Foundation & Provider Abstraction (owner-issued as "Phase 8 Task 1") — `AiFoundationTest` (34) | ✅ **CLOSED** | none | **2151** (MariaDB) · 2143 + 8 skipped (SQLite) · frontend unchanged (563) |
| 5 | AI Usage Metering + Credit Consumption — `AiCreditMeteringTest` (33) + `ai_credit_concurrency_probe.php` (12 checks ×3) | ✅ **CLOSED** | `2026_09_29_100000_create_ai_operations_table` (additive; fresh / rollback / re-apply / no-op re-migrate on throwaway MariaDB 10.11.14) — **not run on the real DB** | **2184** (MariaDB) · 2176 + 8 skipped (SQLite) · frontend unchanged |
| 6 | Migrate the Ad Copywriter to metered AI — `AdCopywriterMeteredAiTest` (21) + `aiService.test.ts` (3) | ✅ **CLOSED** | none | **2205** (MariaDB) · 2197 + 8 skipped (SQLite) · frontend 566 |
| 7 | Journey AI nodes: `prompt` + `agent` executable through `MeteredAiService`; `rag` kept unavailable behind an explicit `KnowledgeRetriever` contract — `JourneyAiNodesTest` (42) + `journey_ai_concurrency_probe.php` (4 checks ×3) + frontend registry/builder (+5) | ✅ **CLOSED** | none | **2245** (MariaDB) · 2237 + 8 skipped (SQLite) · frontend 571 · probes: journey_ai 12/12 ×2 runs, ai_credit 12/12, journey_concurrency 8/8, journey_deploy 14/14, credit 60/60, inbound_gate 12/12 |
| 8 | Gemini provider integration — `GeminiProvider` under the existing abstraction, `AiManager` registration, config/env — `GeminiProviderTest` (42 + 1 opt-in live smoke, skipped by default) | ✅ **CLOSED** | none | **2287 + 1 skipped** (MariaDB) · 2279 + 9 skipped (SQLite) · frontend unchanged (571, no frontend change) |
| 9 | Knowledge Base / Retrieval foundation — schema, ingestion (extract → chunk → metered embed → vector store), `EmbeddingProvider` + OpenAI/Gemini embeddings, `MeteredAiService::embed()`, `KnowledgeRetriever` implementation, minimal API — `KnowledgeBaseTest` (28) + `knowledge_concurrency_probe.php` (5 checks ×3) | ✅ **CLOSED** | `2026_09_29_110000_create_knowledge_base_tables` (additive; fresh / rollback / re-apply / no-op re-migrate / account-delete cascade on throwaway MariaDB 10.11.14) — **not run on the real DB** | **2315 + 1 skipped** (MariaDB) · 2307 + 9 skipped (SQLite) · frontend unchanged (no frontend change) |
| 10 | Journey `rag` node execution — `JourneyAiNodeRunner` rag path (retrieve → cache hits → metered generation), `KnowledgeRetriever::passages()`, save-time knowledge-base ownership, builder selector — `JourneyRagNodeTest` (33) + `journey_rag_concurrency_probe.php` (4 checks ×3) + frontend (+4) | ✅ **CLOSED** | none | **2347 + 1 skipped** (MariaDB) · 2339 + 9 skipped (SQLite) · frontend 575 |
| 11 | AI Agent Registry + controlled agent execution — `ai_agents`/`ai_agent_versions`/`ai_agent_tool_invocations`, `AgentRegistry` + `/api/ai-agents`, `ToolRegistry`/`ToolExecutor`/`ToolSchemaValidator` + 4 tools, bounded `AgentExecutor`, Journey `agent` node `registeredAgentId` (runtime re-resolution, per-session version pin), builder selector — `AiAgentRegistryTest` (14) + `AiAgentExecutionTest` (30) + `agent_tool_concurrency_probe.php` (4 checks ×3) + frontend (+3) | ✅ **CLOSED** | `2026_09_29_120000_create_ai_agent_tables` (additive; not run on the real DB) | **2426 + 1 skipped** (MariaDB) · 2418 + 9 skipped (SQLite) · frontend 578 · probes: agent tool 12/12, ai credit 12/12, journey ai 12/12, knowledge 15/15, journey rag 12/12 |
| 12 | (not specified) | ⏳ not started | — | — |
| §23 | Full frontend actionability / disabled-button audit (final-hardening item) — see §8.3 "Frontend actionability audit" | ✅ **CLOSED** | none | backend unchanged (2426 + 1 skipped MariaDB, last run T11) · frontend 594 · build clean · lint 63 warnings / 0 errors |

#### Social Media (Phase 9) task-by-task

| # | Task | Status | Migration | Suite total after |
|---|---|---|---|---|
| 1 | Provider-agnostic social account foundation — contract extended (availability, cancellation, per-asset credentials, connection check, revoke), Meta driver owns all Meta logic (long-lived token, Page token, 190 mapping), provider-free `SocialAuthController` (+ `providers`, `check`), OAuth CSRF/account-binding hardening, target-account connect checks, existing `social` capability + `social_accounts` module on the routes, lifecycle UI — `SocialAccountFoundationTest` (16) + `SocialAccountLifecycle.test.tsx` (10) | ✅ **CLOSED** | `2026_09_29_130000_add_connection_lifecycle_to_social_accounts` (additive; not run on the real DB) | **2442 + 1 skipped** (MariaDB) · 2434 + 9 skipped (SQLite) · frontend 604 · build clean · lint 63 / 0 |
| 2 | Social connection health & token lifecycle — scheduled `social:check-connections` → `CheckSocialConnectionJob` (database:social) → `SocialConnectionService` → provider contract (`checkConnection`, new `classifyApiFailure`); atomic per-row claim + interval suppression; local expiry without provider calls; expired/revoked from Ads/Organic/Inbox persisted and returned as a safe 409 with the reconnect path; Social Accounts / Ads / Organic / Inbox reconnect UX — `SocialConnectionHealthTest` (23) + `SocialConnectionHealth.test.tsx` (8) | ✅ **CLOSED** | `2026_09_30_100000_add_health_check_claim_to_social_accounts` (additive; not run on the real DB) | **2503 + 1 skipped** (MariaDB) · 2495 + 9 skipped (SQLite) · frontend 612 · build clean · lint 63 / 0 |
| 2.1 | Meta connection error write-back completed — Lead Ads form-data fetch + comment auto-replies through `SocialConnectionService` (assertUsable / observe), webhook-safe (persist + log, still 200); paid launcher 409 frontend tests — `SocialConnectionWriteBackTest` (11) + `PaidLauncherReconnect.test.tsx` (6) | ✅ **CLOSED** | none | **2514 + 1 skipped** (MariaDB) · 2506 + 9 skipped (SQLite) · frontend 618 · build clean · lint 63 / 0 |
| 2.2 | Shared Meta asset webhook tenant routing — `WebhookAssetResolver` (single owner or not processed; ambiguity audited for manual resolution; 200 kept) for Lead Ads + Page/Instagram comments; guardrail test — `SocialWebhookTenantRoutingTest` (11) | ✅ **CLOSED** | none | **2525 + 1 skipped** (MariaDB) · 2517 + 9 skipped (SQLite) · frontend 618 (unchanged) |
| 3 | Social publishing foundation & scheduling — one lifecycle for manual + scheduled organic posts (`OrganicPublishService`, conditional-UPDATE transitions, claim tokens), provider calls behind `SocialPublisher` (`MetaPublisher`, `LinkedInPublisher`), `SocialTargetGate` (target re-check, shared with connect), `social:publish-due` + `PublishScheduledPostJob` (database:social), transient retry with back-off, `outcome_unknown` never auto-resent, `reconnect_required`, missed-schedule grace, stale-claim recovery, per-account idempotency keys, cancel / retry / show endpoints, `capability.guard:social`, schedule UI + `OrganicPostsPanel` — `SocialPublishingTest` (40) + `OrganicScheduling.test.tsx` (9) | ✅ **CLOSED** | 129 (additive) | **2565 + 1 skipped** (MariaDB) · 2557 + 9 skipped (SQLite) · frontend 627 · build clean · lint 64 / 0 |
| 4 | Social publishing analytics & post insights — `organic_post_insights` (latest snapshot per post, nullable metrics, unavailable reasons, lease), `SocialInsightsProvider` implemented by `MetaPublisher` (FB post/video, IG image/reel; permission / unsupported-metric → unavailable, never revoked), `PostInsightsService` (eligibility, stored reads, throttled/backed-off refresh, connection health, post_not_found), insights + refresh + summary endpoints, `social:refresh-insights` + `RefreshPostInsightsJob`, insights UI in `OrganicPostsPanel` — `SocialPostInsightsTest` (28) + `OrganicInsights.test.tsx` (9) | ✅ **CLOSED** | 130 (new table) | **2593 + 1 skipped** (MariaDB) · 2585 + 9 skipped (SQLite) · frontend 636 · build clean · lint 64 / 0 |
| 5 | Social analytics dashboard & reporting — `SocialAnalyticsService` (persisted snapshots only; aggregated SQL, constant query count; available / unavailable / not_fetched / no_data; engagement components; view-weighted watch time; platform breakdown; daily/weekly trend; top posts), `SocialAnalyticsController` (`/api/social/analytics/dashboard`, `/top-posts`; view-social-analytics + module + capability + SocialTargetGate; Super Admin target required on every env), `/social/analytics` page + nav item for view-only analytics users — `SocialAnalyticsDashboardTest` (22) + `SocialAnalyticsPage.test.tsx` (11) | ✅ **CLOSED** | none | **2615 + 1 skipped** (MariaDB) · 2607 + 9 skipped (SQLite) · frontend 647 · build clean · lint 64 / 0 |
| 6 | Social authorization consistency & analytics UX hardening — `requireTargetAccount()` (no local first-account fallback) in every Social controller; `target.account` middleware (Super Admin held to the selected client's own limits: active, module, capability where applicable, subscription for writes) on legacy Social groups + Social Accounts / Organic Posts; Organic Posts panel remounted per client (stale posts after a client switch — fixed); capability-aware Social links; client-scoped analytics refresh note — `SocialAuthorizationConsistencyTest` (11) + `SocialAccess.test.tsx` (6) + 3 frontend | ✅ **CLOSED** | none | **2626 + 1 skipped** (MariaDB) · 2618 + 9 skipped (SQLite) · frontend 656 · build clean · lint 64 / 0 |

#### Ads & Attribution (Phase 10) task-by-task

| # | Task | Status | Migration | Suite total after |
|---|---|---|---|---|
| 8 | **Ads final audit & Phase 10 closure (2026-10-01)** — cross-task audit of Tasks 1–6 (capability / module / permissions / target resolution / subscription semantics / Super Admin / tenant isolation / first-touch / value ownership / dashboard maths / lifecycle / scheduler): consistent, no blocker. All 10 Ads routes + the conversion-value route use `requireTargetAccount` (no `requireAccount` fallback); reads make no Meta call (only `GET /account` and `GET /locations` do, by design); every Ads query is tenant-scoped; no token in URLs or logs (`Http::withToken`). One gap fixed: the conversion-value editor ignored the `lead_crm` module the backend route enforces → now gated (`AdsDashboardPage`). New `AdsPhaseClosureTest` (4: deterministic fixture, campaigns + unlinked = account totals) + 1 vitest; no migration, no backend code change | none | **2808 backend (MariaDB + SQLite)**, 702 vitest |
| 7 | Ads end-to-end hardening & production readiness (2026-10-01) — audit of the whole Ads surface (10 routes + CRM conversion-value route, middleware chain, scheduler, jobs); **contract change:** attribution reads (`/social/ads/attribution`, `/summary`) and `GET …/conversion-value` now keep working on an expired subscription like `GET /social/ads` and the dashboard (`SocialTargetGate::denialFor(..., bool $read)`; suspended / module / capability still deny; writes unchanged); **CTWA auto-pause fixed** (CLICK_TO_WHATSAPP / MESSAGES judged by Meta conversations + 24 h stored-referral guard, not Lead Ads `lead` actions); `ads:check-performance-rules` `withoutOverlapping(30)` (was the 24 h default); index `ad_attributions (account_id, converted_at)`; frontend: Ads write controls hidden for a lapsed / suspended account (`isReadOnly`); +12 `AdsEndToEndHardeningTest`, +6 `AdsOptimizationSafetyTest`, +7 vitest `AdsHardening.test.tsx` | `2026_10_01_100000_add_converted_at_index_to_ad_attributions` (index only; up/down verified on a throwaway MariaDB DB) | **2804 backend (MariaDB + SQLite)**, 701 vitest |
| 6 | Ads reporting, optimization & conversion value foundation (2026-10-01) — conversion value owned by the attribution row that holds the lead's conversion credit (`ad_attributions.conversion_value/currency`, no new column, no second copy on the CRM lead); manual path `GET/PATCH /api/crm/leads/{id}/conversion-value` (CRM gates + Ads permission + Ads entitlement of the explicit target; value optional, 0 valid, NULL unknown, currency required with a value, idempotent, audited via `LogsActivity`, never touches `crm_leads.source`); leaving `converted` clears value + currency; dashboard additive fields: cost per conversion, valued conversions, value currency / mixed-currency + currency-mismatch guards (no ROAS across currencies), campaign comparison facts, `conversion_daily` (by day recorded); auto-pause audited (no change: ACTIVE-only, lock + guarded transition, never resumes) + `AdsOptimizationSafetyTest`; UI: new cards/columns, daily conversions, `AdsConversionValues` entry (manage-crm + crm capability); tests `AdsConversionValueTest` (19), `AdsOptimizationSafetyTest` (6), `AdsConversionValue.test.tsx` (13) | ✅ closed in code, NOT deployed | none | **2786** (+25) |
| 5 | Ads attribution & conversion tracking hardening (2026-10-01) — `AdAttributionService`: journey start links by the strongest identifier (inbound `wamid` = `referral_message_id` → `ctwa_clid` → single phone+ad+24h candidate), several candidates ⇒ left unlinked and marked `metadata.match.status = ambiguous` (no silent newest-wins), a session links once / a referral never re-attaches, `metadata.match.rule` explains each link, `metadata.campaign_match` explains an unmatched launcher campaign; CRM lead linked from the webhook only if promoted from the referral's own capture; conversion credits ONE attribution per lead (earliest), idempotent, cleared on revert, value stays NULL; `CrmLead` saved-hook fix (status change on a just-created instance now syncs); API rows expose derived `status` (converted/ambiguous/attributed/unresolved) + `match`; tests `AdAttributionHardeningTest` (25; incl. multi-row conversion policy, saved-hook lifecycle, candidate-limit ambiguity) | ✅ closed in code, NOT deployed | none | **2761** (+25) |
| 4 | Owner requests 2026-09-30 — Super Admin Platform account on Social / Ads + no plan / subscription enforcement for Super Admin on a client (suspension / module still enforced); launcher: Meta location search, manual placements (FB/IG feed, stories, reels), chosen button, ad account currency / status / spend cap / balance, in-page form with field errors — `SuperAdminPlatformAdsTest` (14) + `LaunchWizardOwnerRequests.test.tsx` (8) | ✅ **CLOSED** | 133 (1 nullable column) | **2706 + 1 skipped** (MariaDB) · 2698 + 9 skipped (SQLite) · frontend 681 · build clean · lint 64 / 0 |
| 3 | Ads dashboard & attribution reporting — `GET /api/social/ads/dashboard` (stored data only, 7d/30d/90d/custom ≤ 366 days, status counts, spend + daily trend, 6-stage funnel, per-campaign breakdown, NULL-with-status instead of 0, CPL/ROAS only on launcher campaigns with stored spend, ROAS only with a real value, 6 fixed queries); `/social/ads/dashboard` page; `social_ads.view` can open `/social/ads` read-only (any-of permission support in ProtectedRoute / nav) — `AdsDashboardTest` (13) + `AdsDashboard.test.tsx` (10) | ✅ **CLOSED** | none | **2692 + 1 skipped** (MariaDB) · 2684 + 9 skipped (SQLite) · frontend 673 · build clean · lint 64 / 0 |
| 2 | Ads entitlement enforcement & campaign lifecycle hardening — `ads` capability on every launcher route (tenant + Super Admin target, reads keep legacy expired-subscription semantics, no `social`); launch recorded before the first Meta write → ACTIVE / FAILED / UNCONFIRMED, local prerequisites checked first, per-tenant Idempotency-Key replay; guarded single pause/resume transition (lock, no-op when already there, ACTIVE↔PAUSED only, confirm-then-write conditional update, Meta 100/33 → UNAVAILABLE, unknown outcome never retried) shared with the auto-pause rule; Ad Account ownership guard; frontend `ads` gating, launch key retention, unconfirmed-outcome messaging, stale-client state cleared — `AdCampaignLifecycleTest` (34) + `AdsLifecycle.test.tsx` (7); 4 existing ads tests' fixtures now grant `ads` / connect a Page | ✅ **CLOSED** | 132 (additive + nullable) | **2679 + 1 skipped** (MariaDB) · 2671 + 9 skipped (SQLite) · frontend 663 · build clean · lint 64 / 0 |
| 1 | Ads & Attribution foundation — `ad_attributions` + `AdAttributionService` (referral → CRM lead → journey → conversion, tenant = owner of the receiving WhatsApp number, per-tenant idempotency, same-account campaign match only, no fabricated values), CTWA webhook / journey engine / CrmLead hooks (best-effort), read-only attribution list + funnel summary behind the Ads gates, `SocialTargetGate::denialFor` (shared target checks) — `AdAttributionTest` (19) | ✅ **CLOSED** | 131 (new table) | **2645 + 1 skipped** (MariaDB) · 2637 + 9 skipped (SQLite) · frontend 656 (unchanged) · build clean · lint 64 / 0 |

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

#### Industry Modules (Phase 11)

| # | Task | Status | Migration | Suite total after |
|---|---|---|---|---|
| 1 | **Industry Modules foundation & architecture audit (2026-10-02)** — see "Industry architecture" below. Foundation only: no student/patient/order/etc. feature was built in Task 1; every module was registered `available => false` (Task 2 ships Education students + batches). | ✅ | 2 additive: `2026_10_02_100000` (`account_industries`), `2026_10_02_100100` (idempotent data: 5 capabilities, provider rows, `view-industry-modules`, Route Master row) — **not yet run on the real DB** | **2831** (MariaDB, 1 skipped) · 2822 + 9 skipped (SQLite) · frontend 710 |
| 2 | **Education foundation (2026-10-03)** — see "Education architecture" below. First real industry module: students, parents/guardians, classes/batches. No attendance, fees, exams, reminders. | ✅ | 2 additive: `2026_10_03_100000` (4 `education_*` tables), `2026_10_03_100100` (idempotent: `view-education`, `manage-education`) — **not yet run on the real DB** | **2860** (MariaDB, 1 skipped) · 2851 + 9 skipped (SQLite) · frontend 743 |
| 3 | **Education attendance (2026-10-04)** — see "Education attendance" below. Student attendance (present/absent/late) per class/batch/date; bulk sheet save; history + totals. No fees, exams, timetable, reminders. | ✅ | 1 additive: `2026_10_04_100000` (`education_attendances`) | 2885 (vitest 767) |
| 4 | **Generic Billing & Collections core + Education fees (2026-10-01)** — see "Billing & Collections" below. Charge items, assignment to a CRM contact, partial/full payments, balances, history. No accounting ledger, GST, invoices, refunds, gateway, recurring billing or reminders. | ✅ | 2 additive: `2026_10_01_110000` (3 `collection_*` tables), `2026_10_01_110100` (registers capability `billing_collections`) | 2918 (vitest 790) |
| 5 | **Generic collection lifecycle (2026-10-01)** — see "Collection lifecycle" below. Cancel / reinstate on the generic core, `cancelled` status, Education adapter endpoints. No refunds, no customer-allocation behaviour (none was specified). | ✅ | 1 additive: `2026_10_01_110200` (3 nullable columns + FK on `collection_charge_assignments`) | 2941 (vitest 791) |

##### Industry architecture (the chain)

`Industry` (config registry) → `Industry Module` (registry entry, `crm_anchor` contact|lead) → `Features` (none yet) → `Permission` (`view-industry-modules`; a module may name its own `permission`) → `Capability` (`industry_<name>`, one per industry, via the existing plan / `account_entitlements` system).

- **Registry, not tables**: `config/industries.php` is code-owned (like the capability catalog). Education (school/coaching/institute), Healthcare (doctor/clinic/hospital), Ecommerce, Real Estate, Financial Services. A new industry or module is a registry entry (+ a capability row), never a boolean column or a per-industry table. Read via `App\Services\Industry\IndustryRegistry`.
- **Assignment**: `account_industries` (account_id, industry, subtype, assigned_by_user_id; unique account+industry; an account may hold several). `IndustryResolver::sync` validates against the registry before writing anything. Assignment is data — it never grants access.
- **One decision point**: `IndustryAuthorizer::denial()` = industry known → module known → `SocialTargetGate` (suspended → subscription [skipped on reads] → account module `industry_modules` → capability `industry_<name>`) → industry assigned → module `available` → permission. It has no notion of origin, so manual, Journey, API and import paths take the same decision.
- **Route gate**: `industry.guard[:industry,module]` (`EnsureIndustryAccessMiddleware`). The target account comes from `tenant.isolation`; a Super Admin without `?account_id` gets 422 `TARGET_ACCOUNT_REQUIRED` — industry routes deliberately do NOT use `target.account` (which falls back to the Platform account for Social/Ads). An Agent can only target itself/its sub-clients (404 otherwise, existing rule).
- **API**: `GET /api/industries` (catalog), `GET /api/industry/context`, `GET /api/industry/{industry}/modules` (all behind `view-industry-modules`); `GET|PUT /api/admin/accounts/{id}/industries` (`manage-accounts`; Super Admin any account, Agent only its own clients, else 404).
- **CRM reuse**: modules declare `crm_anchor`; industry profiles will hang off the existing `crm_contacts` / `crm_leads`. No duplicate contacts, leads, users, messaging or journeys.
- **Plans**: the five `industry_*` capabilities are in `Phase1FoundationSeeder::CAPABILITIES` and `PROVIDER_CAPABILITIES` but in **no** plan bundle. They are sold through plan management (`capabilities` slugs) or a manual/Agent entitlement grant. Plan semantics unchanged.
- **Navigation**: `/auth/me` `user.industry_modules` = usable `"industry.module"` keys (empty for Super Admin, for accounts without the module, and while no module is available). Frontend: `NavItem.requiresIndustryModule` + `components/layout/industryNav.ts`; fail-closed. The sidebar order is untouched; a comment marks where the Industry group goes (after CRM). No items in Task 1 — Education added the first one in Task 2.
- **Fail closed**: a registry permission that does not exist in the DB denies (no 500); `industry_modules` must exist in Route Master or `effectiveModules()` strips it.

**Limitations / owner notes (Phase 11 T1)**: assignment is API-only (no admin UI yet); no industry feature is implemented; accounts with an explicit `allowed_modules` array do not get `industry_modules` until it is enabled for them; a deployment needs migration `2026_10_02_100100` (or `RouteMasterSeeder` + `RolePermissionSeeder` + `Phase1FoundationSeeder`) — the data migration only inserts the Route Master row when `system_routes` is already populated (an empty table means "no restriction").


##### Education architecture (Phase 11 Task 2)

```
contacts (CRM) ◄── education_students ◄── education_student_guardians ──► contacts (CRM: the parent/guardian)
                        ▲
                        └── education_group_members ──► education_groups (kind: class | batch)
```

- **No second CRM.** A student is a *profile on a CRM contact*: name, phone, email, tags and CRM history stay on `contacts` (the student table has no such columns — asserted by a test). A person is named by `contact_id` (must be this account's) or by `phone_number` (+name/email), resolved through the existing `ContactResolver` (find-or-create per account).
- **Tables** (all with `account_id`): `education_students` (contact_id, admission_number, status active|inactive|graduated|withdrawn, admission_date, metadata json; unique account+contact, unique account+admission_number, NULLs allowed), `education_groups` (kind class|batch, name, academic_year, status active|archived, metadata), `education_student_guardians` (student, contact, relationship parent|guardian; unique student+contact — a student may have 0..n, a contact may guard several students), `education_group_members` (student, group; unique pair).
- **Tenant safety is in the database**: every reference is a composite FK `(id, account_id)` — a student cannot point at another account's contact, a guardian link cannot name another account's contact, a membership cannot join another account's group (tested by direct inserts). Contact FKs are RESTRICT; education-owned children CASCADE.
- **Contact lifecycle (CRM stays in charge)**: `Contact::blockingDependencies()` now also reports `education_students` / `education_guardian_links`, so `DELETE /api/crm/contacts/{id}` answers 409 `CONTACT_HAS_DEPENDENTS` for a student/guardian (unlink first). `ContactService::merge` calls `EducationContactReferences`: it refuses (422) two students or a student becoming their own guardian, otherwise moves the source's student profile/guardian links to the surviving contact (duplicate links dropped). No other CRM behaviour changed.
- **Vertical = configuration**: `config/industries.php` `education.vertical_config` (school → default group kind `class`, coaching/institute → `batch`). One model, one API; no per-vertical code path.
- **One write path**: `EducationStudentService` / `EducationGroupService` validate first and write in ONE transaction (a rejected request leaves no student, no new contact, no link). Controller, and later Journey / API / import / messaging, call the same services; authorization never looks at the source of a record.
- **API** (`/api/industry/education/…`, route middleware `industry.guard:education,students|batches`, NO route-level `permission:` — IndustryAuthorizer is the single decision): `GET|POST /students`, `GET|PATCH /students/{id}`, `POST /students/{id}/guardians`, `DELETE /students/{id}/guardians/{linkId}`, `PUT /students/{id}/groups`; `GET|POST /groups`, `GET|PATCH /groups/{id}`. No deletes of students/groups (status instead). Target account = `requireTargetAccount` (Super Admin must pass `?account_id=`, no first-client/Platform fallback; Agent only own sub-clients); the body's `account_id` is never read.
- **Authorizer extension**: a registry module may now carry `write_permission` (reads use `permission`; writes `write_permission`, falling back to `permission`). Education: `view-education` (reads) / `manage-education` (writes + subscription must be active). Granted to `admin` (+ super_admin) by `RolePermissionSeeder` and migration `2026_10_03_100100`; ordinary `user` role does not get them.
- **Capability / plans**: the existing `industry_education` capability (Task 1) is the entitlement — no new mechanism. **No seeded plan bundles it** (asserted); pricing is an owner decision, so it is sold via Plan Management (`capabilities` slugs) or an account entitlement grant.
- **Frontend**: `/education` (Pattern A page, tabs Students | Classes / Batches), nav item after CRM (`requiresIndustryModule: 'education.students'` + `view-education`), `IndustryModuleRoute` guard. Keys come from `/auth/me` `industry_modules` for a tenant user, or from `/industry/context` for the client a Super Admin/Agent selected (`useIndustryModuleKeys`, keyed by account so a late answer for a previous client is ignored; none for a Super Admin with no selection). Create/edit student, create/edit class/batch, server field errors inline, read-only on expired subscription, write controls hidden without `manage-education`.


##### Education attendance (Phase 11 Task 3)

- **Table** `education_attendances`: account_id, student_id, group_id, attendance_date, status (string; `present|absent|late`, extend `EducationAttendance::STATUSES`), recorded_by_user_id (nullable). Unique `(account_id, student_id, group_id, attendance_date)`; composite FKs `(student_id, account_id)` -> education_students and `(group_id, account_id)` -> education_groups (cascade) make cross-tenant rows impossible at DB level. No name/phone/email stored: the CRM contact is reached only via the student.
- **Service** `EducationAttendanceService` (no source-based authorization; callable later by Journey/API/import/scheduler): `sheet`, `saveSheet` (validate everything first, then one transaction + atomic upsert, so no partial update and concurrent submits cannot duplicate), `mark`, `history`, `summarize`. Membership (student in THIS group of THIS account) is enforced in the service, not by FK, so history survives a student leaving or a group being archived. Archived group: reads OK, writes 422. Date: YYYY-MM-DD, at most today+1 day. At most 500 records per save.
- **API** (`industry.guard:education,attendance`): `GET /api/industry/education/groups/{id}/attendance?date=`, `PUT .../groups/{id}/attendance` `{date, records:[{student_id,status}]}`, `GET .../students/{id}/attendance?group_id&from&to&status&per_page`. Reads need `view-education`; writes `manage-education` + active subscription. Super Admin must pass `account_id`.
- **Percentage** = (present+late)/total*100, 1 decimal; `null` (UI "N/A") when there are no records.
- **Frontend**: `AttendancePanel` + `AttendanceHistory` as tabs on `/education`, shown only with module key `education.attendance`; stale-response protection (keys bound to account/group/date), duplicate-submit guard.
- **Limitations**: no reports/analytics, no reminders, no import, no edit-history/audit of changes; `fees` remains the planned module.


##### Billing & Collections (Phase 11 Task 4)

- **Generic core** (no industry knowledge): tables `collection_charge_items` (catalogue), `collection_charge_assignments` (a charge owed by one CRM contact, `amount_due` snapshot + optional `due_date`), `collection_payments` (append-only). Customer = the existing CRM `contacts` row through composite FK `(contact_id, account_id)` (RESTRICT, like `education_students`) — NOT a polymorphic reference. Items/assignments/payments carry `account_id` with composite tenant FKs. No name/phone/email is copied. `scope` is an opaque string the calling module supplies (Education = `education`) that partitions an account's records so one consumer never sees or pays another's for the same contact; the core has no list of scopes.
- **`App\Services\Collections\BillingCollectionService`** (callable without HTTP; no authorization inside, never by caller origin): create/update items, `assign`, `updateAssignment`, `recordPayment`, `ledger`, `paymentHistory`. **Money**: DECIMAL(12,2), wire strings, ALL arithmetic in integer minor units (no float; ≤2 decimals, positive only). paid/outstanding/status (`open|partially_paid|paid|overdue`) are DERIVED from payment rows — no stored balance. `recordPayment` locks the assignment row (`SELECT … FOR UPDATE`), re-reads paid under the lock, rejects overpayment / paying a paid charge, inserts in one transaction. Optional `idempotency_key` (unique per account): same key + same payment → original returned (HTTP 200, `idempotent:true`); same key + different payment → 422. Payments are immutable (no update/delete route); changing an item's price never rewrites assignments; `amount_due` can never drop below what was paid; archived items cannot be assigned but existing assignments stay payable.
- **Education adapter**: `EducationFeeService` only maps student → `contact_id` and pins scope `education`; `EducationFeeController` routes under `/api/industry/education`: `GET|POST /fee-items`, `GET|PATCH /fee-items/{id}`, `GET|POST /students/{id}/fees`, `PUT /students/{id}/fees/{fee}`, `POST /students/{id}/fees/{fee}/payments`, `GET /students/{id}/fee-payments`, `GET /fee-payments`. Foreign ids are 404.
- **Authorization / entitlement**: registry module `education.fees` (`view-education` / `manage-education`, no new permission) has `requires_capability => billing_collections`; `IndustryAuthorizer` checks that extra capability (generic: any module may name one) through the same `SocialTargetGate`, so API and the frontend's `/auth/me` `industry_modules` keys share one source. `billing_collections` (category `platform`) is NOT bundled in any plan and does not imply Education; Education does not imply billing. Account module reused: `industry_modules`.
- **Frontend**: Fees tab on `/education` (only with key `education.fees`): `FeesTab` → `FeeItemsPanel` (+`FeeItemFormModal`) and `StudentFeesPanel` (+`AssignFeeModal`, `RecordPaymentModal`); keyed queries, selection/dialogs bound to the client, per-form idempotency key, in-flight guard.
- **Concurrency**: `tests/Probes/collections_payment_concurrency_probe.php` (forked processes on throwaway DB `wa_throwaway_collections`) run by `BillingCollectionServiceTest::test_concurrent_payments_cannot_exceed_the_balance_on_real_mariadb` (needs a `wa_throwaway_*` test DB). Verified to FAIL when the row lock is removed.
- **Assignment updates (integrity fix)**: `PUT …/fees/{fee}` may change ONLY `amount_due` and `due_date`. `charge_item_id`, `contact_id`, `account_id`, `status`, `amount_paid`, `outstanding` are refused with 422 (never silently ignored) before anything is written. Invariant: payments ≤ amount due — the paid total is re-read under the same `SELECT … FOR UPDATE` row lock `recordPayment` takes, so a reduction below the paid total is a 422 (`amount_due`) that changes no row; reducing exactly to the paid total is allowed and closes the charge (`paid`). The probe has a payments-vs-reductions race check (fails with paid 90 > due 50 if the lock is removed). A wrongly assigned charge item cannot be re-pointed: it is immutable by design.
- **Migration order**: the two collections migrations are dated `2026_10_01_110000` / `2026_10_01_110100` (today, after the last pre-Phase-11 migration `2026_10_01_100000`); they depend only on pre-existing tables (`accounts`, `users`, `contacts`, `providers`, `capabilities`), so they correctly sort before the Phase 11 T1–T3 migrations (`2026_10_02`–`2026_10_04`, which are post-dated vs the real date but unchanged). Laravel orders and runs pending migrations by file name, so this is harmless.
- **Limitations**: customer is a CRM contact (a lead-anchored consumer would need a lead reference — not built); one currency-less amount (no currency column); no refunds/reversals, tax, invoices, gateway, recurring generation or reminders; ledger capped at 200 assignments per student; no UI to adjust an assignment (API only); overdue is computed against the server date; no import/Journey/API/scheduler entry points.

**Limitations (Phase 11 T2)**: no fees/exams/reminders/timetable (`attendance` shipped in Phase 11 T3, `fees` in T4); guardian add/remove has API endpoints but the UI only offers one optional guardian on create (guardians are shown in the list); student name/phone are edited in CRM Contacts; no student/group delete; no import; admission number is optional (unique per account when set).

### 8.2 Long-range roadmap (architecture report §27 numbering)

| Report phase | Scope | Status against code |
|---|---|---|
| 0 — QR stabilization | Login throttling, async bulk upload, real queue worker | ⚠️ **UNVERIFIED** — not confirmed in this codebase; treat as open |
| 1 — Architecture foundation | Provider/plan/billing split, module gates, first real tests | ✅ Done (test suite now 1077, 17 modules gated). ❌ **SoftDeletes never added** |
| 2 — Meta WhatsApp foundation | Productize `MetaCloudApiDriver` | ✅ Done |
| 3 — Meta messaging suite | WABA embedded signup, broadcast parity, unified inbox, Flows/catalog | 🟡 Partial — Flows tables exist; embedded signup and full parity **not** built |
| 4 — Automation engine | `delay`/`wait` node, recurring triggers, paused-execution persistence | 🟡 Partial — **durable delay execution built (Phase 7 Task 1)** on `whatsapp_flow_sessions` (waiting + `wait_until`, scheduler + database queue, retry, cancel). Recurring triggers and flow versioning **not** built |
| 5 — CRM *(= execution Phase 6)* | Contact/Lead/**Deal/Pipeline/Stage/Task/Note/Tag** | 🟡 Contacts, Leads, a status-based pipeline and tenant-scoped lead **tags** exist. **No `deals`, `crm_tasks` or `crm_notes` tables** |
| 6 — AI Platform *(= execution Phase 8)* | Content generation + append-only credit ledger | 🟡 **Credit ledger, plan credits and spending layer built** (Phase 8 Tasks 1–3: `credit_ledger_entries`, not `ai_credit_transactions`; `CreditConsumptionService`); **AI provider abstraction + authorization built** (Phase 8 Task 4: `AiService`/`AiManager`/`AiAuthorizer`); usage metering, pricing and feature wiring not built |
| 7 — Social media | LinkedIn OAuth, organic scheduling/analytics | 🟡 Meta only; organic scheduling (Phase 9 T3) and post insights (Phase 9 T4) done; LinkedIn not. `SocialOAuthProviderFactory` **throws** for `linkedin` and `google` |
| 8 — Catalog/ecommerce | Commerce capability | ❌ Capability seeded; no implementation |
| 9 — Ads + attribution *(= execution Phase 10)* | Full ad → deal → conversion chain | 🟡 Foundation built (Phase 10 T1) + launcher entitlement/lifecycle hardened (Phase 10 T2): ad → click/referral → WhatsApp conversation → CRM lead → journey → conversion (= CRM lead `converted`). No Deal model, so no deal stage / revenue; no conversion value observed yet; no Ads dashboard |
| 10 — Advanced analytics | Extend analytics to CRM/AI/Ads data | ❌ Not started |

### 8.3 Confirmed gaps (verified against code, not inferred)

#### Frontend actionability audit (§23, 2026-09-29)

Every `disabled` / `aria-disabled` / disabled-looking control in `frontend-app/src` (241 `disabled` hits, no "coming soon",
no handler-less primary buttons found) was classified. Rule applied: implemented + prerequisite missing → clickable and
explain/route; entitlement/permission → explain + upgrade path; genuinely impossible or in-flight → may stay disabled
**with a visible reason**. Frontend state never replaces backend authorization.

| Area | Control | Before | After / classification |
|---|---|---|---|
| Social Accounts | Connect Meta Account | disabled for SA Global View; stuck disabled after a closed popup | **Fixed** — clickable → client picker → OAuth; popup-closed detection; disabled only while a connection is in progress (label "Connecting…", tooltip) |
| Social Accounts | empty list | text only | **Fixed** — explains + Connect button; `?returnTo=` → "Continue where you left off" |
| Meta Ads Launcher | Paid Meta Ad Campaign / Organic Post / empty list | disabled for SA Global View; no prerequisite check until launch | **Fixed** — clickable → client picker → prerequisite check (ad account connected + healthy; page recommended; publishable asset for organic) → explanation + connect link or the wizard; check failures (e.g. 403) never block — backend decides |
| Comment Rules | Add Rule | disabled for SA Global View | **Fixed** — client picker |
| Social Inbox / Leads / Reports / Comment Rules / CRM (all pages) | no-client state | "use the switcher above" (hidden on mobile) | **Fixed** — in-page "Choose a client" button |
| CRM Leads | Add lead | disabled: no client / no CRM on target / read-only | **Fixed** — no client → picker then form; no CRM → explanation + upgrade path; read-only stays disabled (below) |
| Journey Builder | palette node locked by capability/provider | disabled + hover tooltip | **Fixed** — `aria-disabled`, click shows the reason inline (+ upgrade path for a capability); still never added |
| Journey Builder | KB / AI agent selector empty | "none yet" | **Explained** — no management screen exists in this version (API only: `/api/knowledge-bases`, `/api/ai-agents`); stated in the empty state. **Open gap: no Knowledge Base / AI Agent management UI** |
| All pages | mutation buttons in read-only mode (expired/suspended subscription: CRM controls, New Rule, Invite, Save credentials, Send Alert…) | disabled + tooltip; banner without action | **Intentional, explained** — backend `subscription.guard` refuses them; banner now links to Billing (billing routes are outside `subscription.guard`) or says whom to ask |
| Users | Invite Member | disabled for limit / read-only / no assignable roles (last had no reason) | **Explained** — reason tooltip added for "no roles"; limit has a visible banner |
| Send Alert | Send | WhatsApp disconnected / no groups (no reason) | **Explained** — disconnected banner already links to WhatsApp Setup; "no contact groups" reason added |
| Route Master (admin) | New Route with no categories | no reason | **Explained** — tooltip added |
| Everywhere | submit/confirm while saving/sending/loading; required-field-empty; pagination ends; "clear filters" when none; per-row busy; template approve before test-fire; default group / non-empty category delete; read-only display inputs; inherited permissions; Journey test-send input after result; default-trigger value; buttons cap | disabled | **Intentional** — in-flight, form validation or domain invariants, each with visible state or tooltip; unchanged |
| Sidebar | items without permission/module/capability | hidden (not disabled) | **Intentional** — consistent with the routes' guards; pages reached have usable primary actions |


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
- **P6-2 consequences (by design, parity with `subscription.guard`):** `hasActiveSubscription()` requires `refreshStatus() === 'active'`, so a
  target whose message quota is exhausted counts as not active: an Agent / Super Admin cannot write its CRM data and its automated
  captures stay unpromoted (`not_entitled`) until renewal/top-up, then `CaptureLeadLinker::retry()` or the backfill promotes them.
  The client's OWN users on a quota-exhausted account are already refused non-GET writes by `subscription.guard` (unchanged).
  Denied foreign-target writes are audited; blocked captures are recorded in `crm_capture_link_failures`, not in `activity_logs`.
- **Phase 9 Task 1 follow-ups (not done):** (1) Deploy prerequisite: `entitlements:backfill-plan` (the connection routes
  now require `social`) and `FRONTEND_URL` outside local. (2) Connection health is checked on demand (`POST
  /social/accounts/{id}/check`) and derived from `token_expires_at` — **done in Phase 9 Task 2** (scheduled sweep +
  Ads/Organic/Inbox write-back). (3) Meta long-lived user tokens (~60
  days) cannot be refreshed without the user — expiry means Reconnect. Tokens bound BEFORE this task are short-lived
  (~1–2 h) and show as expired: reconnect. (4) Disconnect deletes the row and its credentials locally; the app is not
  revoked at Meta (by design, see `MetaOAuthProvider::revoke`). (5) Only Meta is implemented; LinkedIn/Google slugs
  remain declared but unimplemented (404 `SOCIAL_PROVIDER_UNAVAILABLE`). (6) The per-account platform flags
  (`allow_facebook` / `allow_instagram` / …) stay the Super Admin's per-tenant provider switch, separate from the
  plan's `social` capability.
- **Phase 9 Task 2 follow-ups (not done):** (1) Deploy: migration 128 and a minute-level `schedule:run` (drives
  `social:check-connections` every 15 min and the `queue:work database --queue=social` worker). (2) ~~Comment replies and
  the Lead Ads fetch not wired~~ — done in Task 2.1. A Page / Instagram account bound by two tenants is no longer routed arbitrarily — Task 2.2
  (not processed + audited). (3) Other (non-connection) Meta rejections
  in Ads/Organic/Inbox still surface Meta's own `error.message` text, as before this task. (4) Credentials are never
  refreshed automatically (Meta long-lived tokens need the user): expiry/revocation always means Reconnect. (5) The
  launcher form is kept by opening Social Accounts in a new tab; the in-tab `returnTo` flow reopens the launcher but
  does not restore typed values (unchanged).
- **Owner requests 2026-09-30 — limitations / follow-ups (not done):** (1) The AI Ad Copywriter keeps its own gate:
  the target (the Platform account included) needs an active subscription, the `ai` capability and AI credits, and
  is billed for the copy — otherwise "Generate with AI" is refused and copy can be written by hand. (2) Journey node / runtime
  entitlements and native WhatsApp group rules are unchanged for Super Admin. (3) Video creatives are still not
  uploaded to Meta (`/advideos`) — a video file cannot be used as ad media yet; Stories / Reels work best with
  vertical media. (4) `instagram_actor_id` and the per-objective button lists follow Meta's documentation but are
  unverified live [Hypothesis]; Meta remains the final validator (rejections are stored as FAILED). (5) The ad
  account currency cannot be changed from here (Meta fixes it at ad-account creation); no platform wallet — Meta bills
  the ad account directly. (6) Campaigns launched before migration 133 have no stored currency and display as INR.
  (7) City radius targeting is not offered (a city targets Meta's default area).
- **Phase 10 Task 3 limitations / follow-ups (not done):** (1) Spend exists only for launcher campaigns and only as
  fresh as `ads:check-performance-rules` (it stores today's insights for ACTIVE campaigns every 15 min; paused days,
  backfill of past days and ad-set / ad-level spend are not collected). (2) Campaign counts are current status, not
  status history. (3) Funnel is a cohort of referrals received in the range; a later conversion moves into the
  referral's period; conversion leaving `converted` removes it (Task 1 rule). (4) Conversion value / ROAS stay
  unavailable until something records `conversion_value` (nothing does today). (5) Breakdown is capped at 200 most
  recent campaigns (totals are not). (6) The dashboard allows reads on an expired subscription while the Task 1
  attribution endpoints deny them (divergence kept, owner may align). (7) Dates are compared in the app timezone
  (UTC); no per-tenant timezone. (8) No export / PDF; the legacy monthly `social/reports` (reports module) is unchanged.
- **Phase 10 Task 2 limitations / follow-ups (not done):** (1) **Entitlement impact**: Starter/Growth tenants (no
  `ads` in their plan) lose the Ads Launcher; the provider rules forbid granting `ads` to a QR-engine account — owner
  to confirm this is the intended product rule before deploy. (2) The AI Ad Copywriter (`/social/ai/generate`) keeps
  its own `AiAuthorizer` gate (`launch-meta-ads` + `meta_ads` + active subscription + `ai` capability) and does NOT
  require `ads`; media upload (`/social/media/upload`) stays ungated by `ads` because Organic posts share it. (3) ~~The
  frontend route/nav still require `launch-meta-ads`; a `social_ads.view`-only user cannot open the page~~ — fixed
  in Phase 10 Task 3. (4) Attribution endpoints deny
  reads on an expired subscription (Task 1 contract kept) while the launcher list allows them — owner may align.
  (5) An `UNCONFIRMED` launch / an unconfirmed pause is not reconciled with Meta automatically (no Graph read-back);
  the auto-pause rule re-evaluates on its next cycle and may pause again (pausing is idempotent at Meta). (6)
  `UNAVAILABLE` is terminal locally (Meta 100/33 also covers missing permissions); no un-mark path yet. (7) Revoking
  `ads` does not pause campaigns already running at Meta. (8) No delete/archive endpoint (none existed;
  `social_ads.delete_rules` still maps to no action). (9) The status lock uses the default cache store — it must be
  a shared store (database/redis) when more than one app server runs. (10) Ad set / ad / creative ids are not
  individually controllable (only the campaign status is changed, as before).
- **Phase 10 Task 1 limitations / follow-ups (not done):** (1) Only Meta click-to-WhatsApp referrals are captured
  (the QR/Baileys engine receives no referral data); Lead Ads form leads are not attribution rows (they already carry
  `leads.ad_id` / `form_id`). (2) Conversion = the attributed CRM lead is currently `converted`; no Deal model and no
  observed amount, so conversion value and ROAS are null. (3) Campaign / ad set are known only for ads created by the
  launcher (`ad_campaigns.meta_ad_id`); other ads keep only the ad id — no Graph lookup is made (existing provider
  code untouched). Spend = launcher daily metrics over the same date range (an approximation of the attribution
  window). (4) A journey start is linked to the newest referral from the same phone (and ad) on the same account within
  24 h that has no journey yet; a lead is linked via the CTWA capture or a save_lead in the attributed session only —
  manual / API leads for the same phone are deliberately not linked. (5) Attribution is recorded for every tenant that
  receives a referral (ingestion is not gated); reading it requires the Ads gates. (6) No Ads dashboard UI yet; no
  frontend changes in this task. (7) ~~Existing ad launcher routes still gate on `meta_ads` module only (no `ads`
  capability)~~ — closed by Phase 10 Task 2. (8) `leads.provider_lead_id` stays globally unique (Phase 5/6 audit F-6.4); attribution
  uses its own per-tenant key and never links another tenant's capture row.
- **Phase 9 Task 6 limitations / follow-ups (not done):** (1) `target.account` applies to a Super Admin only. An Agent
  acting for a sub-client (?account_id=) is checked by module.guard / capability.guard on the sub-client, but
  subscription.guard still checks the Agent's OWN account (pre-existing, platform-wide — not changed here). (2)
  `requireAccount()` keeps its APP_ENV=local fallback for non-Social areas (CRM, WhatsApp, team, …) — unchanged by scope.
  (3) Behaviour change for Super Admin (owner-approved "mirror tenant checks"): on legacy Social routes and on Social
  Accounts / Organic Posts, a suspended / module-off / (where the route has one) capability-less client can no longer be
  read or changed by a Super Admin, and a lapsed client is read-only — previously these were open ("Absolute Super Admin
  Control" in module.guard / subscription.guard, which are themselves unchanged). (4) Tenants' expired-subscription reads:
  legacy routes allow GET (subscription.guard); the Phase 9 insights / analytics endpoints deny (SocialTargetGate) — the
  Task 4/5 contract, kept.
- **Phase 9 Task 5 limitations / follow-ups (not done):** (1) Metrics are each post's latest LIFETIME snapshot
  attributed to its publish date — the trend is "results of posts published in the period", not daily platform
  activity (no time series is stored). (2) Totals only cover posts that reported a metric; the cards say "Reported by
  N of M posts" when coverage is partial, and impressions / reach / clicks / video metrics stay unavailable until the
  Meta insights scopes decision (Task 4 limitation 1). (3) Dates are bucketed in the app timezone (`config('app.timezone')`),
  not the viewer's. (4) No external permalink is stored for posts, so top posts have no "open on platform" link. (5)
  ~~Other insights/publishing endpoints still use `requireAccount()`~~ — closed in Task 6 (`requireTargetAccount()` in every
  Social controller). (6) The per-post Refresh on the dashboard is available to any user with
  `view-social-analytics` (same permission as the refresh endpoint since Task 4).
- **Phase 9 Task 4 limitations / follow-ups (not done):** (1) **Meta connect scopes do not include `read_insights`
  or `instagram_manage_insights`** (verified in `MetaOAuthProvider::SCOPES`; the publishing scopes
  `pages_manage_posts` / `instagram_content_publish` are not requested either — pre-existing). Engagement counts
  (reactions / likes, comments, shares) come from the post object (`pages_read_engagement` / `instagram_basic`);
  impressions / reach / clicks / video metrics need the insights scopes — adding them forces every tenant to
  reconnect, so it is an owner decision; until then those metrics are reported as unavailable (`permission`), not
  0, and the connection is NOT marked revoked. (2) Graph calls use the existing `v19.0` constant; Meta has been
  retiring post-level metrics (e.g. impressions → views) — the provider asks per metric when Meta rejects one, so
  a retired metric becomes `unsupported_metric` instead of failing the fetch. [Hypothesis] — request shapes are
  the documented contract, not verified against a live Page. (3) Meta omits `shares` on a Facebook post with no
  shares; that is reported as unavailable (`not_reported`), never inferred as 0. (4) Code 100 / subcode 33 means
  "object does not exist or no permission" — treated as post_not_found (no further worker refreshes; an explicit
  refresh still tries). (5) Only the latest snapshot is stored (no time series). (6) ~~The insights UI sits only in the Organic Posts panel (manage-social-accounts)~~ — closed in Task 5: the
  `/social/analytics` page follows `view-social-analytics`. (7) LinkedIn: no insights (no LinkedIn OAuth). (8) On `QUEUE_CONNECTION=sync`
  the worker's jobs run inside the scheduler tick.
- **Phase 9 Task 3 limitations / follow-ups (not done):** (1) Deploy: migration 129 + the minute-level `schedule:run`
  (drives `social:publish-due` every minute and the existing `database:social` worker); on `QUEUE_CONNECTION=sync`
  the job runs inside the scheduler tick. (2) `outcome_unknown` (no answer from the platform, or a stale claim that had
  already called it) is never re-sent automatically; a manual Retry may duplicate the post — the UI asks for a second
  click. (3) Transient retry applies to worker-sent (scheduled / retried) posts only; a manual "publish now" that hits a
  temporary error fails and can be retried. (4) Status transitions are conditional query-builder UPDATEs, so
  `LogsActivity` records the post's creation but not each status change (the row itself carries status, attempts,
  failure_code, timestamps). (5) Other (non-connection) provider rejections now return a safe message with the Graph
  error code (the raw `error.message` is no longer stored on the post). (6) `CompleteInstagramVideoPostJob`'s own
  catch-all still stores the exception message (pre-existing, unchanged). (7) `organic_posts.media_url` is still
  `varchar(255)` while validation allows 2048 (pre-existing mismatch, not changed). (8) Instagram posts without media
  are now refused with 422 before a row is created (previously a `failed` row was logged). (9) LinkedIn publishing
  moved into `LinkedInPublisher` unchanged and remains unreachable (no LinkedIn OAuth); its media fetch re-downloads the
  stored media URL server-side (pre-existing). (10) Provider request shapes remain [Hypothesis] — not verified against a
  live Page.
- **Phase 8 Task 11 limitations / follow-ups (not done):** (1) Agents execute ONLY through the Journey `agent` node;
  there is no manual "run/test agent" endpoint and no agent management UI (API only; the builder has a selector). (2) On
  the automated (Journey) path there is no acting user, so a tool's PERMISSION is enforced when a user grants it (version
  creation / enable) and when a journey referencing the agent is saved; at run time the account-level checks (active,
  subscription, `chatbot`, `ai`, the tool's module + capability + own rule) run on every call. A user who later loses the
  permission does not revoke tools they granted — disable the agent or save a new version. (3) `crm.lead.capture_current`
  de-duplicates by "open lead of this contact" — two DIFFERENT concurrent executions for the same customer can both create
  a lead (the same execution never does: invocation key + transaction, probe-verified). (4) A charged model decision lost
  to a crash before its persist fails the step permanently (`internal_error`, never re-charged) — Task 7's rule. (5) The
  execution's decisions/tool results (lead ids/statuses, arguments the model chose such as a name/email) live in
  `context_data['@agent'][node]` until the node completes (visible in the sessions API; cleared on success, kept on a
  failed session); `context_data['@agents']` keeps the version pins for the session. (6) The model hint is honoured only
  when `AI_JOURNEY_ALLOWED_MODELS` lists it (shared with prompt/agent). (7) The timeout is checked between steps; a
  single provider call is bounded by the provider HTTP timeout. (8) Deleting an agent is not refused while journeys
  reference it — such a node then fails permanently (`invalid_configuration`). (9) No persistent/cross-session memory,
  no vendor tool-calling API (a provider-neutral JSON protocol instead), no arbitrary HTTP/SQL/shell/code/file tools, no
  per-agent credentials — by design (task exclusions). (10) MySQL 8 not run for this task.
- **Phase 8 Task 10 limitations / follow-ups (not done):** (1) The Super Admin's pre-existing P5-7 SAVE-time
  node-entitlement bypass is unchanged: a Super Admin can save a rag journey for a client without `ai`; the run is
  refused at execution (`entitlement_blocked`) before any retrieval, generation or charge — entitlement is enforced
  where AI is used and billed. Knowledge-base ownership is checked for everyone, Super Admin included. (2) No passage
  found → the output variable is `''` and nothing is generated (no canned answer): authors branch on it with a
  Conditional node. A weakly related passage still counts as a hit (no similarity threshold on the node — `min_score`
  exists on the retriever but is not exposed). (3) Two charges per execution (query embedding + generation); a
  retried step reuses the cached hits (no second embedding charge) — but a query embedding charged whose hits were lost
  before they were cached (crash in that window) fails the step (`internal_error`). (4) The prompt instructs the model
  to answer only from the passages; whether it complies is a model property (not verifiable in tests — fake provider).
  (5) The cached hit ids/scores (`context_data['@rag']`) are visible in the sessions API until the node completes.
  (6) A knowledge base deleted or re-indexed between retrieval and a retry: `passages()` returns only chunks that are
  still live (possibly fewer / none → empty answer). (7) The builder selector lists up to 200 knowledge bases; there is
  still no knowledge-base management UI.
- **Phase 8 Task 9 limitations / follow-ups (not done):** (1) ~~The Journey `rag` node is still NOT executable~~ —
  done in Phase 8 Task 10. (2)
  `DatabaseVectorStore` is a development store: exact brute-force scan, ≤ `KNOWLEDGE_MAX_SEARCH_CHUNKS` (20 000)
  chunks per search, vectors on MySQL rows — production scale needs a real vector backend behind `VectorStore`.
  (3) Plain text only (no PDF/DOCX/HTML/URL extraction; no file upload); chunking is character-based, not
  tokenizer-based. (4) No knowledge-base UI (API only). (5) A batch charged but whose vectors were lost (crash
  between charge and store) fails the document (`KNOWLEDGE_EMBEDDING_ALREADY_CHARGED`); an explicit re-process
  builds a new version and is charged again. (6) Changing the embedding provider/model does not re-index existing
  knowledge bases — each keeps its locked space; a new space needs a new knowledge base (no bulk re-index tool).
  (7) Search embeds every query (billed); no query cache. (8) OpenAI/Gemini embedding shapes are [Hypothesis],
  verified against faked HTTP only; Gemini batch responses may carry no token usage (→ missing-usage rule).
  (9) Deleting a knowledge base while a job runs: the job ends `superseded`/`failed`; embeddings already made are
  charged (they happened). (10) Needs a `knowledge` queue worker (scheduled in `routes/console.php`).
- **Phase 8 Task 8 notes / follow-ups (not done):** (1) Verified against faked HTTP only (no live key here) —
  `GeminiProviderTest::test_optional_live_smoke_against_the_real_gemini_api` runs against the real API when
  `GEMINI_LIVE_SMOKE=1` and `GEMINI_API_KEY` are set; recommended once before switching production to Gemini.
  (2) `AI_GEMINI_MODEL` default `gemini-3.5-flash` is a placeholder (Google's model list, checked 2026-09-29, shows
  the 2.5 family access-limited); pricing is still provider-neutral `tokens_per_credit` — set
  `ai.credits.tokens_per_credit_overrides` (e.g. `gemini:<model>`) if Gemini must be priced differently (owner decision).
  (3) Like OpenAI/Anthropic, vendor auth/model/rate-limit errors all map to `AI_PROVIDER_FAILED` (no finer-grained
  code exists in `AiException`); a Journey retries them with its normal backoff. (4) The deprecated per-tenant
  `accounts.gemini_api_key`, the `social_provider_configs` 'gemini' row and `services.gemini` are still not read by
  anything in the AI layer (left in place; removal would be a separate, authorized clean-up). (5) Google's newer
  Interactions API exists; `generateContent` (v1beta) is used because it is the documented stateless endpoint.
- **Phase 8 Task 7 consequences / follow-ups (not done):** (1) `rag` needs a retrieval backend (knowledge-base
  model + ingestion + embeddings + index, tenant-scoped) implementing `App\Services\Ai\Retrieval\KnowledgeRetriever`
  before it can join `RUNTIME_EXECUTABLE_TYPES`. (2) The `agent` node is ONE bounded model call (instructions +
  one input variable → reply); the palette's "hand the conversation to a configured AI agent" implies things that do
  not exist and were NOT built: an agent registry (`agentId` is a label and selects nothing), tool/action execution,
  multi-turn memory/conversation history, per-agent credentials. (3) AI nodes on the inbound path answer after a
  hand-off to the `journeys` worker — up to ~1 min with only the scheduled per-minute worker (same latency as a
  delay/deferred message; a continuously running `journeys` worker makes it immediate). A customer message that
  arrives while the node is queued is answered by chatbot rules, as during a delay. (4) A reply lost to a crash
  between the charge and the checkpoint write fails the session (`internal_error`) rather than charging twice;
  replies are not stored in `ai_operations` by design. (5) The node's model hint is honoured only when listed in
  `AI_JOURNEY_ALLOWED_MODELS` (empty by default). (6) `context_data['@ai_runs']` (per-node visit counters) is
  visible in the sessions API next to the collected variables. (7) Each AI call writes one `ai.authorize`
  `activity_logs` row (AiAuthorizer's audit, unchanged).
- **Phase 8 Task 6 consequences / follow-ups:** the Ad Copywriter no longer calls Gemini (no Gemini provider
  in the central layer): tenants that relied on their own `accounts.gemini_api_key` or the platform `gemini`
  social-provider key now get OpenAI/Anthropic through `AiManager` (or templates if `AI_PROVIDER` is unset). Those
  key columns/rows are left in place, unused by the copywriter. ~~A Gemini provider is a separate task~~ — Phase 8 Task 8 added it to the central layer (platform key only; `AI_PROVIDER=gemini`). Behaviour
  now requires the `ai` capability, the `meta_ads` module and AI credits (Starter tenants and tenants without
  credits get 403/422 instead of free copy). A network retry of an already-successful generation with the same
  key gets 409 (answers are not stored), charged once.
- **Phase 8 Task 5 decisions for the owner:** AI pricing values are placeholders (1000 tokens/credit, minimum 1,
  missing usage → minimum); every plan still has `included_credits = 0`, so no tenant can use metered AI until
  credits are granted or a plan includes them. Behaviour by design: a provider that bills the vendor but returns a
  malformed answer is NOT charged to the tenant; an abandoned run (process died mid-call) is not charged.
- **Phase 8 Task 4 follow-ups (not done):** ~~Ad Copywriter calls vendors directly~~ — done in Phase 8 Task 6. Journey `prompt`/`agent`/`rag`
  nodes — `prompt`/`agent` done in Phase 8 Task 7 (`rag` still unavailable: no retrieval backend). ~~No Gemini provider in the new layer~~ — done in Phase 8 Task 8.
  ~~Nothing debits credits for AI calls yet~~ — done in Phase 8 Task 5.
- **P5-10 consequences (by design):** a re-seed no longer adds baseline capabilities to an EXISTING
  plan — it cannot tell "never had it" from "an administrator removed it". An environment whose plans
  predate a bundle change must get it through plan management (or an explicit, authorized data
  migration), not a re-seed. Observation, not changed: `PlanManagementService::modify()` with a
  `capabilities` list rewrites every pivot `usage_limit` to NULL (incl. whatsapp_send); nothing in
  `app/` reads that pivot value at runtime today.
- **P5-9 follow-ups (not done):** a busy conversation's deferred message waits for a `journeys`
  worker — up to ~1 min with only the scheduled per-minute `queue:work database --queue=journeys`;
  a continuously running `journeys` worker makes it ~the back-off (1–5 s). Arrival order between a
  deferred message and a newer one is best-effort (as before: the old 100 ms poll had no FIFO
  either — mutual exclusion is what is guaranteed). With pacing on, a group batch is one job per
  send (more queue rows; each slice re-reads the frozen list).
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

### Phase 12 Task 6 — Release pipeline parity, backup/restore verification, migration safety (2026-10-02)

- **Verification-only; no migration, no behaviour/authorization/API-contract change, QR/Baileys untouched.** New read-only ops commands: `ops:verify-restore --database=<throwaway> [--json]` (exit 0/1/2), `ops:check-recovery [--strict] [--skip-database] [--json]` (APP_KEY validity, `RECOVERY_APP_KEY_FINGERPRINT` escrow match, `APP_PREVIOUS_KEYS`, credential presence by name only, runbook headings, encrypted-column decryptability by count), `ops:rehearse-migrations [--steps=32] [--seed] [--json]` (migrate:fresh → seed → rollback → re-migrate → schema symmetry). All refuse any database not named like a throwaway (`App\Support\ThrowawayDatabaseGuard`) and `ops:rehearse-migrations` refuses production.
- `database/migration_risk.php` classifies migrations 111–142 (schema-only 13 / additive 7 / locking 6 / data-changing 6; rollback clean/conditional/data-loss/none); `MigrationSafetyTest` fails if the classification understates what a file does. Runbook: `backend-api/docs/RELEASE_AND_RECOVERY.md` (APP_KEY escrow/recovery, restore verification, provider credential recovery, migration safety). `config/recovery.php` holds the inventory.
- CI (`.github/workflows/ci.yml`, PHP 8.4): backend-sqlite (release-safety group, full suite, rehearsal), backend-mariadb (full suite, rehearsal, mysqldump→restore→verify, 3 probes on `wa_throwaway_test` — the probes' own guard name), frontend (tsc, vitest, build), qr-engine syntax job unchanged. The workflow file was delivered as a download because that path is write-protected for the assistant — copy it into place.
- Tests (`#[Group('release-safety')]`, 91 tests): CriticalRouteAuthorizationSweep, ReleasePipelineTopology, RestoreVerification, RecoveryConfiguration, MigrationSafety, MetaDuplicateDeliveryContract, PublicApiV1Contract. Results: SQLite full suite 3297 passed / 10 skipped (before a RestoreVerification connection-pinning fix); fresh-MariaDB full suite 3307 passed / 1 skipped; rehearsal and the 3 probes passed on MariaDB; frontend tsc clean, vitest 800/800, build OK.
- **Limitations**: the GitHub Actions workflow has not been executed; the restore verifier samples ≤20 values per encrypted column and does not sample journey `enc:v1:` graph secrets; the throwaway-name rule is a refusal rule, not a permission system.

### Public API Key Authorized Server Binding — DONE
- Every `/api/v1/*` key is bound to one authorized server. Enforced centrally in the two shared middlewares (`AuthenticateApiKey`, `ApiAuthMiddleware`) via `ApiKeyBindingService::gate()`, before throttle/idempotency/business logic; controllers are untouched.
- Mechanism: key-creation mints a one-time `wasaas_inst_…` installation credential (sha256-hashed at rest, sent in `X-Client-Installation`) plus an IP policy (`SINGLE_IP` default / `IP_ALLOWLIST` / `NONE`; IPv4, IPv6, CIDR, IPv4-mapped). Credential is a bearer secret, so the IP policy is the second factor. First-request activation is trust-on-first-use under `lockForUpdate`; one live binding per key via nullable slot columns (portable to SQLite/MariaDB).
- Denial: HTTP 403 `{success:false,status:false,code/error_code:"API_CLIENT_NOT_AUTHORIZED",message:"This API key is not authorized for this server."}` — uniform, reveals nothing about the authorized server.
- Server change: buyer files a request (`POST /api/developer/api-keys/{id}/server-change-requests`); old server stays active until a Super Admin approves (`/api/admin/api-access/change-requests/{id}/approve`), which revokes the old binding and issues a pending one; buyer then issues the new credential once. No client-side rebind exists. Super Admin can also revoke/rebind/disable/enable and read security events (`api_key_security_events`; safe identifiers only, never keys/credentials/headers).
- Migration `2026_10_05_100000_create_api_key_server_binding_tables` (additive, has `down()`): `api_key_bindings`, `api_key_change_requests`, `api_key_security_events`, `api_keys.access_disabled_*`. Not run against the real DB.
- Legacy: keys with no binding rows keep working while `config('api_binding.allow_legacy_unbound')` is true; set false to enforce for all.
- Key creation / client regenerate require `acknowledge_server_binding` plus the licence warning (config/api_binding.php `warning`).
- Tests: `ApiKeyServerBindingTest` (26, group release-safety). UI: `ApiAccessPanel` (buyer), `pages/admin/ApiAccessPage` (`/admin/api-access`, super_admin).

### Message Logs "View" action — DONE
- `GET /api/message-logs/{id}` (same `module.guard:message_logs` group as the list; tenant scope = the account `tenant.isolation` resolves, identical to `index()`; out-of-scope id => 404). Returns the full resolved text (`message_body`), `template_code`, sanitised `media` descriptor (never a storage path; URL only if http/https), safe `api_key` {name, prefix}, `message_body_is_complete`.
- New nullable `message_dispatch_logs.message_body` (migration `2026_10_06_100000`, guarded + reversible), written centrally by `MessageDispatchLog::record()` / `recordGroupDispatchQueued()` from the text the dispatchers already pass as the preview (resolved, variables substituted); group-recipient rows copy the parent's. Hidden from the list payload. No dispatcher/send code changed. Rows older than the migration keep only their 160-char preview (flagged in the UI); no backfill.
- Frontend: `MessageLogDetailModal` + "View" button column in `MessageLogsPage`. Tests: `MessageLogDetailTest` (13), `MessageLogsPage.test.tsx` (8).

### Global Templates visible in Agent / Client template lists — DONE
- Global template = `message_templates.account_id IS NULL` (existing field; no schema change). Root cause: `MessageTemplateController::index()` restricted an Agent to own+sub-client rows (global excluded), and `myTemplates()` (client "My Templates") returned `account_id = own` only.
- Rule: Agent index = own OR sub-client OR (account_id NULL AND status approved); client My Templates = own (any status) OR (account_id NULL AND status approved) — same predicate as `approvedFor()`. One query, so search/status/account filters and ordering run over the union. Responses carry derived `is_global`. Super Admin view unchanged.
- Global is read-only for Agents (`assertAgentOwnsTemplate` still 404s every write on a global row; UI shows "Read-only"). Permission/module/subscription middleware and send-path entitlement checks untouched. No rows are copied into tenants. A global stays `pending` until test-fired + approved (existing gate), so it only becomes visible after approval.
- Tests: `GlobalTemplateVisibilityTest` (10); UI: `TemplateManagerPage.agentGlobal.test.tsx`, `MyTemplatesModal.test.tsx`.

### Template approval no longer requires a test-fire; template "View" action — DONE (2026-10-05)
- `MessageTemplateController::approve()` no longer 422s a Super Admin approving a template that has not been test-fired. `POST /admin/templates/{id}/test` still exists (Super Admin only) and still sets `is_super_admin_tested` / `tested_at`, but the flag is informational now. Agent approval (own sub-client `pending_agent_review` rows, forwards to Super Admin) is unchanged. No migration, permission or plan change.
- Frontend `TemplateManagerPage`: Approve is enabled for Super Admin without a test; new read-only **View** button (all viewers who see the row, i.e. Super Admin and Agent) opens `ViewTemplateModal` (body, status, tested flag, code, client, header, language, category, rejection reason, variables). Not yet covered by new tests; run `php artisan test` and `npm test` before deploy.

### Social credentials no longer 500 when APP_KEY differs (new device / restored DB) — DONE (2026-10-05)
- Symptom: after pulling the code onto another machine with the laptop's DB, Social Gateway Settings / Social Accounts failed with "The MAC is invalid." Cause: `social_provider_configs` (client_id, client_secret, webhook_verify_token) and `social_accounts` (access_token, refresh_token) are encrypted with the source machine's `APP_KEY`; a different key cannot decrypt them.
- New cast `App\Casts\EncryptedOrNull` (same as `encrypted`, but an undecryptable value reads as null and logs a warning). Applied to those five columns only. Unreadable secrets now show as "not set" and can be re-entered; unreadable account tokens fail the connection check and prompt a reconnect. Other encrypted columns (mail, payment gateway, WhatsApp Meta token, webhook secrets) are unchanged and would still throw. No migration. Proper fix for a migrated DB remains: copy the source `APP_KEY` into the new `.env` (see `Claude outputs/NEW_DEVICE_ONBOARDING.md`).

### Meta connect no longer depends on the popup's postMessage — DONE (2026-10-05)
- Symptom: Connect Meta Account → Facebook (2-step, "Continue as …") → popup closes → "The connection window was closed before the connection finished. Nothing was connected." Cause (likely): after the popup visits facebook.com its Cross-Origin-Opener-Policy severs `window.opener` / makes `popup.closed` read true, so the callback page's postMessage never reaches the SPA.
- `GET /api/social/oauth/meta/redirect` now also returns `nonce`; new `GET /api/social/oauth/{provider}/result/{nonce}` (same guards as the other social routes, readable only by the account + user that started it) returns `pending` | `success` (+ offered assets) | `error`/`cancelled`. The callback records failed/cancelled outcomes (`social_oauth_outcome:{nonce}`, 10 min). `SocialAccountsPage` polls every 2 s (max 10 min) and applies the result through the same handler as postMessage; the "window closed" message waits 6 s when polling is active. postMessage remains the fast path. No migration.
- Requires a cache store shared by the callback and API requests (`CACHE_STORE=database`/redis, not `array`).

### Send Alert "No template" option — DONE (2026-10-05)
- Template dropdown on Send Alert now has a "No template (write your own message)" option alongside every approved template. Selecting it swaps the Dynamic Variables form for a plain textarea; the recipient picker (individual phone(s)/CSV or Contact Group(s)) and the Media URL field are unchanged and still apply. The empty "no approved templates yet" state also offers this option now, so a tenant with zero templates isn't blocked from sending at all.
- New `POST /api/alerts/send-direct` (`PaymentAlertController::sendDirect`, same `permission:send-messages`/`module.guard:send_alert` group as the rest of `/alerts`). Dispatches through `DirectMessageDispatcher`/`GroupDirectMessageDispatcher` (the same free-text/media path the Developer API and Social Inbox replies already use), not `TemplateMessageDispatcher` — no template, no approval requirement, no `{{variable}}` substitution. `media_url` present ⇒ sent as media with the text as caption; otherwise plain text. No migration.
- **Disclosed limitation:** unlike the template path's Anti-Spam Bulk Dispatch (`sendBulkTemplateMessage`, a paced background queue), a multi-recipient "No template" send fires one synchronous request per phone number from the browser via `Promise.allSettled` — no anti-ban pacing. Fine for a handful of numbers; a large comma-separated/CSV list sent without a template has no delay between sends. Group sends are unaffected (`GroupDirectMessageDispatcher` already queues/paces per recipient via `ProcessGroupDirectMessageJob`). Not yet covered by tests; run `php artisan test` and `npm test` before deploy.

### Developer API "no_template" sentinel for free-text sends — DONE (2026-10-05)
- `MessageTemplate::NO_TEMPLATE_CODE = 'no_template'` (can never collide with a real template_code, which must match `/^[A-Z0-9_]+$/`). Passing `template_code: "no_template"` to the two template_code-based external endpoints — `POST /api/v1/messages/send-template` (`TemplateMessageController::send()`) and `POST /api/v1/send-message` (`sendMessage()`, both recipient_type) — skips the template lookup entirely and requires a new `text` key instead of `variables`; sends via `DirectMessageDispatcher`/`GroupDirectMessageDispatcher`, same as the Send Alert "No template" UI option. A real template_code is completely unaffected — same lookup, same variables, same approval requirement as before. `media_url` optional on both paths; present ⇒ sent as media with `text` as the caption.
- `SendTemplateByCodeRequest` and `SendMessageRequest` both validate `text` as `required_if:template_code,no_template` and skip variable-schema validation for the sentinel.
- Send Alert page's Developer API Documentation panel now renders the real endpoint/headers/sample-payload block for "No template" too (previously a plain "not available" note) — `template_code: "no_template"` + `text` instead of `variables`. No migration.

### Legacy (unbound) API keys no longer unrestricted — DONE
- `api_binding.allow_legacy_unbound` now defaults to **false** (env `API_ALLOW_LEGACY_UNBOUND`; documented as a temporary emergency override that records a `legacy_unbound_override_used` event). A key with no binding row gets 403 `API_SERVER_BINDING_REQUIRED` ("This API key requires server authorization before it can be used.") on `/api/v1/*`; API use never creates or claims a binding.
- Enrollment is owner-authenticated only: Developer > API Access > "Register authorized server" (`POST /api/developer/api-keys/{id}/server-binding`, Sanctum, tenant-scoped, ack required) returns the one-time installation credential; the same key then works from the authorized IP + credential. Super Admin can rebind. Panel/Profile show "Server authorization required". No key regeneration needed.
- Tests: legacy suite in `ApiKeyServerBindingTest`; contract suites that use bare factory keys opt into the override via `Tests\Concerns\AllowsUnboundApiKeys`. **Deploy note:** every existing customer key is refused until its owner enrolls — notify customers before rollout.

### WhatsApp QR login: phone-number option + durable sessions — DONE (2026-10-05)

- **Why**: (a) owner asked for a second login method — a WhatsApp number plus an OTP-style code, alongside QR; (b) QR-engine sessions lived in `qr-engine-service/sessions/<id>/` on the pod's disk, so every redeploy or pod recreation disconnected every tenant (the 2026-10-05 boot-resume only helped when the disk survived).
- **Pairing code**: Baileys 7.0.0-rc14 `requestPairingCode(phone)`. Requested on the first `connecting`/`qr` event after the socket starts (retried on later events if WhatsApp is not ready), at most once per socket. The code is broadcast as `pairing_code`. A replacement socket is created when a number is submitted while a QR socket waits; the old socket's listeners are removed first, then it ends and its pending writes are flushed.
- **Session storage**: `authStore.js` (`loadAuthState` / `drainAuthState` / `clearAuthState` / `listPairedAccountIds`). Backend store: `keys.set` is awaited (Baileys treats a key as used only after `set` resolves), `creds.update` is saved, and writes are chained per account with 3 retries and backoff. A failure after the retries is logged as `AUTH_STATE_PERSIST_FAILED` and not stored: the affected key is then lost, which can force a re-pair. Logout (`clearAuthState`) closes the handle first, so a late write cannot bring deleted keys back. A reconnect or replacement waits for the old handle to drain before loading, so it never reads older state than the last save.
- **Files touched**: `qr-engine-service/src/services/{authStore,sessionManager,backendClient}.js`, `src/server.js`, `test/authStore.test.js`; `backend-api` `WhatsAppController` (start-session + `forwardToQrEngine` extra payload), `Internal/WhatsAppAuthStateController`, `Models/WhatsAppEngineAuthState`, the 143rd migration, `routes/api.php`, `config/recovery.php`, `tests/Feature/WhatsAppEngineAuthStateTest` (14), `CriticalRouteAuthorizationSweepTest` (4 routes allowlisted, internal.secret), `RecoveryConfigurationTest` (guard recognises `EncryptedOrNull`); `frontend-app` `QRScannerModal.tsx` (tabs: QR default / phone number; tenant connections only), `whatsappService.startSession(accountId, phoneNumber)`, `types/whatsapp.ts`.
- **Limitations / follow-ups (not done)**: (1) Pairing-code and pod-restart behaviour is NOT yet verified against a real WhatsApp account — only the storage layer and the HTTP contract are tested. (2) No Kubernetes manifest in the repo; replica count and `Recreate` strategy must be set in the deployment. (3) The Super Admin self-device is QR-only. (4) The phone tab has no automated frontend test (vitest is not installed in this environment). (5) If the backend is down at boot, no account resumes until the next boot or a manual Connect — there is no retry loop for boot-time resume. (6) Credential writes that fail all retries are dropped with a log line (see the Why above); no dead-letter store. (7) Import of a local folder runs only while the backend has no `creds.json` for that account.

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

##### Collection lifecycle (Phase 11 Task 5)

- **States**: derived from payment rows on every read — `open | partially_paid | paid | overdue` — plus ONE stored fact, cancellation: `cancelled_at`, `cancelled_by_user_id`, `cancellation_reason` on `collection_charge_assignments`. Status, paid and outstanding are still never stored, so nothing can drift. A cancelled charge is listed but reports `status = cancelled`, outstanding `0.00`, and is excluded from ledger totals and `summary.count`.
- **Transitions** (all in `BillingCollectionService`, under the same `SELECT … FOR UPDATE` assignment-row lock as `recordPayment` / `updateAssignment`; the paid total is re-read under the lock): `cancelAssignment` only while NO payment exists (payments are append-only and refunds are not supported → 422 on `status`), not twice; `reinstateAssignment` only when cancelled and only if no active duplicate (same contact, charge item, due date) exists — it takes the contact lock first, then the assignment lock, the same order as `assign()`. A cancelled charge refuses payment (422 `amount`) and update (422 `status`); `assign()` ignores cancelled rows when checking for duplicates, so a voided charge can be assigned again.
- **Immutability**: `cancelled_at`, `cancelled_by_user_id`, `cancellation_reason` join `charge_item_id`, `contact_id`, `account_id`, `status`, `amount_paid`, `outstanding` in `IMMUTABLE_ON_UPDATE`; `PUT …/fees/{fee}` rejects them with 422. Payment rows and `charge_item_id` stay immutable; Task 4 invariants unchanged.
- **Education adapter**: `EducationFeeService::cancelFee` / `reinstateFee` only resolve student → contact and pin scope `education`. Routes (inside `industry.guard:education,fees`): `POST /api/industry/education/students/{id}/fees/{fee}/cancel` (body: optional `reason` ≤255) and `…/reinstate` (no body). Any other body key → 422 keyed by that field ("This field is not supported here."). Ledger `status` filter now accepts `cancelled`. A test asserts the core's executable code mentions no education/student/school.
- **Authorization**: unchanged chain — `tenant.isolation` → `subscription.guard` → `industry.guard:education,fees` → `IndustryAuthorizer` (`manage-education` + active subscription for writes; `industry_education` AND `billing_collections` capabilities). Foreign account/student/assignment ids → 404; Super Admin needs explicit `account_id`; Agent reaches only itself/its clients. Origin is never an authorization input. **No capability, permission, plan or seeder change** (asserted: no seeded plan bundles `billing_collections`).
- **Concurrency**: probe scenarios (g) payments racing cancel and (h) cancel/reinstate/pay interleaved were added to `tests/Probes/collections_payment_concurrency_probe.php` (invariant: never cancelled with payments; paid ≤ amount due). Negative control: removing the lock from `cancelAssignment` makes 8/51 checks fail, including a real "cancelled with 30.00 paid" state; lock restored.
- **Frontend (minimal)**: `StudentFeeStatus` gains `cancelled`; label/style in `feeUtils.ts`; "Record payment" hidden for cancelled rows. There is NO cancel/reinstate UI yet (API only).
- **Limitations / follow-ups**: no refunds or payment reversal (so a charge with any payment can never be cancelled); no cancel/reinstate UI; strict unknown-field rejection applies to the new endpoints and to the lifecycle fields on PUT, NOT to assign/pay bodies (a Task 4 test relies on those ignoring balance fields — tightening them is a separate change); the task title mentions "Customer Allocation" but its body specified no allocation behaviour, so none was built — specify it (e.g. allocating one payment across several charges) before it is; the deploy still needs migration `2026_10_01_110200`.

