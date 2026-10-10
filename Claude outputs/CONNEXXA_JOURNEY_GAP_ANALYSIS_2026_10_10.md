# Connexxa Journey Builder — Gap Analysis vs wa-saas-platform

**Date:** 2026-10-10
**Scope:** Journey/Flow builder (`WhatsAppFlow` / `JourneyNodeCatalog` / `nodeRegistry.tsx`) only.
**Method:** Live, read-only audit of the production ConnexxaIQ account (browser, authenticated session) —
full field-level pass over all 27 Connexxa node types, plus live inspection of real production journeys
(HDFC English Journey + HDFC English Buy Policy Journey, Labdhi Insurance, HRMS - Simple Logic) to see how
nodes are actually wired and how variables actually flow, not just what the config forms offer. Cross-referenced
against this repo's `WhatsAppFlow.php`, `JourneyNodeCatalog.php`, `JourneyActionConfig.php`, and
`frontend-app/src/journey/nodeRegistry.tsx` as of this date.

All claims tagged [Fact] (verified by reading the actual file/UI), [Inference] (reasoned from verified facts),
[Hypothesis] (not verified against a live run), or [Unknown].

---

## 1. Where wa-saas already has parity or is ahead

- [Fact] The 27-node **palette** (persistable types) matches almost exactly: `WhatsAppFlow::PALETTE_NODE_TYPES`
  lists the same 27 Connexxa node names, plus one extra — `classifier` (LLM intent routing, distinct from
  `conditional`'s rule-based branching). Connexxa has no classifier-style node; this is a wa-saas-only addition.
- [Fact] **Delay node**: wa-saas's delay is parked on the real scheduler (`journeys:resume-due`, minutes/hours/days,
  non-blocking) — strictly more capable than Connexxa's, which is hard-capped at **10 seconds** and is pure UX
  pacing (confirmed in-product: "Add a delay of up to 10 seconds between messages"). **No gap here — wa-saas wins.**
- [Fact] Runtime-executable today (`JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES`): `trigger, message, question,
  condition, save_lead, delay, conditional, text, image, video, document, audio, prompt, agent, rag, template,
  catalog, product, flow` — 19 of 27+1 types actually run messages/AI/commerce/Meta-Flow today, not just
  persist as drafts. This is a solid base; Connexxa's advantage is concentrated in a few specific types below.

## 2. The central architectural gap: the variable system

This is the single highest-leverage gap — almost every other gap (Code, API, Payment, Journey-to-Journey) is
downstream of it.

**Connexxa [Fact], confirmed from live node code in 3 different production journeys:**
- Two scopes, same syntax everywhere: `var_local.*` (journey-declared, Local/Global) and `var_system.*`
  (always-available built-ins: `userChatId`, `channel`, `event_type`, `conversationid`, `ai_intent`,
  `message_metadata`, `conversationlanguage`, `ChannelId`, `fbChannelId`, `IntegrationType`, etc. — 17 total).
- `{{var_local.x}}` / `{{var_system.x}}` interpolation in any text field, **including nested paths**:
  `{{var_local.buyPolicyJson.pan}}`, `{{var_local.buyPolicyJson.consent}}` — object-type variables, not just
  flat strings.
- A real sandboxed **TypeScript/JavaScript** engine in the Code node (modal literally says "Write
  TypeScript/JavaScript code"), not a restricted expression language. Verified executable syntax across real
  journeys:
  - Dot-path assignment: `var_local.customerName = var_local.buyPolicyJson.customer;`
  - Array indexing: `var_local.token = var_local.TokenResponse.data[0].Table[0].Token;`
  - Bracket notation for keys with spaces: `var_local.isRM = var_local.EmpDetailsResponse.data[0].rows[0]["IS REPORTING MANAGER"];`
  - Built-ins: `new Date()` + `.getFullYear()/.getMonth()/.getDate()`, `String(var_system.userChatId).substring(2)`.
- Universal **"Store Response In"** pattern: API, External URL/CTA, Document, Payment, Email, and List's
  "Store In Variable" all write their output straight into a named local variable, which later nodes read
  either flattened (via a Code-node destructure step) or straight through the nested path.
- Conditional node's Variable field takes a **variable-picker chip** (not `{{}}` text) — e.g.
  `IF isCtaSucceeded Equal to true (Boolean)`.
- A categorized "Variables" dropdown (Local / System submenus) is available directly inside every rich-text
  field's toolbar, so builders never have to remember variable names.

**wa-saas [Fact], from `JourneyActionConfig::renderText()`:**
```php
preg_replace_callback('/\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}/', ... $context[$m[1]] ?? null ...)
```
- **Flat key lookup only.** The regex allows a literal dot *inside the variable name string* (e.g. a variable
  could be named `foo.bar`), but there is **no nested property traversal** — `$context['buyPolicyJson']['customer']`
  is not reachable via `{{buyPolicyJson.customer}}`; only a flat `$context['buyPolicyJson.customer']` key would
  resolve, and nothing currently writes keys in that shape.
- **No array indexing, no bracket notation, no JS sandbox at all.** The Code node's config schema has exactly
  one field (`code`, textarea) with the help text *"Stored only. This is never evaluated in the browser."* — i.e.
  `code` is not even in `RUNTIME_EXECUTABLE_TYPES`. There is no execution engine for it yet on the backend.
- **No `var_system` scope.** `$context` = `WhatsAppFlowSession::context_data`, populated only by `question` node
  answers (`setVariable()`/`getVariable()`) — single flat associative array, no built-in platform variables
  (chat id, channel, conversation id, etc.) exposed to journey authors at all today.
- **No universal "Store Response In."** The `api` node has a `responseMapping` keyvalue field
  (`variable = json.path`, per-field mapping) instead of Connexxa's simpler "dump whole response into one
  object variable, then Code-node destructure" — functionally narrower and, since `api` isn't runtime-executable
  yet anyway, currently theoretical.

**[Inference] Recommended target design**, closest to Connexxa while fitting Laravel idiom:
1. Extend `WhatsAppFlowSession::context_data` (or a new `variables` json column) to hold **nested values**, not
   just flat scalars.
2. Rewrite `renderText()`'s regex to support dot-paths *and* array indices — e.g. match
   `\{\{\s*([A-Za-z0-9_$]+(?:\.[A-Za-z0-9_$]+|\[\d+\])*)\s*\}\}` and resolve with `Arr::get()`-style traversal
   (with bracket-notation-with-spaces support deferred unless a real use case needs it).
3. Introduce a `var_system.*` namespace resolved at render time from the session/account context (phone,
   channel, account id, inbound event id, etc.) — additive, breaks nothing existing.
4. Build the actual Code-node sandbox. This is the biggest single engineering lift in this whole list — needs
   an actual JS/TS evaluator (`V8Js`, a Node.js microservice called over HTTP, or a constrained PHP expression
   evaluator that only covers the patterns actually seen in production: property assignment, array/bracket
   access, `Date`, `String`). **Recommend scoping v1 to exactly the patterns evidenced above** rather than a
   general-purpose sandbox — it covers 100% of what was observed across 3 real Connexxa production journeys.

## 3. Node-by-node gaps (beyond the variable system)

| Node | Connexxa [Fact] | wa-saas [Fact] | Gap |
|---|---|---|---|
| **Conditional** | Multiple condition **groups** — IF, any number of "ELSE IF" groups (each with its own AND/OR conjunctions), implicit ELSE. One handle per group. | `conditions[]` + single `match: all\|any`, exactly **two** handles (`CONDITIONAL_TRUE_HANDLE`/`FALSE`). | wa-saas is binary-only; Connexxa supports an if/elseif/elseif/.../else chain with per-branch handles. Real UX + runtime gap for multi-way branching (e.g. the HDFC journeys route 3+ ways off one condition today). |
| **List** | One output **handle per row/option** (confirmed live: 5 options → 5 independent connector lines, fan-in allowed downstream). | `sourceHandles: NEXT` — single output handle regardless of section/row count. | Topology gap. wa-saas's List node can't branch per selected option today; every option must currently be disambiguated after the fact (e.g. via a `question`/`condition` pair), which is extra nodes Connexxa doesn't need. |
| **Reply Button** | Same per-option handle pattern as List. | Same `sourceHandles: NEXT` single-handle limitation. | Same gap as List. |
| **Journey (sub-journey)** | Static mode (pick a published journey + a specific starting node, not just the journey's `Start`) **or** Dynamic mode (variable-bound `Dynamic Journey ID` / `Dynamic Node ID`, resolved at runtime). This is the backbone of Connexxa's real production architecture — every live journey audited was a hub-and-spoke of Journey nodes. | `configSchema: [{ key: 'journeyId', label: 'Journey', type: 'text', required: true }]` — a single free-text field, no node-targeting, no dynamic/runtime-resolved option. | Significant gap — this is the node type that makes Connexxa's production journeys composable (localization via parallel journey sets, hub routing). wa-saas's version can reference another journey by id but can't target a specific node inside it, and has no dynamic/runtime jump at all. |
| **API** | "Store Response In" → one variable holds the raw response; a following Code node destructures what's needed. Request body/params support full `{{var_local.x.y}}` interpolation, confirmed with a real production payload mixing `var_system` and nested `var_local` fields. Also has a reusable "Saved APIs" library (Global API Management) shared across nodes/journeys. | `responseMapping` (keyvalue, `variable = json.path`) — narrower, and not runtime-executable yet (`api` absent from `RUNTIME_EXECUTABLE_TYPES`). No saved/reusable API connection concept visible in the schema beyond `apiConnectionId` (a connection picker exists — [Unknown] whether it behaves like Connexxa's reusable "Saved APIs" library; worth a follow-up read of the API Connections feature before building more). | Needs: (a) runtime wiring (HTTP runner + response variable storage), (b) decide response-mapping strategy — Connexxa's "store whole thing, destructure via Code" is simpler and more flexible once the Code sandbox exists; wa-saas's per-field `responseMapping` is viable as a v1 without the sandbox. |
| **Payment** | 3-step wizard: Setup (existing link vs. generate new) → Amount/Tax/Discount (all variable-bindable, live total) → Details (Quick Pay toggle, Razorpay customer fields prefilled from variables, config summary). Response object has at least `.amount` and `.status` (seen destructured in a Code node). | `{ amount, currency, description, gatewayRef }` — flat, no tax/discount breakdown, no Quick Pay, no variable-bound amount, not runtime-executable yet. | Largest UI gap among the "advanced" nodes. **Per standing constraint: any gateway-config work needs explicit confirmation before touching payment config/credentials** — flag this one for a scoping conversation before implementation, not just a straight port. |
| **Email** | Select Template (from a separate Email Template library) + Select Mail Config + Store Response In. | [Unknown] — not inspected yet in this pass; `email` capability already exists (`capabilities: ['email']`) in `JourneyNodeCatalog` but `email` is not in `RUNTIME_EXECUTABLE_TYPES`. Needs its own config-schema read before scoping. |
| **Human Intervention** | Handover Message, Human Agent Instruction (context handed to the agent), Information Variable (transfer a variable into the human conversation). | [Unknown] — not inspected yet; `capabilities: ['crm']` already declared, not runtime-executable. |
| **Agent / RAG** | Model Provider picker (OpenAI/Groq/Anthropic/Google), own API key field, Agent Instructions, User Input, Response Format, Store Response In; RAG adds a required journey-scoped Files picker. | **Already runtime-executable** in wa-saas (`prompt`, `agent`, `rag` all in `RUNTIME_EXECUTABLE_TYPES`, via `MeteredAiService`/`KnowledgeRetriever`) — [Inference] likely closest-to-parity advanced node already; worth a direct schema diff but not flagged as a priority gap. |
| **Flow (Meta Flow)** | Header/Body/Footer/Button text, Flow ID, First Screen Type, live preview pane. | `flowId`, `flowCta`, `screenName`, `body` — already **runtime-executable**. Close parity; [Unknown] whether wa-saas's Flow node writes the submission into a journey variable the way Connexxa's `Variable Name` field does (this is the exact mechanism that feeds the whole "Flow → Code destructure" pattern in Connexxa's production journeys) — worth a direct check since it's the on-ramp for the variable-system work in §2. |

## 4. UI/UX chrome gaps (secondary, cheap to build)

- **"Leave Studio?" guard** on navigating away with unsaved node changes ("Stay"/"Leave") — [Unknown] whether
  wa-saas's journey builder has an equivalent; cheap, pure-frontend, low-risk addition if missing.
- **Connector-line hover controls**: "×" to disconnect, "+" to insert a new node directly into an existing
  edge (opens a categorized Message/Interactive/Advanced/Utility picker) — a real editing-speed feature;
  [Unknown] current wa-saas canvas UX for this.
- **Per-node hamburger menu**: Copy / Duplicate / Disconnect / Delete, plus an eye icon for a live preview of
  that single node's rendered message — [Unknown] current wa-saas parity.
- **Issues panel**: a dedicated validation surface separate from inline field errors (grouped "Node Validation"
  / "Flow Structure" with jump-to-node links, e.g. "Dead-End Nodes: Add an outgoing connection or terminal node").
- **Categorized variable picker** embedded in every rich-text toolbar (Local/System submenus) — directly
  supports whatever variable-system work happens in §2; builders should never have to type a variable name
  from memory.

## 5. Suggested implementation order

1. **Variable system core** (§2, items 1–3) — nested storage + dot/array-path `renderText()` + `var_system`
   namespace. Additive, no regression risk, and everything else below depends on it being richer than today's
   flat lookup.
2. **Conditional: multi-group branching** — extends an already-runtime-executable node; mechanically similar
   to adding more handles, contained blast radius.
3. **List / Reply Button: per-option handles** — topology change in the canvas + `resolveConditionTarget`-style
   routing in the engine; higher complexity, high production value (this is how Connexxa's real journeys branch).
4. **Journey-to-Journey node: target-node + dynamic mode** — core to replicating Connexxa's actual hub-and-spoke
   production architecture.
5. **Code-node sandbox (scoped v1)** — the single biggest lift; unlocks the API/Payment "store raw, destructure"
   pattern and the HRMS-style token-chain pattern once built. Recommend building it *after* 1–4 so there's a
   concrete, evidenced set of patterns (dot-path, array index, bracket-with-spaces, `Date`, `String.substring`)
   to scope the sandbox against, rather than over-building.
6. **API node runtime wiring + response-store pattern**, **Payment node richer config** (flag for a scoping
   conversation before touching gateway/credential surface), **Email/Human Intervention schema parity** — once
   the Code sandbox exists these become straightforward "store response, let Code destructure" implementations
   rather than needing their own bespoke mapping UI.
7. **UI chrome** (§4) — cheap, parallelizable, no dependency on the above; can be picked up by anyone at any
   point without blocking the runtime work.

## 6. Open items before starting implementation

- [Unknown] Current wa-saas journey-builder canvas UX (Leave-guard, connector insert, node menu, Issues panel) —
  needs its own quick audit pass (`frontend-app/src/journey/` canvas components) before §4's items are scoped,
  so we build only what's actually missing.
- [Unknown] `email`, `human_intervention`, `classifier` node config schemas not yet read in this pass.
- [Unknown] Whether wa-saas's `apiConnectionId` picker already behaves like Connexxa's reusable "Saved APIs"
  library — if so, §3's API gap shrinks to runtime wiring only.
- Per standing instruction: no payment-gateway or other credential-surface work proceeds without an explicit
  go-ahead in a dedicated conversation, even once this list is otherwise being worked through.

---

*Companion to this repo's `PROJECT_STATE.md`; produced from a live, read-only audit of the production ConnexxaIQ
account — no changes were made to any Connexxa journey during the audit (all Studio sessions were closed via
"Leave" without saving).*
