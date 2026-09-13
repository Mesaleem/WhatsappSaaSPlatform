# Social Media & Ads Module — Audit Report

**Role assumed:** Principal Full-Stack Architect & Meta Marketing API Lead
**Scope:** `backend-api/` + `frontend-app/` Social/Ads module, evaluated against the "Dynamic, Industry-Agnostic Ads Launcher" strategy (any business type uploads media, previews ads, publishes Organic or Paid Meta Lead Ads).
**Method:** Direct source inspection on-device. Nothing executed, nothing mutated. Every claim is **[Fact]** (verified by reading the exact file/line shown), **[Inference]** (a conclusion drawn from facts), or **[Hypothesis]** (plausible, unverified against a live Meta call — flagged as such by the code's own docblocks in most cases, corroborated here).

**Bottom line up front:** The **Paid Meta Lead Ad launcher** (Campaign→AdSet→AdCreative→Ad) is real, hits actual Marketing API endpoints, and is the most complete piece of this module. The **Organic Publish pipeline is entirely absent** — no FB/IG/LinkedIn organic posting code exists anywhere. The **AI Copywriter uses OpenAI/Anthropic/templates, not Gemini Pro**, and exposes 2 of the 3 requested dynamic tokens. **Click-to-WhatsApp is not implemented** — the "Messages" objective actually builds a Click-to-**Messenger** campaign. The **Meta Lead-Ad → `leads` table pipeline is fully built and good**; the CTWA-inbound half of the lead pipeline does not exist.

---

## 1. Dynamic Asset Upload & Live Preview System

**[Fact] Image/video upload:** does not exist. `frontend-app/src/pages/social/MetaAdsPage.tsx` (the entire ads launcher UI) has exactly one creative-media field:
```tsx
<label>Image URL (optional)
  <input type="text" value={form.image_url} onChange={(e) => update({ image_url: e.target.value })} placeholder="https://…" />
</label>
```
There is no `<input type="file">`, no `FormData`/multipart upload call, anywhere in `pages/social/` or `components/social/`. The only "asset" component in the module, `components/social/AssetSelectionModal.tsx`, is unrelated — it lets a tenant choose which already-OAuth'd Facebook Page / Instagram account / Ad Account to *connect*, not which media file to *upload*.

**[Fact] Live preview UI:** does not exist. There is no FB-Feed or IG-Story/Post mockup component anywhere in the frontend (confirmed by name search across `pages/social/` and `components/social/` — no "Preview" component of any kind). The pasted `image_url` is never even rendered as an `<img>` inside the wizard for the tenant to look at before launch.

**[Fact] Edit/Regenerate AI copy:** partially built.
- Edit: **yes** — once a variant is applied (`applyAiVariant` copies `hook`→Headline, `caption + cta`→Primary Text), both fields are plain controlled `<input>`/`<textarea>` elements the tenant can freely retype. This is genuine manual editing, not a static preview.
- Regenerate: **implicit only** — there is one button, "✨ Generate with AI" (`handleGenerateAi`), which can be clicked again to fetch a new batch of 5 variants. There is no distinct "Regenerate" action, no per-variant regenerate, and no indication to the tenant that re-clicking replaces rather than adds to the list (it replaces — `setAiVariants(result.variants)`).

**Verdict:** **[B] Partially built.** Prompt input exists (Industry + Tone); upload and preview do not.

---

## 2. Organic vs Paid Ads Launcher Pipeline

### 2a. Organic Publish — **[C] Not built, at all**

**[Fact]** Repo-wide search for organic-post primitives returns nothing:
- No `ugcPosts` / LinkedIn UGC API call anywhere. LinkedIn appears **only** as an OAuth connect provider (`app/Services/SocialAuth/SocialOAuthProviderFactory.php`, `SocialAuthController`) for account binding — there is no LinkedIn posting code at all.
- No Facebook Page feed-post call (`POST /{page-id}/feed`) anywhere.
- No Instagram Media API two-step publish (`POST /{ig-user-id}/media` → `POST /{ig-user-id}/media_publish`) anywhere.
- The only Graph API POST calls in the whole backend are: `SocialInboxController` (send a DM/comment *reply*, not a new post), `MetaAdsService` (paid campaign objects), `CommentAutomationService` (reply to a comment). None of these create an organic feed/story post.

There is no controller, service, route, or frontend action named or shaped like "Publish Post," "Organic," or anything that lets a tenant post to their Page/IG/LinkedIn feed without spending ad budget. This is a ground-up build, not a refactor.

### 2b. Paid Meta Lead Ad Launcher — **[A] Built, real, well-engineered — with two gaps**

**[Fact]** `app/Services/Ads/MetaAdsService.php::launch()` builds the real chain against Marketing API v19.0:
1. `POST /{ad_account_id}/campaigns` — `objective` mapped from the app's `LEAD_GENERATION|MESSAGES|TRAFFIC` to Meta's current ODAX values (`OUTCOME_LEADS|OUTCOME_ENGAGEMENT|OUTCOME_TRAFFIC`) — correct handling of Meta's 2022 objective-model change, not naive.
2. `POST /{ad_account_id}/adsets` — `daily_budget` correctly converted to the ad account's smallest currency unit (`×100`, i.e. paise for INR) — **so a ₹500/day budget from the wizard is sent to Meta as `50000`, correctly.** Targeting (countries, age range, interest keywords resolved via the Targeting Search API) is wired in.
3. `POST /{ad_account_id}/adcreatives` — builds `object_story_spec.link_data` from headline/primary_text/image_url.
4. `POST /{ad_account_id}/ads` — attaches the creative to the ad set.

All four calls are atomic at the DB layer — nothing is persisted to the local `ad_campaigns` table unless every Meta call succeeds; a mid-chain failure is logged with the orphaned Meta object IDs for manual cleanup rather than silently swallowed. Pause/resume/insights (spend, impressions, leads, CPL) are also implemented and feed the auto-pause guard the frontend's CPL-threshold control drives.

**[Fact] Gap 1 — Click-to-WhatsApp is not implemented.** The `MESSAGES` objective sets:
```php
$body['destination_type'] = 'MESSENGER';
```
This is Click-to-**Messenger**. A genuine Click-to-WhatsApp Ads campaign requires `destination_type => 'WHATSAPP'` plus a connected WhatsApp Business phone number as the promoted object (not the Facebook Page used here). Nothing in this codebase builds that — the "Click-to-WhatsApp" capability named in your brief does not exist under any objective today, despite this platform's core product being WhatsApp-centric.

**[Fact] Gap 2 — no Lead Form (Instant Form) object is created or referenced.** `LEAD_GENERATION` campaigns set `destination_type => 'ON_AD'` (Meta's on-ad Instant Form flow) but never create a `leadgen_form` object (`POST /{page-id}/leadgen_forms` or `/{ad_account_id}/adleadgenforms`) and never attach a `lead_gen_form_id` to the ad creative's call-to-action. **[Hypothesis]**, consistent with Meta's documented contract and un-contradicted by anything in this codebase: an ON_AD lead-generation ad creative without a referenced form ID is rejected by Meta's API at creative-validation time. This is very likely to fail on the first live attempt, not a cosmetic gap — the whole point of "Lead Form campaigns" is a tenant-defined question set, and there is currently no way to define, create, or attach one.

**[Fact] Also disclosed by the code itself (not hidden, worth repeating here):** video creative is unsupported — only a public `image_url`, no `/adimages` or `/advideos` upload step; city/region geo-targeting is unsupported (country-code only); and the class carries an explicit `ads_management` OAuth-scope migration note — any tenant who connected their Ad Account before this phase must reconnect before campaign creation will work at all.

---

## 3. Dynamic Multi-Industry Prompt Engine

**[Fact] Provider: not Gemini Pro.** `app/Services/Ai/CopywriterService.php::generate()` tries OpenAI (`gpt-4o-mini` default) → Anthropic (`claude-3-5-haiku-latest` default) → a deterministic hand-written template bank, in that order, based on which API key is configured. A repo-wide search for `gemini`/`generativelanguage` returns **zero matches** anywhere in `backend-api/` — not in code, not in `config/services.php`, not in `.env.example`. There is no Gemini integration to audit; it was never built.

**[Fact] Tokens: 2 of 3 requested, and one is mislabeled.**
```php
public function generate(string $productName, string $targetIndustry, string $tone): array
```
Only `target_industry` and (nominally) `product_name` are dynamic business-context tokens; `offer_details` and `target_goal` do not exist anywhere in this method's signature, its prompt template, or the frontend payload (`GenerateAdCopyPayload = { product_name, target_industry, tone }`). Worse — the frontend never collects a real product/service name; it sends `form.campaign_name` (the *campaign's* internal name) as `product_name`. There is no field capturing "what are you offering" or "what's the campaign's goal" distinct from industry, so the AI has materially less to work with than the strategy calls for.

**[Fact] Positive: the mechanism itself genuinely is industry-agnostic.** There is no hardcoded `if ($industry === 'real_estate')`-style branching anywhere — `target_industry` is free text, interpolated directly into the LLM prompt (`"Target industry: \"%s\""`) and into the template-fallback strings (`{industry}` placeholder). A tenant can type "Gym," "Coaching," "Healthcare," or anything else and the code path is identical; nothing needs to be added per-industry. **This part of the strategy is honored** — the gap is provider choice and token breadth, not hardcoding.

**Verdict:** **[B] Partially built.** Dynamic-by-design ✅. Right provider ❌. Full token set ❌.

---

## 4. Lead Data Pipeline

**[Fact] Meta Lead Ads (Instant Form) → `leads` table: fully built and genuinely well-designed.** `app/Services/Leads/MetaLeadWebhookHandler.php`, wired from `SocialWebhookController`'s `leadgen` change handler:
- Fetches the full `field_data` from Meta for the `leadgen_id`, best-effort maps name/phone/email by field-name substring match, and keeps the **entire raw payload** in `leads.raw_field_data` so no custom-question data is ever lost even when the heuristic misses.
- Two real dedup guards: a DB-unique `provider_lead_id` (safe against Meta's webhook-retry redelivery) and an app-level "same phone within 24h for this tenant" business rule.
- On a new lead: writes the `Lead` row, then fires **two** WhatsApp sends — a notification to the tenant's own number and an auto-welcome to the lead — each independently tracked (`tenant_notified_at`/`lead_welcomed_at` + their own error columns), so a WhatsApp-send failure never loses the lead record itself.
- `LeadController` exposes this read-only at `GET /api/social/leads` (search, pagination, tenant-scoped).

**[Fact] Click-to-WhatsApp inbound → `leads` table: not built.** This is a structurally different event from a `leadgen` webhook (CTWA never fires one) — a CTWA click produces a normal inbound WhatsApp message carrying a `referral` object (`source_id`/ad ID, `source_type: "ad"`, headline, media). Grepping the entire backend for `referral`/`ctwa` returns **zero matches**. `MetaWebhookController::handleInboundMessages()` reads only `message.from`/`type`/`text.body` and hands it straight to the generic `ChatbotEngineService` — a CTWA-originated message today is indistinguishable from an organic WhatsApp message; it creates no `Lead` row and carries no ad attribution. This is consistent with §2b's finding that CTWA campaigns aren't launchable in the first place — there is nothing on the inbound side to catch, because nothing on the outbound side produces it yet.

**[Fact] Two adjacent, self-disclosed gaps worth flagging since they sit on this exact pipeline:** (1) `MetaWebhookController` does not verify Meta's `X-Hub-Signature-256` header — the inbound webhook endpoint is public and trusts payload shape alone, not a signed payload (the class's own docblock calls this out as a pre-production hardening item). (2) `qr-engine-service` has no inbound-message listener wired to `POST /api/internal/whatsapp-inbound` at all yet — inbound message handling (chatbot and any future lead capture) only works for tenants on the Meta Cloud API engine, not the Baileys/QR engine.

---

## 5. Actionable Gap Analysis

### [A] 100% built and ready
- Meta Lead Ads (Instant-Form-submission) → `Lead` row → dual WhatsApp notify (tenant + lead) — `MetaLeadWebhookHandler`, `LeadController`, `leads` migration.
- Paid campaign creation chain (Campaign→AdSet→AdCreative→Ad) for `TRAFFIC` and (mechanically) `LEAD_GENERATION`/`MESSAGES` objectives, with correct budget-unit conversion, interest-keyword resolution, pause/resume, and insights-driven CPL auto-pause — `MetaAdsService`, `AdCampaignController`.
- Meta/FB/IG/LinkedIn account connection via OAuth, asset selection, and disconnect — `SocialAuthController`, `AssetSelectionModal.tsx`.
- Industry-agnostic prompt mechanism (no hardcoded per-industry branching) with a genuine provider-fallback chain that degrades gracefully instead of 500ing — `CopywriterService`.
- AI-copy apply-then-manually-edit flow in the wizard (headline/primary text become plain editable inputs after a variant is applied) — `MetaAdsPage.tsx`.

### [B] Partially built / mock-adjacent / will likely fail live
- **AI Copywriter provider** — built, but on OpenAI/Anthropic/template, not Gemini Pro as the strategy specifies. Functionally complete, wrong vendor.
- **Prompt token surface** — `product_name` (actually campaign name) + `target_industry` only; `offer_details` and `target_goal` are unimplemented.
- **LEAD_GENERATION campaigns** — the whole Campaign/AdSet/Creative/Ad chain runs, but with no `leadgen_form` object created/attached; **[Hypothesis]**, high-confidence: Meta will reject the ad creative at validation. Untestable without a live Ad Account, and disclosed as untested by the code itself.
- **"Regenerate" AI copy** — works, but only as an unlabeled re-click of Generate, not a distinct, discoverable action.
- **Image creative** — a public URL only, never previewed, never uploaded, never validated as reachable before launch.

### [C] Must be written next — exact files
To close the loop end-to-end (upload → preview → edit → publish, both organic and paid, any industry, with WhatsApp-attributed leads), in priority order:

1. **Media upload endpoint + storage.** New: `backend-api/app/Http/Controllers/Api/SocialMediaController.php` (or extend `AdCampaignController`) accepting `multipart/form-data`, storing to `FILESYSTEM_DISK` (already configured), returning a durable URL — replacing the free-text `image_url` field in `frontend-app/src/pages/social/MetaAdsPage.tsx`. Needed before video support (Meta's `/advideos` upload) can be added to `MetaAdsService::createAdCreative()`.
2. **Live ad preview component.** New: `frontend-app/src/components/social/AdPreview.tsx` — a lightweight FB-Feed/IG-Story mock rendered from the wizard's live `form` state (image + headline + primary text), inserted into `MetaAdsPage.tsx`'s step 2 alongside the existing AI-copy panel. Pure frontend, no backend dependency.
3. **Switch the copy engine to Gemini Pro** (or add it as a third/first provider) in `backend-api/app/Services/Ai/CopywriterService.php` — mirror the existing `generateWithOpenAi`/`generateWithAnthropic` pattern with a `generateWithGemini()` method and a `config/services.php` `'gemini' => ['key' => env('GEMINI_API_KEY')]` entry; extend `generate()`'s signature (and `AICopywriterController`'s validation + `frontend-app/src/types/ai.ts` `GenerateAdCopyPayload`) to accept `offer_details` and `target_goal` alongside `target_industry`.
4. **Organic Publish pipeline — new, ground-up.** Backend: new `app/Services/Social/OrganicPublishService.php` with per-provider drivers (`POST /{page-id}/feed` for FB, the two-step `/media`→`/media_publish` for IG, `ugcPosts` for LinkedIn) plus a new `OrganicPostController` and `organic_posts` migration/model (mirroring `AdCampaign`'s shape). Frontend: a new mode toggle in `MetaAdsPage.tsx` (or a sibling `OrganicPostPage.tsx`) — "Organic Post" vs "Paid Campaign" — since today's wizard only ever produces a paid campaign.
5. **Click-to-WhatsApp objective — new.** In `MetaAdsService::createAdSet()`, add a real `destination_type => 'WHATSAPP'` branch keyed off a new `AdObjective` value (`CLICK_TO_WHATSAPP`), promoting the tenant's connected WhatsApp number (not the Page) as the promoted object; update `AdCampaign::OBJECTIVES`, `AdCampaignController`'s validation `Rule::in`, and `frontend-app/src/types/ads.ts`'s `AdObjective` union + `AD_OBJECTIVE_LABELS`.
6. **Lead-Form (Instant Form) builder — new.** Backend: a `leadgen_form_id` reference step in `MetaAdsService::createAdCreative()` (call `POST /{page-id}/leadgen_forms` with tenant-defined questions before building the creative, or let the tenant pick an existing form via `GET /{page-id}/leadgen_forms`). Frontend: a form-question builder step in the wizard, or a form-picker if reusing existing Meta forms is acceptable for v1.
7. **CTWA-attributed lead capture.** In `MetaWebhookController::handleInboundMessages()`, read `message.referral` when present and, on a match, create a `Lead` row (`provider = 'whatsapp_ctwa'`) via a small extension to `MetaLeadWebhookHandler` (or a sibling handler) instead of routing straight to the generic chatbot — this is what makes gap #5 above actually produce trackable leads once built, not just billable clicks.

Items 1–3 are additive and low-risk (no existing behavior changes). Items 4–7 are new capabilities with no existing code to regress against — safe to build incrementally in the order listed, since each is independently shippable and none blocks the others except #7 depending on #5.

---

*No files were modified and no calls were made against a live Meta account, database, or either service's running state during this audit.*
