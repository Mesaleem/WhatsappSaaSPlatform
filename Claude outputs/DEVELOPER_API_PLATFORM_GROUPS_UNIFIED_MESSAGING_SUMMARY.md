# Developer API Platform: WhatsApp Group Creation & Unified Messaging

Implements the three requested pieces: dual-factor API auth, a group-creation endpoint, and a unified send-message endpoint that routes by recipient type and message type. Built to reuse this codebase's existing dispatch/quota/engine-selection machinery wherever it already covered the need, rather than duplicating it.

## 1. Dual-factor authentication

**New, separate middleware, not a change to the existing one.** The pre-existing `AuthenticateApiKey` (`auth.apikey`) is single-factor and is used today by three live routes (`/v1/messages/send-payment-alert`, `/v1/messages/send-template`, `/v1/send-message`). Requiring a second header there would break every integration already using those routes. Instead:

- New nullable `secret_prefix`/`secret_hash` columns on `api_keys` (migration `2026_09_15_130000_add_secret_to_api_keys_table.php`, **not yet run — needs your authorization**, see below).
- `ApiKey` model gained `hasSecret()`, `isSecretValid()` (timing-safe `hash_equals()`), and `hashSecret()`.
- `ApiKeyController::store()` now issues a secret alongside every **new** key. A new `regenerateSecret()` action (`POST /api/developer/api-keys/{id}/regenerate-secret`) lets an **existing** key be backfilled with a secret without touching its key hash — every already-integrated caller of that key keeps working.
- New `ApiAuthMiddleware` (alias `auth.apisecret`) validates `X-API-KEY` + `X-API-SECRET` and attaches `api_account_id`, `api_agent_id`, `api_engine_type`, and `api_key` to the request. Scoped only to the two new routes below.
- Frontend: the Developer Portal's Create API Key modal now reveals both the key and the secret; the API Keys table has a Secret column and a "Generate/Regenerate Secret" action for keys created before this feature. Verified with `tsc -b` (clean) and `oxlint` (0 errors, same 4 pre-existing warnings as before).

## 2. `POST /api/v1/whatsapp/groups/create`

`Api\V1\GroupController::create()`, dual-factor authenticated. Accepts `name`, optional `group_type` (`internal_segment` default, or `native_wa_group`), and `contacts` (required for native).

Native group creation delegates to a new `NativeGroupCreationService`, extracted **byte-for-byte** from `ContactGroupController::storeNativeGroup()`'s transaction body — that internal controller now calls the same service, so a group created through this external endpoint and one created through your own dashboard go through identical code. Same preconditions as the internal path: account must be on the `qr` engine with a connected WhatsApp session (Meta Cloud API has no group capability at all). Same `contact_groups` module gate as every other group action.

## 3. `POST /api/v1/whatsapp/messages/send`

`Api\V1\UnifiedMessageController::send()`, dual-factor authenticated. Routes on two independent fields:

- `recipient_type`: `individual` (`to`: phone) or `group` (`group_id`: **either** this app's internal `ContactGroup` id **or** the raw WhatsApp group JID string, e.g. `120363xxx@g.us` — both accepted, per the literal spec wording).
- `message_type`: `text`, `media`, or `template`.

**Template routing reuses the existing dispatchers unchanged** (`TemplateMessageDispatcher` for individual, `GroupMessageDispatcher` for group) — the same classes `/v1/send-message` already uses. Two disclosed translations were needed to bridge the spec's literal field names onto this schema:

- `template_name` is matched against `MessageTemplate.title` — there is no `template_name` column in this app's schema; `title` is the equivalent field.
- `components.parameters` (Meta's own numbered-placeholder wire format) is translated into this app's flat, named `{{token}}` variables by a new `TemplateComponentTranslator`. **This is a positional mapping, not a literal one** — Meta's format carries no variable names, so the Nth `type: "text"` parameter (across all `body`-type components, in order) is assigned to the Nth field of the template's configured variable schema, in that schema's own order. Non-text parameter types (`currency`, `date_time`, etc.) are skipped, not mis-mapped. If your templates' variable order doesn't match the order you send parameters in, values will land on the wrong field — flagging this now since it's the one part of this endpoint that can't have a single unambiguous meaning.

**Text and media are new** — no prior dispatcher handled non-template sends. New symmetric pair of classes:

- `DirectMessageDispatcher` (individual) / `GroupDirectMessageDispatcher` + `ProcessGroupDirectMessageJob` (group) — mirror `TemplateMessageDispatcher`/`GroupMessageDispatcher`/`ProcessGroupDispatchJob`'s exact structure (same account/subscription/connection checks, same quota accounting — 1 credit per individual or native-group send, N credits for an internal-segment fan-out, same anti-ban jitter between members), just without a template to render.
- Engine selection is untouched — every new dispatcher still resolves the driver via the existing `WhatsAppEngineFactory::make($account)`, so Meta-vs-QR routing is automatic exactly as it already was.
- Media shape: `{media_type: image|document|video|audio, url, caption?, filename?}`, translated per-engine by a new `WhatsAppMediaPayloadBuilder`.
  - **Meta engine**: reuses the *exact* `{type, <type>: {link, caption, filename}}` shape `ChatbotEngineService` already sends today for chatbot media replies — zero new code path on the Meta side, just a new caller of an existing, already-shipped contract.
  - **QR/Baileys engine**: media sending did not exist at all before this feature. Added `media_type`/`media_url`/`caption`/`filename` fields to `qr-engine-service`'s `/api/message/send` endpoint and a `buildContent()` helper in `sessionManager.js` that builds Baileys' `{image:{url}}`/`{video:{url}}`/`{document:{url}, fileName}`/`{audio:{url}, mimetype}` content objects. **Disclosed limitation**: this follows Baileys' documented API but has **not been exercised against a live, authenticated WhatsApp session** in this environment — flag as unverified-in-production until tested against a real connected device.

## Verification performed (and what wasn't available)

No `php` binary exists in this environment, so PHP correctness was checked by: brace/paren/bracket balance on every new/changed file; every `use App\...` import resolved against the actual file on disk; every class `extends Controller` checked for the matching `use App\Http\Controllers\Controller;` import (this is exactly the bug class you reported earlier this session in `RouteMasterController`/`ActivityLogController` — both already fixed, and this new check confirmed no new file has it); namespace-vs-directory and class-name-vs-filename checks. This is real verification of what it checks, but it cannot catch a logic error the way running the code would.

Node.js changes (`qr-engine-service`) were verified with `node --check` — genuine syntax validation, passed on both files. Frontend changes were verified with `tsc -b` (clean, exit 0) and `oxlint` (0 errors).

## Needs your authorization before it's live

1. **New migration** `2026_09_15_130000_add_secret_to_api_keys_table.php` (adds `secret_prefix`/`secret_hash` to `api_keys`, both nullable — backward compatible) has **not been run**. Run with `php artisan migrate` when ready.
2. This is in addition to the Phase 4/5 migrations (`route_categories`, `system_routes`, `activity_logs`) already flagged as pending in earlier phases this session, which are still un-run.
3. `qr-engine-service` needs a restart to pick up the `sessionManager.js`/`server.js` changes (media support) — no code-level action needed beyond that, but flagging since it's a running service, not just files.

## Files changed

**backend-api** — new: `database/migrations/2026_09_15_130000_add_secret_to_api_keys_table.php`, `app/Http/Middleware/ApiAuthMiddleware.php`, `app/Services/Groups/NativeGroupCreationService.php`, `app/Http/Requests/CreateWhatsAppGroupRequest.php`, `app/Http/Controllers/Api/V1/GroupController.php`, `app/Support/WhatsAppMediaPayloadBuilder.php`, `app/Services/WhatsApp/DirectMessageDispatcher.php`, `app/Services/Groups/GroupDirectMessageDispatcher.php`, `app/Jobs/ProcessGroupDirectMessageJob.php`, `app/Services/Templates/TemplateComponentTranslator.php`, `app/Http/Requests/UnifiedSendMessageRequest.php`, `app/Http/Controllers/Api/V1/UnifiedMessageController.php`. Modified: `app/Models/ApiKey.php`, `app/Http/Controllers/Api/ApiKeyController.php`, `bootstrap/app.php`, `app/Http/Controllers/Api/ContactGroupController.php` (delegates to the new service; no behavior change), `routes/api.php`.

**qr-engine-service** — modified: `src/server.js`, `src/services/sessionManager.js`.

**frontend-app** — modified: `src/types/developer.ts`, `src/services/developerService.ts`, `src/pages/developer/DeveloperPage.tsx`.
