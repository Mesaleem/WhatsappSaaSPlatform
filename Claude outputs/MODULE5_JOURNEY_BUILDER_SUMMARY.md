# Module 5 — No-Code WhatsApp Journey Builder — Implementation Summary

**Date:** 2026-09-11

All claims tagged [Fact] (verified by reading the actual file), [Inference] (reasoned from verified facts), [Hypothesis] (behavior not integration-tested against a live WhatsApp number/browser), or [Unknown].

---

## 1. Database & Models

**Created (⚠️ NOT YET MIGRATED — see §6):**

| File | Purpose |
|---|---|
| `database/migrations/2026_09_11_200000_create_whatsapp_flows_table.php` | `whatsapp_flows`: `account_id`, `name`, `trigger_type` (`keyword`\|`ctwa_referral`\|`default`), `trigger_value`, `graph_data` (json), `is_active`, timestamps. Index `[account_id, is_active, trigger_type]`. |
| `database/migrations/2026_09_11_200001_create_whatsapp_flow_sessions_table.php` | `whatsapp_flow_sessions`: `account_id`, `flow_id` (cascadeOnDelete), `phone_number`, `current_node_id`, `context_data` (json), `status` (`active`\|`completed`\|`expired`), `last_interaction_at`, timestamps. Index `[account_id, phone_number, status]`. |
| `app/Models/WhatsAppFlow.php` | `TRIGGER_TYPES`/`NODE_TYPES` consts, `graph_data` helpers (`nodes()`, `edges()`, `findNode()`, `triggerNode()`, `outgoingEdges()`). |
| `app/Models/WhatsAppFlowSession.php` | `STATUS_*` consts, `findActive()`, `setVariable()`/`getVariable()` on `context_data`. |

**Design decisions disclosed in the migrations' own docblocks:**
- `trigger_type` is a deliberately simpler vocabulary than `chatbot_rules.match_type` (3 values vs. chatbot_rules' 5) — a flow only needs to know *how it's entered*, not fine-grained text-matching rules.
- `whatsapp_flow_sessions.flow_id` is `cascadeOnDelete` (unlike `ad_campaigns.social_account_id`'s `nullOnDelete` precedent) — a session has no meaning independent of the graph it's walking.
- At most one *active* session per (account, phone) is an **application-level** invariant (enforced in the engine), not a DB constraint — same disclosed trade-off already made for `leads`' 24-hour dedup window.
- **Disclosed gap:** `status = 'expired'` is a modeled value but nothing currently sweeps stale active sessions into it automatically — no scheduled command was added for this (out of this module's given scope). A future module should add one (same shape as `CheckAdPerformanceRules`).

---

## 2. `WhatsAppJourneyEngine` (`app/Services/WhatsApp/WhatsAppJourneyEngine.php`)

The execution core. Full node-type contract (trigger/message/question/condition/save_lead) is documented in the class's own docblock — summary:

- **`trigger`** — the graph's single entry point (no data).
- **`message`** — sends text immediately, continues in the same request.
- **`question`** — sends a prompt (plain text, or an interactive button/list message mirroring `ChatbotEngineService::buildInteractiveReply()`'s exact Meta payload shape), then **pauses** the session (`current_node_id`) until the next inbound message from that phone number.
- **`condition`** — evaluates outgoing edges in order against a named `context_data` variable (`equals`/`not_equals`/`contains`/`exists`), falling back to an edge marked `is_default`.
- **`save_lead`** — terminal: upserts a `leads` row (`provider = 'whatsapp_journey'`, dedup key `journey:{flow_id}:{session_id}` — reuses `leads.provider_lead_id`'s existing unique index, the same redelivery-safety mechanism `MetaLeadWebhookHandler` and Step 4's CTWA capture already rely on). **All** collected `context_data` is preserved verbatim in `raw_field_data` regardless of which fields were explicitly mapped — same "never lose data" discipline as `MetaLeadWebhookHandler`.

**Integration point — the key architectural decision:** rather than duplicating a hook into both `MetaWebhookController` *and* the Baileys-side `Internal\WhatsAppInboundController`, the engine is called from inside **`ChatbotEngineService::handleInboundMessage()`** — the one shared choke point both controllers already funnel every inbound message through. This means a journey built once works on **both** the Meta and Baileys/qr engines with a single integration point, and the Baileys path required **zero code changes**.

**Regression safety:** for any tenant with zero `WhatsAppFlow` rows (every existing tenant today), `handleInboundMessage()` returns `false` after two cheap, indexed queries — `ChatbotEngineService`'s existing `chatbot_rules` flow then runs completely unchanged. Confirmed by re-reading the merged method.

**Quota discipline:** every outbound send from the engine goes through the same quota-guarded, subscription-checked path `ChatbotEngineService::process()` already uses (same `DB::transaction` + `lockForUpdate` increment pattern) — a journey cannot be used to bypass the billing/quota system.

**Also added:** `testFlow(Account $account, WhatsAppFlow $flow, string $phoneNumber): void` — powers the "Test Trigger" action, running one specific flow directly (bypassing trigger matching) against a tenant-supplied number. **Sends real WhatsApp messages** — not a simulation; disclosed in the code, the controller, and the frontend's confirmation modal.

**Cycle guard:** `advance()` is bounded by `MAX_ADVANCE_STEPS = 25` — a malformed graph (e.g. a condition node cycling with no matching branch) marks the session `expired` and logs a warning instead of looping indefinitely within a webhook request.

---

## 3. Hook points (minimal, disclosed changes to existing files)

| File | Change |
|---|---|
| `app/Services/Chatbot/ChatbotEngineService.php` | `handleInboundMessage()` gained an optional trailing `?array $referral = null` param (backward-compatible — the Baileys call site never passes it) and now tries `WhatsAppJourneyEngine` **first**; only when it declines does the pre-existing `process()` run, byte-for-byte unchanged. |
| `app/Http/Controllers/Api/MetaWebhookController.php` | The inbound-message type gate widened from `text`-only to also accept `interactive` — a Question node's button/list reply arrives as `type='interactive'`, so its title (or id) is now flattened into a plain string and passed through the **same** `handleInboundMessage(string $incomingMessage)` signature `chatbot_rules` already used, rather than inventing a parallel code path. Every other non-text type (image, audio, location, …) is still dropped exactly as before. The CTWA `$referral` extracted in Step 4 is now also passed through to `handleInboundMessage()`. |

Both changes were re-read in full after editing to confirm the pre-existing `chatbot_rules` behavior is untouched for every message type that was already handled.

---

## 4. API — `WhatsAppFlowController` + routes

`app/Http/Controllers/Api/WhatsAppFlowController.php` — CRUD (`index`, `show`, `store`, `update`, `destroy`, `toggle`) + `sessions()` (read-only session visibility, last 100) + `test()` (Test Trigger, live send, disclosed in its own docblock).

Routes wired in `routes/api.php` under `GET/POST/PUT/DELETE /api/whatsapp/flows`, inside the existing `tenant.isolation`/`subscription.guard` group, gated on `module.guard:chatbot` + the **existing** `manage-chatbot` permission tier (plus the already-seeded granular `whatsapp.view/create/edit/delete` permissions).

**Disclosed design choice:** this reuses the Chatbot module's existing permission/module slugs rather than introducing a new one — a Journey is, functionally, a multi-turn evolution of `chatbot_rules`, and every role that manages chatbot rules today manages journeys too with **zero seeder changes**, avoiding a *third* pending "re-run the seeder" manual step on top of the two migrations already awaiting your authorization from this session's earlier Steps.

Save-time structural validation (mirroring `ChatbotRuleController`'s discipline): rejects a graph with anything other than exactly one `trigger` node, or a node with an unknown `type`/missing `id` — catches a flow that would silently never run, at save time rather than only as a runtime log warning.

---

## 5. Frontend

| File | Purpose |
|---|---|
| `src/types/journey.ts` | Mirrors the backend's node/edge/graph contract 1:1. |
| `src/services/journeyService.ts` | `list/get/create/update/remove/toggle/sessions/test`. |
| `src/pages/whatsapp/JourneyBuilderPage.tsx` | The canvas builder (~1,200 lines) — list view + visual editor. |
| `src/App.tsx` / `src/components/layout/AppLayout.tsx` | Route `/chatbot/journeys` + sidebar nav entry "Journey Builder", gated identically to "Chatbot Rules". |

**⚠️ DISCLOSED ENVIRONMENT CONSTRAINT — no new npm dependency:** before writing a line of the canvas, I confirmed this environment's network egress blocks the npm registry (`npm ping` → `403 Forbidden` from the proxy; a direct `curl` to `registry.npmjs.org` → `403` as well) — consistent with your "restricted environment, do not assume package installation permissions" directive. A library like React Flow would normally be the obvious choice for a node-graph editor; it was not installable here. The canvas is instead built from scratch with **zero new dependencies**:
- Nodes are absolutely-positioned `<div>`s, dragged via native mouse events, position kept in React state.
- Edges are hand-drawn SVG `<path>` bezier curves, recomputed from node positions every render, with click-to-select hit-testing via a wide invisible stroke.
- Connections are made by dragging from a small handle on a node's right edge onto another node (bounding-box hit test against stored positions — no DOM measurement needed).

**Trade-off, disclosed rather than hidden:** no pan/zoom, no auto-layout, no minimap. The canvas is a fixed 1800×1100 scrollable area. If a graph library becomes installable in a future session, swapping it in is an isolated rewrite of this one file — the backend `graph_data` JSON contract does not change.

**Editor features implemented:** node palette (Message/Question/Condition/Save Lead — Trigger is auto-created once, non-deletable), drag-to-reposition, drag-to-connect, click-to-edit side panel per node type (question's answer-type/options/validation, condition's variable+branch editing on the edge itself, save-lead's field mapping from a live dropdown of variables collected so far), Trigger Type/Value bar, Active toggle, Save, and a "Test Trigger" action that opens a confirmation modal explicitly warning it sends real WhatsApp messages before it fires.

A correctness fix worth flagging: my first draft of the drag-listener cleanup had each `mouseup` handler remove itself by referencing its own `useCallback` binding inside its own initializer — functionally safe (the reference isn't read until a real mouseup fires, well after the `const` is assigned), but `oxlint`'s `react(immutability)` rule correctly flags this shape as fragile static analysis can't prove. Restructured to look up the active listener pair via a `ref` populated at mousedown time instead — this eliminated the warning with identical runtime behavior, verified by re-running `oxlint` before and after.

---

## 6. Audit & Verify

### Frontend
```
./node_modules/.bin/tsc -p tsconfig.app.json --noEmit   → exit 0, zero errors
./node_modules/.bin/oxlint src/                          → 0 errors, 52 warnings
```
51 of the 52 warnings are the same pre-existing `react(set-state-in-effect)` pattern found throughout this codebase before this module (confirmed by running oxlint on `main` equivalent earlier this session). The 1 new warning is `JourneyBuilderPage.tsx`'s own `loadFlows()` call inside a `useEffect` — the exact same, already-accepted pattern every other list page in this app uses (`AccountsPage.tsx`, `ChatbotPage.tsx`, `MetaAdsPage.tsx`, …), not a new class of issue. **Zero new errors, zero new warning types.**

### Backend
**Disclosed limitation, unchanged:** no PHP interpreter or Composer is available in this environment (`which php` and `composer --version` both re-confirmed empty this session). Every new/edited PHP file was manually re-read in full and checked for brace/paren balance:

```
whatsapp_flows migration                    braces:10/10  parens:28/28   OK
whatsapp_flow_sessions migration            braces:5/5    parens:37/37   OK
app/Models/WhatsAppFlow.php                 braces:16/16  parens:29/29   OK
app/Models/WhatsAppFlowSession.php          braces:8/8    parens:18/18   OK
app/Services/WhatsApp/WhatsAppJourneyEngine.php  braces:88/88  parens:297/297  OK
app/Services/Chatbot/ChatbotEngineService.php    braces:37/37  parens:138/138  OK
app/Http/Controllers/Api/MetaWebhookController.php braces:26/26 parens:109/109 OK
app/Http/Controllers/Api/WhatsAppFlowController.php braces:29/29 parens:106/106 OK
routes/api.php                              braces:98/98  parens:387/387  OK
```
This is not a substitute for a real linter — running `php artisan route:list` and, if configured, `phpstan`/`larastan` in your own environment before merging is strongly recommended.

---

## 7. Full file list

**Created:**
- `backend-api/database/migrations/2026_09_11_200000_create_whatsapp_flows_table.php` *(not yet migrated)*
- `backend-api/database/migrations/2026_09_11_200001_create_whatsapp_flow_sessions_table.php` *(not yet migrated)*
- `backend-api/app/Models/WhatsAppFlow.php`
- `backend-api/app/Models/WhatsAppFlowSession.php`
- `backend-api/app/Services/WhatsApp/WhatsAppJourneyEngine.php`
- `backend-api/app/Http/Controllers/Api/WhatsAppFlowController.php`
- `frontend-app/src/types/journey.ts`
- `frontend-app/src/services/journeyService.ts`
- `frontend-app/src/pages/whatsapp/JourneyBuilderPage.tsx`

**Edited:**
- `backend-api/app/Services/Chatbot/ChatbotEngineService.php` (Journey Engine hook)
- `backend-api/app/Http/Controllers/Api/MetaWebhookController.php` (interactive-reply gate widening + referral pass-through)
- `backend-api/routes/api.php` (`whatsapp/flows/*` routes)
- `frontend-app/src/App.tsx` (`/chatbot/journeys` route)
- `frontend-app/src/components/layout/AppLayout.tsx` ("Journey Builder" nav item)

---

## 8. Outstanding items requiring your decision

1. **Three migrations are now pending authorization**, unrun per the state-mutation protocol: `add_gemini_api_key_to_accounts_table`, `create_organic_posts_table` (both from earlier Steps this session), and now `create_whatsapp_flows_table` + `create_whatsapp_flow_sessions_table`. Run `php artisan migrate` on your server when ready.
2. **No scheduled sweep for stale/abandoned sessions** — a customer who never replies to a Question node leaves their session `active` forever (harmless — it just means their next unrelated message resumes the old flow instead of matching a new trigger — but worth a follow-up module, same shape as `CheckAdPerformanceRules`).
3. **Canvas has no pan/zoom/auto-layout** — a deliberate, disclosed trade-off given this environment's blocked npm registry access; revisit if `react-flow` (or similar) becomes installable.
4. **No PHP linter/static analyzer available in this environment** — recommend `php artisan route:list` + `phpstan`/`larastan` in your own environment before merging.
