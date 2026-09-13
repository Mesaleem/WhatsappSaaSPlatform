# Meta Webhook Signature Verification — DB-Backed Refactor

Follow-up to the earlier HMAC-SHA256 hardening pass. This refactor removes all `.env`/`config` dependence for the secret and reads it dynamically from the existing `social_provider_configs` table instead.

## 1. Files changed

| File | Change |
|---|---|
| `backend-api/app/Http/Controllers/Api/MetaWebhookController.php` | `assertValidSignature()` now reads the secret via `SocialProviderConfig::findByProviderCached('meta')?->client_secret` instead of `config('services.meta.app_secret')`. The `META_WEBHOOK_VERIFY_SIGNATURE` config off-toggle is removed — bypass is `APP_ENV=local` only. Added `use App\Models\SocialProviderConfig;`. Docblocks updated to match. No other line touched. |
| `backend-api/config/services.php` | Removed the entire `'meta' => [...]` block added in the prior task. File is back to exactly its pre-hardening state (postmark…gemini, no `meta` key). |
| `backend-api/.env.example` | Removed `META_APP_SECRET` and `META_WEBHOOK_VERIFY_SIGNATURE` lines and their comment block. File is back to its pre-hardening state. |

**No new migration.** [Fact, verified before writing any code] `social_provider_configs` already has a `client_secret` column (`text`, nullable, `encrypted` cast, hidden from serialization) — the same column `SocialAuthController` uses as the Meta App's OAuth client secret. I reused it rather than adding a new `app_secret` column.

## 2. The load-bearing assumption — please confirm

[Inference, flagged in the code's docblock too]: Meta's platform model has exactly **one** "App Secret" per Meta App (App Dashboard → Settings → Basic). That single value is documented by Meta to serve as both the OAuth `client_secret` *and* the HMAC key for `X-Hub-Signature-256` on every webhook subscription registered under that App. So reusing `social_provider_configs.client_secret` (provider=`meta`) for this WhatsApp Cloud API webhook is correct **only if** this platform's WhatsApp Cloud API integration and its Marketing API/Lead-Ads OAuth integration are registered under the **same** Meta App.

I could not verify which is true from the codebase alone — nothing in the schema distinguishes "the WhatsApp App's secret" from "the Marketing API App's secret," because until now nothing in this codebase used the App Secret for anything other than OAuth. If your Meta Business setup actually uses two separate Meta Apps for these two products, this column will silently hold the wrong secret for this endpoint's HMAC check, and every webhook delivery will start failing signature verification (403) even though the payload is genuinely from Meta. If you're not sure, the fastest way to confirm is comparing the App ID your WhatsApp Business Platform integration is registered under (Meta Business Settings → WhatsApp Accounts) against the App ID stored as `social_provider_configs.client_id` (provider=`meta`) — if they match, this is correct as-is.

## 3. Bypass behavior change (explicit — you asked to simplify this)

Before this refactor there were two bypasses (local env, or an explicit config flag). Now there is exactly **one**: `app()->environment('local')`. There is no way to disable verification in any other environment any more — this is intentional per your instruction ("Maintain bypass only for local development testing"), but note it as a real behavior change from the previous task: if you were relying on `META_WEBHOOK_VERIFY_SIGNATURE=false` in a staging environment, that path no longer exists.

## 4. Rejection logic — unchanged from the prior pass

- Missing `X-Hub-Signature-256` header → 403, logged.
- No `client_secret` configured for provider=`meta` (no row, or null) → treated as misconfiguration, fails **closed** → 403, logged. Same reasoning as before: verifying "on" with nothing to check against would be worse than rejecting loudly.
- Signature mismatch → 403, logged (`hash_equals()`, constant-time).
- Log lines never include the secret, the computed signature, or the expected signature — only IP and header-presence metadata, unchanged from before.

## 5. Verify & confirm

- No PHP linter available in this environment (`which php` / `composer --version` both still empty, consistent with every prior task this session) — verified via manual review plus balance checks:
  - `MetaWebhookController.php`: braces 32/32, parens 155/155.
  - `config/services.php`: brackets 12/12, parens 25/25.
- Grepped the whole `app/`, `config/`, and `.env.example` for `META_APP_SECRET`, `META_WEBHOOK_VERIFY_SIGNATURE`, and `services.meta.` — zero remaining references anywhere.
- `handleInboundMessages()` and `captureCtwaLead()` — confirmed byte-identical to the previous task's verified baseline (only the import block, `handle()`'s docblock, and `assertValidSignature()` were touched).
- `verify()` (GET handshake) — untouched.

## 6. Operational implication (replaces the prior task's "set META_APP_SECRET in .env" step)

Setting the secret is no longer a deploy/`.env` action — it must be entered through whatever admin UI manages `social_provider_configs` (the existing Meta OAuth credentials screen, since this is the same `client_secret` field — `SocialGatewayController`, per earlier context in this engagement). **If that field is not yet populated for provider=`meta` in your database, every production webhook delivery will be rejected with 403 the moment this deploys**, exactly as before — only the place you go to fix it has changed (DB row via admin UI, not `.env`).

## 7. Flagged, not actioned (out of scope for this task)

`SocialWebhookController`'s separate leadgen/comment webhook has its own long-disclosed, still-open signature-verification gap. Previously I described it as needing "a different credential." With this refactor, that's no longer true — it needs the exact same `social_provider_configs.client_secret` (provider=`meta`) this method now reads, so closing that gap would need no new schema, just the same HMAC check added to `SocialWebhookController::handle()`. Say the word if you want that as a follow-up.

## 8. Standing state (unchanged)

Still no migrations run. The four migrations disclosed in earlier reports remain written, not executed, awaiting your authorization:
- `2026_09_11_190000_add_gemini_api_key_to_accounts_table.php`
- `2026_09_11_193000_create_organic_posts_table.php`
- `2026_09_11_200000_create_whatsapp_flows_table.php`
- `2026_09_11_200001_create_whatsapp_flow_sessions_table.php`
