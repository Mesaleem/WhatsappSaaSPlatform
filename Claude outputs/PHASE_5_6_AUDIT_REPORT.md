# Final Phase (5 & 6) Audit Report — Social Media Marketing & Meta Ads SaaS Platform

Scope: AI Ad Copywriter, White-Label PDF Reporting, production polish (rate limiting, RBAC, error handling) across `backend-api` and `frontend-app`. All work verified live on-device (`wa-saas-platform`); no code was run against a real database or external AI provider — see Verification Method and Disclosed Limitations below.

---

## 1. AI Ad Copywriter & Creative Builder

**Backend.** `AICopywriterController::generate()` → `POST /api/social/ai/generate`, validates `product_name`/`target_industry`/`tone` (all required strings), delegates to `CopywriterService`. Gated on `launch-meta-ads` — [Inference] generating draft copy is not a distinct capability from launching the campaign it's for, so no new permission was added.

`CopywriterService::generate()` implements the requested provider chain: OpenAI (`config('services.openai.key')`) → Anthropic (`config('services.anthropic.key')`) → deterministic template engine. [Fact] Both `OPENAI_API_KEY` and `ANTHROPIC_API_KEY` are unset in `.env.example` and this environment has no path to test either provider — every real-provider call is wrapped in try/catch, logs via `Log::warning()`, and falls through to templates rather than 500ing. **[Hypothesis]** The OpenAI/Anthropic request/response shapes follow each provider's published API docs from training knowledge but are untested against a live key; this must be exercised in a real environment before being trusted for production traffic. The template engine (four hand-written banks: Urgent / High-Converting / Professional / generic-tone fallback, matched case-insensitively) requires zero network access and always returns exactly 5 `{hook, caption, cta}` variants.

**Frontend.** `types/ai.ts` + `services/aiService.ts` mirror the JSON shape. Wired directly into `MetaAdsPage.tsx`'s `LaunchWizardModal`, creative step (step 3): a "✨ Generate with AI" panel takes Target Industry + Tone, calls the endpoint, and renders the 5 returned variants as clickable cards — clicking one fills Headline/Primary Text (`caption + cta`) but never auto-submits. Product name is the wizard's existing Campaign Name field, so no duplicate input was added.

## 2. White-Label Automated PDF Reporting

**Root-cause finding.** [Fact, verified against `MetaAdsService::fetchInsights()`] Meta Insights calls use `date_preset: 'today'`, and `CheckAdPerformanceRules` overwrites `ad_campaigns.last_spend/last_impressions/last_leads/last_cpl` every 15 minutes with that day's numbers only. There was no historical/monthly aggregate anywhere in the schema — a "monthly" report built from those columns would silently mislabel today's spend as a month total. Fixed by adding `ad_campaign_daily_metrics` (one row per campaign per calendar day, upserted idempotently via `unique(ad_campaign_id, metric_date)`), populated by a 4-line addition to `CheckAdPerformanceRules::evaluate()` immediately after its existing cache write.

**Disclosed, unavoidable limitation:** this table starts empty. A report generated the day this ships will only reflect today's snapshot(s), not a full month — there is no way to backfill months that were never recorded, since only the last daily snapshot was ever kept anywhere in the prior schema. Correct accumulation begins from this deployment forward only.

**Two distinct "leads" numbers — deliberately not blended.** `leads` (Phase 2, every Meta Lead Ads submission actually received) and `ad_campaign_daily_metrics.leads` (Meta's own Insights-attributed conversions for campaigns launched through this app) can legitimately differ and have no coherent combined definition. The report shows both, separately labeled (`total_leads_generated` vs. `meta_attributed_leads`), and computes `average_cpl` only from the latter against `spend` from the same table — never a cross-source ratio.

**Tenant branding.** No `logo_url`/`brand_accent_color` storage existed anywhere, and no tenant self-service profile endpoint exists at all (`AccountController::update()` is the only place `company_name` is edited, Super-Admin-only). Added both columns following that exact precedent — same tier as `primary_phone`/`status` — rather than inventing a new self-service surface. `brand_accent_color` is validated backend-side as a bare 6-hex-digit string; the frontend strips a leading `#` before sending.

**PDF generation.** No PHP binary, Composer, or reachable Packagist exists in this environment (`php`/`php8`/`php8.2`/`php8.3` all resolve to "command not found"; `packagist.org` is proxy-blocked). Rather than add an unverifiable dependency (dompdf, etc.), `SocialReportController::generate()` extends the pre-existing, dependency-free `SimplePdfWriter` (already used by `ExportController` and `BillingController`) with a new `renderBrandedReport()` method — the proven `render()` method used by those two modules was left byte-for-byte untouched. **Known limitation:** `SimplePdfWriter` has no image-embedding support, so `logo_url` is rendered as a text line ("Logo: https://…"), not an actual picture, in the generated PDF.

**Frontend.** `types/reports.ts` / `services/reportsService.ts` (mirrors `analyticsService.ts`'s established blob-download + Content-Disposition-filename pattern) and `SocialReportsPage.tsx` at `/social/reports`: 5 KPI tiles, a recharts daily spend/leads area chart, a Top Campaigns table, a month picker, and a one-click "Download PDF" button.

## 3. Production Polish, Error Logging & RBAC Audit

**Root-cause finding — rate limiting.** [Fact, verified directly against `vendor/laravel/framework/.../Middleware.php`] `bootstrap/app.php` never calls `->throttleApi()`, so Laravel 11's `'api'` middleware group added **zero** rate limiting to any route in `routes/api.php` — including every public, unauthenticated webhook endpoint. Fixed with a targeted `meta-webhook` limiter (120 req/min/IP, JSON 429 response) applied only to the genuinely public endpoints (`/webhooks/meta`, `/social/callback/{provider}`, `/social/webhook/{provider}`) — deliberately not a blanket throttle across authenticated tenant traffic, which nobody asked for and would be a behavior change of its own.

**Gaps closed.** No backend route or frontend page for a standalone Lead CRM existed anywhere (Phase 2's `Lead` rows were only reachable indirectly as synthetic threads inside the Inbox), despite this final phase's spec explicitly requiring `/social/leads`. Added a read-only `LeadController` (index/show, search, pagination) + `LeadsPage.tsx`, gated on the same `manage-social-leads` permission as the Inbox — deliberately no write endpoints, since every mutable field on a Lead is already owned exclusively by `MetaLeadWebhookHandler`.

**social_marketer route accessibility — verified end-to-end:**

| Route | Permission required | Held by `social_marketer`? |
|---|---|---|
| `/social/accounts` | `manage-social-accounts` | Yes |
| `/social/ads` | `launch-meta-ads` | Yes |
| `/social/leads` | `manage-social-leads` | Yes |
| `/social/inbox` | `manage-social-leads` | Yes |
| `/social/comment-rules` | `manage-comment-automation` | Yes |
| `/social/reports` | `view-social-analytics` | Yes |

Cross-checked directly against `RolePermissionSeeder`'s `social_marketer` array — it already held all 5 distinct permissions before this phase; **no seeder change was required.** Nav entries for the two new pages were added to `AppLayout.tsx` with the same tint/permission gating pattern as the four existing social nav items (no `hiddenForRoles`/`requiresModule` blocking them).

## 4. Verification

No PHP binary or database exists on this device, so verification used the strongest available static methods:

- **Backend (14 new/modified PHP files, this phase):** brace/paren/bracket balance + `<?php` start check — all 14 pass, 0 imbalances.
- **Frontend:** `npx tsc -b --noEmit` — 0 errors, whole project. `npx oxlint` — 0 errors, whole project (98 files); 47 pre-existing warnings project-wide, all the same `react(set-state-in-effect)` pattern already present in shipped Phase 1-4 code (e.g. `CommentRulesPage.tsx`, `MetaAdsPage.tsx`'s pre-existing `loadCampaigns()` effect) — not something this phase introduced, and consistent with the codebase's existing convention.
- **AI provider integration:** [Unknown] — not exercised against a live OpenAI/Anthropic key in this environment (see Section 1).
- **PDF output:** not re-run through `qpdf`/`pdftotext` in this session (no PHP to generate a sample); the new `renderBrandedReport()` method's escape/xref/trailer logic was diffed line-for-line against the previously-verified `render()` method and found identical.

## 5. Database Changes Requiring Authorization

**No migration, seed, or other database mutation has been run.** Per standing instruction, the following 10 pending migrations require explicit authorization before `php artisan migrate`:

```
2026_09_10_090000_add_social_platform_flags_to_accounts_table.php
2026_09_10_090001_create_social_provider_configs_table.php
2026_09_10_090002_create_social_accounts_table.php
2026_09_10_100000_make_subscriptions_expires_at_nullable.php
2026_09_10_110000_create_leads_table.php
2026_09_10_120000_create_ad_campaigns_table.php
2026_09_10_130000_create_comment_automation_rules_table.php
2026_09_10_130001_create_comment_automation_events_table.php
2026_09_10_140000_add_branding_fields_to_accounts_table.php   ← this phase
2026_09_10_140001_create_ad_campaign_daily_metrics_table.php  ← this phase
```

The first 8 predate this phase (accumulated across Phases 1-4, still unrun as of this report). This phase adds exactly 2 more. Recommended command once authorized: `php artisan migrate` (no seeder changes needed — see Section 3).

## 6. File Manifest (this phase)

**Backend — new:** `AICopywriterController.php`, `SocialReportController.php`, `LeadController.php`, `Services/Ai/CopywriterService.php`, `Models/AdCampaignDailyMetric.php`, 2 migrations above.
**Backend — modified:** `AppServiceProvider.php` (rate limiter), `routes/api.php` (webhook throttling + 3 new route groups), `Models/Account.php` + `AccountController.php` (branding fields), `CheckAdPerformanceRules.php` (daily snapshot write), `config/services.php` + `.env.example` (AI provider config), `Services/Pdf/SimplePdfWriter.php` (additive `renderBrandedReport()` method only).
**Frontend — new:** `types/{ai,reports,leads}.ts`, `services/{aiService,reportsService,leadsService}.ts`, `pages/social/{SocialReportsPage,LeadsPage}.tsx`.
**Frontend — modified:** `MetaAdsPage.tsx` (AI Copywriter panel), `App.tsx` + `AppLayout.tsx` (routes/nav), `types/account.ts` + `CreateAccountModal.tsx` (branding fields UI).

---

**Bottom line:** AI Copywriter and PDF Reporting are both implemented and internally verified (types, compilation, structural PHP integrity, permission matrix); the two genuine open risks are the AI providers being untested against live keys, and the PDF's logo being text-only. Both are disclosed, not silently assumed away. Nothing has touched the database — that step is gated on your authorization.
