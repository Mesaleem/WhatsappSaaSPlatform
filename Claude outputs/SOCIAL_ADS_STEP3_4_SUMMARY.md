# Social/Ads Launcher Overhaul — Step 3 & 4 Implementation Summary

**Date:** 2026-09-11
**Scope:** Step 3 (Organic Multi-Channel Publishing Engine) + Step 4 (Click-to-WhatsApp Ads & Inbound Referral Lead Capture) + Audit & Verify.

All claims below are tagged [Fact] (verified by reading the actual file), [Inference] (reasoned from verified facts), [Hypothesis] (Meta/LinkedIn API contract followed from documentation, not live-tested), or [Unknown].

---

## 1. STEP 3 — Organic Multi-Channel Publishing Engine

### 1.1 Database

**Created (not yet migrated):** `database/migrations/2026_09_11_193000_create_organic_posts_table.php`

Table `organic_posts`: `account_id` (FK, cascade), `social_account_id` (FK, nullable, null-on-delete), `provider` (`meta`|`linkedin`), `platform` (`facebook`|`instagram`|`linkedin`), `caption` (text), `media_url`/`media_type` (nullable), `status` (default `pending`), `external_post_id`/`error_message` (nullable), `published_at` (nullable), timestamps, composite index `[account_id, platform, status]`.

[Inference] This diverges deliberately from `ad_campaigns`' "persist only on confirmed external success" precedent: the spec required failure logging, so a row is created at `pending` *before* the external call and updated after — disclosed in the migration's own docblock.

**⚠️ NOT YET RUN.** Per the state-mutation authorization protocol, no migration has been executed against the database. Two migrations are now pending:
1. `2026_09_11_190000_add_gemini_api_key_to_accounts_table.php` (from Steps 1–2, still pending)
2. `2026_09_11_193000_create_organic_posts_table.php` (this step)

**To apply both, run on the server:**
```
php artisan migrate
```
This requires your explicit authorization — I have not run it.

### 1.2 Backend files

| File | Action | Purpose |
|---|---|---|
| `app/Models/OrganicPost.php` | **Created** | Eloquent model — `PLATFORMS`/`STATUS_*` consts, `account()`/`socialAccount()` relations, `scopeForAccount()`, `markPublished()`/`markFailed()` helpers. |
| `app/Services/Social/OrganicPublishService.php` | **Created** | Orchestrator (`publish()`) + 3 platform drivers. |
| `app/Http/Controllers/Api/OrganicPostController.php` | **Created** | `index()` (history, last 50) + `store()` (publish now). |
| `routes/api.php` | **Edited** | Added `OrganicPostController` import + `GET/POST /api/social/organic-posts` inside the existing `tenant.isolation`/`subscription.guard` group, gated on `module.guard:social_accounts` + `permission:manage-social-accounts`. |

### 1.3 Platform drivers ([Hypothesis] — not integration-tested, same standing disclosure as `MetaAdsService`)

- **Facebook Page Feed**: image → unpublished `/photos` upload then `/feed` with `attached_media`; video → direct `/videos` (Meta publishes video uploads as feed posts on their own); text-only → plain `/feed`.
- **Instagram**: two-step `/media` → `/media_publish`. Text-only posts are rejected client- and server-side (Instagram has no text-only post type). Video containers are polled via `status_code` (bounded loop, `sleep(3)` between attempts) — same synchronous-blocking trade-off already disclosed for `MetaWebhookController`'s processing; a production deployment should queue this.
- **LinkedIn UGC Posts API**: `POST /v2/ugcPosts`, with the full `registerUpload` → binary `PUT` → asset-URN media flow for images/video.

### 1.4 ⚠️ CRITICAL DISCLOSED GAP — LinkedIn is unreachable end-to-end

[Fact, verified by reading `SocialOAuthProviderFactory.php`]: `IMPLEMENTED_PROVIDERS = ['meta']` only. `make('linkedin')` throws `InvalidArgumentException`. **No `SocialAccount` row with `provider='linkedin'` can ever exist** through this codebase's current OAuth flow. `publishToLinkedIn()` is written correctly against LinkedIn's documented API but will always fail at the `resolveSocialAccount()` pre-flight step with "No connected LinkedIn asset for this tenant." This was **not** part of Step 3's spec to fix (LinkedIn OAuth), so it is surfaced here rather than silently built around. The frontend's `OrganicPostModal` shows an inline note when LinkedIn is selected, so tenants aren't misled.

### 1.5 Frontend files

| File | Action |
|---|---|
| `src/types/organic.ts` | **Created** — `OrganicPlatform`, `OrganicPostStatus`, `OrganicPost`, `PublishOrganicPostPayload`, LinkedIn-unavailable note constant. |
| `src/services/organicPostService.ts` | **Created** — `list()` / `publish()`. |
| `src/components/social/OrganicPostModal.tsx` | **Created** — single-step form (platform multi-select, caption, media upload reusing `mediaService`), publishes one request per selected platform, shows per-platform pass/fail results. |
| `src/pages/social/MetaAdsPage.tsx` | **Edited** — replaced the single "Launch Campaign" button with a **Mode Toggle**: `[ Organic Post ]` / `[ Paid Meta Ad Campaign ]`, each opening its own modal (`OrganicPostModal` vs the existing `LaunchWizardModal`, left untouched). |

[Inference] Organic posting was deliberately **not** folded into the existing 3-step paid wizard — an organic post has no budget/targeting/objective to configure, so a separate, simpler modal avoids forcing irrelevant steps on the tenant.

---

## 2. STEP 4 — Click-to-WhatsApp (CTWA) Ads & Inbound Referral Lead Capture

### 2.1 `AdCampaign::OBJECTIVES`

Added `'CLICK_TO_WHATSAPP'` to the const array. No migration needed — `objective` is a plain `string` column with no DB-level enum constraint [Fact, verified by reading the migration]. `AdCampaignController`'s `Rule::in(AdCampaign::OBJECTIVES)` validation picks this up automatically — **no controller change was required.**

### 2.2 `MetaAdsService.php` changes

- `OBJECTIVE_MAP['CLICK_TO_WHATSAPP'] = 'OUTCOME_ENGAGEMENT'` and `OPTIMIZATION_GOAL_MAP['CLICK_TO_WHATSAPP'] = 'CONVERSATIONS'` — [Hypothesis] mapped identically to `MESSAGES`, since pre-ODAX, Click-to-WhatsApp used the same legacy `MESSAGES` objective as Click-to-Messenger, differing only in the AdSet's `destination_type`.
- `createAdSet()` now takes `Account $account` and adds a `CLICK_TO_WHATSAPP` branch: `destination_type => 'WHATSAPP'`, with `promoted_object => ['page_id' => ..., 'whatsapp_phone_number' => ...]`.
- New `resolveWhatsAppPhoneNumber(Account $account)` reads `WhatsAppSession.meta_phone_number_id` (the same field the WhatsApp module already uses for inbound webhook routing) — throws a clear `RuntimeException` (→ 422 via the controller's existing catch block) if no WhatsApp session is configured.
- `createAdCreative()` now maps `CLICK_TO_WHATSAPP` → CTA type `WHATSAPP_MESSAGE` via a small `$ctaTypeMap`, instead of the prior two-way ternary.

**⚠️ DISCLOSED DESIGN UNCERTAINTY** ([Hypothesis], explicitly flagged in the code): Meta's most commonly documented Click-to-WhatsApp path promotes a Page whose WhatsApp Business Account is already linked in Business Manager, with `promoted_object = {"page_id": ...}` only — the destination number is implied by that linkage, **not** passed explicitly in the API call. This implementation instead follows the **literal instruction given for this step** ("set the promoted object to the tenant's connected WhatsApp Business phone number") and sends the number explicitly via a `whatsapp_phone_number` key. If a live Meta call rejects that field, the documented fallback (drop it, rely on the Page's own WhatsApp linkage) is noted directly in the code comment — this is a genuine, disclosed [Unknown] pending a real Meta Business Manager test, not a guess presented as fact.

### 2.3 Frontend types

- `types/ads.ts`: `AdObjective` gained `'CLICK_TO_WHATSAPP'`, `AD_OBJECTIVE_LABELS` gained `'Click to WhatsApp'`.
- `MetaAdsPage.tsx`: `OBJECTIVE_CTA_LABEL` gained `CLICK_TO_WHATSAPP: 'Send WhatsApp Message'` (mirrors the backend's `WHATSAPP_MESSAGE` CTA exactly, so the live preview never shows a button the launched ad wouldn't have — same discipline as the original three objectives).

The wizard's existing Objective `<select>` (Step 1, driven by `Object.keys(AD_OBJECTIVE_LABELS)`) automatically lists "Click to WhatsApp" — no JSX change was needed there.

### 2.4 `MetaWebhookController.php` — Inbound Referral Lead Capture

- Added `use App\Models\Lead;` and `use App\Support\PhoneNumberNormalizer;`.
- `handle()` now also passes `$value['contacts'] ?? []` into `handleInboundMessages()`.
- `handleInboundMessages()` gained a `$contacts` parameter and now checks `$message['referral']` **before** the existing text-only gate (a referral can in principle accompany any inbound message type), calling a new `captureCtwaLead()` — the text-only gate and the existing Chatbot hand-off below it are otherwise **completely unchanged**.
- New `captureCtwaLead(int $accountId, string $from, array $message, array $contacts)`:
  - Extracts `message['referral']` (source ad ID, headline, media — [Hypothesis]: WhatsApp Cloud API's documented top-level `message.referral`, not nested under `context`, per the exact wording of this step's instruction).
  - `Lead::updateOrCreate(['provider_lead_id' => $wamid], [...])` — upserts on the inbound message's WAMID, reusing the **same** unique-index redelivery-safety guarantee `MetaLeadWebhookHandler` already relies on for Lead Ads leads.
  - Sets `provider = 'whatsapp_ctwa'`, `ad_id` = `referral.source_id`, `lead_phone` = the normalized sender number, `lead_name` = `contacts[].profile.name` matched by `wa_id` (if present), `raw_field_data` = the referral object verbatim.
  - **Deliberately does not** call `Lead::isDuplicatePhoneWithin24Hours()` — that guard collapses repeat *form* submissions; a CTWA referral is an individual ad-click attribution event and is intentionally allowed to recur.
  - Falls through to the existing `ChatbotEngineService::handleInboundMessage()` call exactly as before — the Lead capture happens first, then control passes to the chatbot, per this step's explicit ordering requirement.

No `leads` table migration was needed — `provider`, `provider_lead_id`, `ad_id`, `lead_name`, `lead_phone`, `raw_field_data` all already existed on the table [Fact, verified by reading the migration].

---

## 3. Audit & Verify

### 3.1 Frontend

```
./node_modules/.bin/tsc -p tsconfig.app.json --noEmit   → exit 0, zero errors
./node_modules/.bin/oxlint src/                          → 0 errors, 51 warnings
```
All 51 warnings are the pre-existing `react(set-state-in-effect)` pattern (calling `setState` inside a `useEffect` used for initial data loading) found **throughout the codebase** (e.g. `AccountsPage.tsx`, `GatewaySettingsPage.tsx`) — not something this session introduced. The one warning inside `MetaAdsPage.tsx` itself is the same pre-existing `loadCampaigns()` call flagged before Steps 1–2 began (previously reported at line 779; now at line 789 purely because new code was inserted above it in the file). **Zero new warnings or errors from any file touched in Steps 3–4.**

### 3.2 Backend

**⚠️ Disclosed limitation, unchanged from Steps 1–2:** no PHP binary or Composer is available in this environment (`which php` / `composer --version` both return nothing), so no `php -l`, `phpstan`, or `php artisan` command can run here. Every touched/created PHP file was instead:
1. Manually re-read in full after editing.
2. Checked for brace/paren balance programmatically:

```
app/Models/OrganicPost.php                                       braces:7/7   parens:16/16   OK
app/Models/AdCampaign.php                                        braces:7/7   parens:13/13   OK
app/Services/Social/OrganicPublishService.php                     braces:55/55 parens:144/144 OK
app/Http/Controllers/Api/OrganicPostController.php                braces:6/6   parens:33/33   OK
app/Services/Ads/MetaAdsService.php                                braces:72/72 parens:168/168 OK
app/Http/Controllers/Api/MetaWebhookController.php                 braces:25/25 parens:99/99   OK
routes/api.php                                                     braces:89/89 parens:365/365 OK
database/migrations/..._create_organic_posts_table.php             braces:4/4   parens:36/36   OK
```

This is **not** a substitute for a real linter/static analyzer — if PHP or Composer become available in a future session, running `php artisan route:list`, `./vendor/bin/phpstan analyse`, or simply booting the app is strongly recommended before this code reaches production.

---

## 4. Full list of files created/updated this step

**Created:**
- `backend-api/database/migrations/2026_09_11_193000_create_organic_posts_table.php` *(not yet migrated)*
- `backend-api/app/Models/OrganicPost.php`
- `backend-api/app/Services/Social/OrganicPublishService.php`
- `backend-api/app/Http/Controllers/Api/OrganicPostController.php`
- `frontend-app/src/types/organic.ts`
- `frontend-app/src/services/organicPostService.ts`
- `frontend-app/src/components/social/OrganicPostModal.tsx`

**Edited:**
- `backend-api/routes/api.php` (organic-posts routes)
- `backend-api/app/Models/AdCampaign.php` (`CLICK_TO_WHATSAPP` objective)
- `backend-api/app/Services/Ads/MetaAdsService.php` (CTWA objective/optimization maps, `createAdSet()` WHATSAPP branch, `resolveWhatsAppPhoneNumber()`, `createAdCreative()` CTA map)
- `backend-api/app/Http/Controllers/Api/MetaWebhookController.php` (referral extraction, `captureCtwaLead()`)
- `frontend-app/src/types/ads.ts` (`CLICK_TO_WHATSAPP` objective + label)
- `frontend-app/src/pages/social/MetaAdsPage.tsx` (Mode Toggle, CTA label)

---

## 5. Outstanding items requiring your decision

1. **Run the two pending migrations** (`add_gemini_api_key_to_accounts_table`, `create_organic_posts_table`) — needs `php artisan migrate` on your server; not run by me per the state-mutation authorization protocol.
2. **LinkedIn OAuth is not implemented** — organic LinkedIn posting and the LinkedIn driver are code-complete but unreachable until a `LinkedInOAuthProvider` is added to `SocialOAuthProviderFactory`. Out of scope for Steps 1–4 as given; flagging for a future step.
3. **CTWA `promoted_object` shape is unverified against a live Meta call** (see §2.2) — recommend a real Meta Ads Manager test (sandbox or a small real budget) before relying on this in production; the documented fallback is already noted in the code if the explicit `whatsapp_phone_number` field is rejected.
4. **No PHP linter/static analyzer available in this environment** — recommend running `php artisan route:list` and `phpstan`/`larastan` (if configured) in your own local/CI environment before merging.
