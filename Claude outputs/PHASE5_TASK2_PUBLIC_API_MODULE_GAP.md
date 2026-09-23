# Phase 5 — Task 2 §4: Public API Authorization Gap — Audit Only

**Status: analysis only. No production authorization behaviour was changed in this task.**

Every claim below is tagged `[Fact]` (read directly from this repository) or `[Inference]`.

---

## 1. What actually gates what today

| Route | Auth | Permission | Module guard |
|---|---|---|---|
| `POST /api/alerts/send-template` | `auth:sanctum` | `permission:send-messages` | **`module.guard:send_alert`** |
| `POST /api/alerts/send-template-bulk` | `auth:sanctum` | `permission:send-messages` | **`module.guard:send_alert`** |
| `POST /api/payment-alerts/send` | `auth:sanctum` | `permission:send-messages` | **`module.guard:send_alert`** |
| `POST /api/v1/messages/send-template` | `auth.apikey` | — | **none** |
| `POST /api/v1/messages/send-payment-alert` | `auth.apikey` | — | **none** |
| `POST /api/v1/send-message` | `auth.apikey` | — | **none** |
| `POST /api/v1/whatsapp/messages/send` | `auth.apisecret` | — | **none** |
| `POST /api/v1/whatsapp/groups/create` | `auth.apisecret` | — | none at route level; `hasModuleEnabled('contact_groups')` in the controller |
| `GET/POST /api/developer/*` (key management UI) | `auth:sanctum` | `permission:manage-developer-settings` | **`module.guard:developer_api`** |

`[Fact]` `routes/api.php:126`, `:148`, `:318`, `:537`.

`[Fact]` `module.guard` is `EnsureModuleEnabledMiddleware`, which resolves the module from `$request->user()->account`. There is no Laravel `User` on an API-key request, so this middleware **cannot** be applied to the `/v1/*` groups as written — the gap is structural, not an oversight of a one-word route change.

`[Fact]` The two group dispatchers work around exactly that by calling `$account->hasModuleEnabled('contact_groups')` in PHP (`GroupMessageDispatcher:79`, `GroupDirectMessageDispatcher:51`, `V1\GroupController:46`). So the pattern for gating an API-key route already exists in this codebase — it just was never applied to individual sends.

---

## 2. Answers to the five questions

### 2.1 Which entitlement/capability *should* conceptually control Public API sending?

**`whatsapp_send`.** `[Fact]` It is a seeded `Capability` (`Phase1FoundationSeeder`, category `whatsapp`), it is the capability every message-bearing `Plan` carries (`starter`, `growth`, `business`), and `provider_capabilities` already states it is supported on both `qr` and `meta`. It is the only row in the catalog whose meaning is "this tenant may send WhatsApp messages."

`send_alert` is *not* the right answer conceptually — see 2.2.

### 2.2 Is `send_alert` actually the correct capability?

**No.** `[Fact]` `send_alert` is a member of `Account::MODULES`, the **UI navigation/feature-checklist** list (`dashboard`, `whatsapp_setup`, `send_alert`, `analytics`, `chatbot`, `billing`, `developer_api`, `team_management`, `notifications`, …). `[Fact]` `BackfillMessageLogsModuleAccess` treats it as one of five "WhatsApp suite" **screens** (`whatsapp_setup`, `send_alert`, `chatbot`, `templates`, `device_settings`).

So `send_alert` means *"this tenant can see the Send Alert page"*, not *"this tenant may send messages."* `[Inference]` Reusing it as the Public API's authorization would conflate a nav-visibility flag with a messaging right, and would produce the absurd result that hiding a dashboard page silently disables a partner's server-to-server integration.

`[Fact]` `allowed_modules` is a JSON column where **`null` means every module is enabled** (`Account::effectiveModules()`, the same "null = unrestricted" convention as `max_users_limit`). Most tenants therefore have it null and are unaffected by any module rule either way.

### 2.3 Is `developer_api` only for key management, or also for execution?

**Key management only, today.** `[Fact]` `developer_api` appears in exactly one guard: `routes/api.php:318`, on the `/api/developer/*` prefix (list/create/revoke keys, webhook subscriptions, delivery logs). No `/v1/*` route references it.

`[Fact]` The consequence is concrete and testable: disabling `developer_api` removes the tenant's ability to *manage* keys while every key they already hold keeps working at full power. `[Inference]` That is almost certainly not the intent an operator forms when they toggle that module off — they expect "turn off the API", not "freeze the key list".

### 2.4 Would applying a module guard be a breaking contract change?

**Yes — and worse for `whatsapp_send` than for `send_alert`.** Three separate reasons, in increasing severity:

1. `[Fact]` **New status code on existing routes.** `/v1/*` today returns only `401`/`402`/`403`/`404`/`409`/`422`/`429`. `EnsureModuleEnabledMiddleware` returns `403 MODULE_DISABLED`. A partner integration currently receiving `200` would begin receiving `403` with an error code it has never seen.

2. `[Fact]` **`send_alert` is off for real tenants.** Any account whose `allowed_modules` was explicitly narrowed (a deliberate, supported Super-Admin action) and which also holds a live API key would be cut off with no migration window.

3. `[Fact] — the decisive one.` **`whatsapp_send` is not held by every sending tenant.** Entitlements are granted in exactly one place: `InvoiceCreditService::grantPlanEntitlements()`, called from `markPaidAndCreditQuota()` — i.e. **only on a paid invoice through checkout**. `AccountController::store()` creates a `Subscription` directly (`AccountController:442`) and grants **no** `AccountEntitlement` at all. So a Super-Admin-provisioned tenant has a working subscription, sends successfully today, and holds zero entitlement rows. Gating on `whatsapp_send` right now would **break every hand-provisioned account**, which is the opposite of a safe hardening.

`[Inference]` This is the single most important finding in §4: the entitlement table is not yet a complete picture of who may send, so it cannot become an authorization gate until it is backfilled.

### 2.5 Recommended authorization model for Task 3

A three-step sequence. Steps 1 and 2 are prerequisites; **step 3 must not ship before them.**

**Step 1 — Make the entitlement table complete (no enforcement).**
Grant `whatsapp_send` wherever a sending subscription exists but the entitlement does not: at `AccountController::store()`/subscription-update alongside the existing `ProviderCapabilityService::supports()` check, plus a one-off idempotent backfill command for existing accounts. Purely additive — nothing reads it as a gate yet. Ship, observe, confirm the counts of "accounts with a subscription" and "accounts with `whatsapp_send`" converge.

**Step 2 — Observe-only enforcement.**
Add the check to the `/v1/*` send paths in the same in-PHP style the group dispatchers already use (`AccessControlService::canTenant($account, 'whatsapp_send')`), but **log a warning instead of returning 403**. Run it for a full billing period. If the log is empty, step 1 is complete and step 3 is safe. If it is not, step 1 is not done.

**Step 3 — Enforce, deliberately and announced.**
Return `403 { success: false, error_code: 'CAPABILITY_NOT_GRANTED' }`. A **new** error code, not `MODULE_DISABLED`: the two conditions are different and a partner should be able to tell them apart. Document it as a dated contract change.

**Explicitly recommended against:**

- Do **not** gate `/v1/*` on `send_alert`. It is a nav flag (2.2).
- Do **not** repurpose `developer_api` as an execution gate without first deciding whether it means "may manage keys" or "may use the API". If the latter is wanted, that is its own announced change, separate from `whatsapp_send`.
- Do **not** try to attach `module.guard` middleware to the `/v1/*` groups. It needs a `User` that does not exist there.

`[Inference]` The end state worth aiming for is one sentence: **a module controls what a human sees; a capability controls what an account may do; a provider capability controls what an engine can physically do.** Today the first is doing part of the second's job on the dashboard, and nothing is doing it on the Public API.

---

## 3. Relationship summary

```
allowed_modules  (JSON on accounts, null = all)
    -> module.guard / hasModuleEnabled()
    -> gates UI-backed Sanctum routes ONLY          ... "what a human sees"

Capability (seeded rows) + AccountEntitlement (per-account grants)
    -> AccessControlService::canTenant()
    -> read at login and at admin grant time; NEVER at send time   ... "what an account may do"  [not yet enforced]

Provider + ProviderCapability
    -> ProviderCapabilityService::supports() / supportsNativeWhatsAppGroups()
    -> as of Phase 5 Task 2, gates Native WhatsApp Groups          ... "what an engine can do"

API key (api_keys)
    -> AuthenticateApiKey / ApiAuthMiddleware
    -> authenticates and resolves the tenant; carries NO authorization beyond that
```

`[Fact]` The `api_engine_type` request attribute set by `ApiAuthMiddleware:96` is read by nothing in the codebase. It is a natural place to hang a future capability decision, or it should be removed.
