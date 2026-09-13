# Meta Webhook HMAC-SHA256 Hardening — Summary Report

**Scope:** `backend-api/app/Http/Controllers/Api/MetaWebhookController.php` (WhatsApp Cloud API webhook, `/api/webhooks/meta`) plus its configuration. No frontend changes; no database migrations.

## 1. Files changed

| File | Change |
|---|---|
| `backend-api/config/services.php` | Added `'meta' => ['app_secret' => env('META_APP_SECRET'), 'verify_signature' => (bool) env('META_WEBHOOK_VERIFY_SIGNATURE', true)]`. No other keys touched. |
| `backend-api/.env.example` | Added `META_APP_SECRET=` and `META_WEBHOOK_VERIFY_SIGNATURE=true`, with a comment block explaining scope and the local-env bypass. |
| `backend-api/app/Http/Controllers/Api/MetaWebhookController.php` | Added `private function assertValidSignature(Request $request): void`, called as the **first line** of `handle()`. Updated `handle()`'s docblock (the "KNOWN GAP" paragraph is replaced with a "HARDENED" paragraph). No other line in the file was touched. |

## 2. Implementation, matched against your three requirements

**(1) Signature verification** — `assertValidSignature()`:
- Header: `$request->header('X-Hub-Signature-256', '')`.
- Raw body: `$request->getContent()` (Laravel buffers the input stream, so this does not conflict with `$request->input('entry', [])` used later in `handle()` — [Fact], standard Laravel `Request` behavior, `getContent()` reads the buffered raw body regardless of call order).
- Secret: `config('services.meta.app_secret')`.
- Expected signature: `'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret)`.
- Comparison: `hash_equals($expected, $header)` — constant-time.

**(2) Rejection & audit logging:**
- Missing header, unconfigured secret, or signature mismatch → `Log::warning()` (IP + header-presence metadata only — **never** the app_secret, the raw body, or the computed/expected signature values) → `abort(403, ...)`.
- **Status code: 403**, not 401. [Decision, disclosed per your instruction's "401 or 403" latitude]: your own `verify()` method in this same controller already uses 403 for both of its rejection paths (mode/token mismatch); 403 was chosen to keep one convention inside this class rather than introduce a second (401 typically implies "authenticate and retry with credentials," which doesn't apply to a server-to-server webhook signer).
- **Bypass toggle**, checked in this order — either one skips verification:
  1. `app()->environment('local')`
  2. `config('services.meta.verify_signature') === false` (i.e. `META_WEBHOOK_VERIFY_SIGNATURE=false`)
  - Default (`.env.example` ships `META_WEBHOOK_VERIFY_SIGNATURE=true`, and the config default is also `true`): verification is **ON**.
- **[Decision, not explicitly specified by you — flagging it]:** if verification is enabled (not local, not toggled off) but `META_APP_SECRET` is empty/unset, I treat that as a misconfiguration and **reject with 403** (fail closed), rather than silently letting requests through. Rationale: silently bypassing verification because the operator forgot to set the secret is a worse failure mode than a loud 403 in logs. If you'd rather fail *open* in that specific case (e.g. during a staged rollout before the secret is provisioned), tell me and I'll change that one branch — it's an isolated `if` block.

**(3) Audit & verify:**
- `handleInboundMessages()` and `captureCtwaLead()` — **byte-identical**, confirmed by diffing the post-edit file against the full pre-edit read taken at the start of this task. Only `handle()` (one line inserted) and the docblock were touched, plus the one new private method.
- Brace/paren balance (no PHP linter available in this environment — confirmed again via `which php`/`composer --version`, both empty, consistent with every prior task this session):
  - `MetaWebhookController.php`: braces 33/33, parens 156/156.
  - `config/services.php`: brackets 13/13, parens 36/36 (this file has no `{`/`}` at all — pure array syntax).
- **`leadgen` — [Fact, verified this task, worth flagging]:** `leadgen` is **not handled in `MetaWebhookController.php`** and never was. It's handled by a separate controller, `SocialWebhookController::handle()` (route `POST /api/social/webhook/{provider}`), which delegates to `MetaLeadWebhookHandler`. That controller's own docblock already discloses an **unrelated, still-open** signature-verification gap on *its* webhook (it uses a different credential — `SocialProviderConfig`'s OAuth `client_secret`, not this Meta App Secret — and has no `app_secret` field to verify against at all). That gap is out of scope for this task (you scoped it to `MetaWebhookController.php` specifically) and remains open — flagging it in case you want it addressed as a follow-up.

## 3. Regression / side-effect analysis

- **Existing webhook handlers** (`handleInboundMessages`, `captureCtwaLead`, `fireDeliveredWebhook`): unchanged, behavior-identical.
- **`verify()` (GET handshake)**: untouched — Meta's GET verification handshake carries no body and no `X-Hub-Signature-256` header by design, so it was correctly left out of scope.
- **Production impact**: once `META_APP_SECRET` is set in the real `.env` (it is currently unset — this is a required deploy step, not just a code change) and `APP_ENV` is not `local`, every POST to `/api/webhooks/meta` will be rejected with 403 **until Meta is also configured with a matching App Secret and is sending signed requests** — which it always does by default for Cloud API webhooks. If `META_APP_SECRET` is left blank in production, **all inbound WhatsApp messages/status updates will start being rejected** the moment this deploys (fail-closed, per the misconfiguration decision above). This is the one deploy-time action item from this change.
- **Local/dev**: no behavior change — `APP_ENV=local` bypasses verification exactly as before this hardening.

## 4. Standing state (unchanged by this task)

No migration was created or run by this task. The four migrations disclosed in prior reports this session remain **written to disk, not executed**, awaiting your explicit authorization to run `php artisan migrate`:
- `2026_09_11_190000_add_gemini_api_key_to_accounts_table.php`
- `2026_09_11_193000_create_organic_posts_table.php`
- `2026_09_11_200000_create_whatsapp_flows_table.php`
- `2026_09_11_200001_create_whatsapp_flow_sessions_table.php`

## 5. Action items for you

1. Set a real `META_APP_SECRET` in production `.env` before/at deploy time (from Meta App Dashboard → Settings → Basic → App Secret). Without it, this endpoint will reject all webhook traffic once deployed with `APP_ENV` ≠ `local`.
2. Confirm the fail-closed-on-missing-secret decision (section 2) is what you want, or tell me to flip it.
3. Decide whether `SocialWebhookController`'s separate, still-open leadgen/comment signature-verification gap should be a follow-up task.
