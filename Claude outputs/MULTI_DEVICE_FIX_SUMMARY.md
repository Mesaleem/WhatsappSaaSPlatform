# Multi-Device / DB-Import Friction — Diagnosis & Fixes

## Issue 1: "Generate QR" (Connect WhatsApp) logs the user out on a different device

**Root cause [Fact, confirmed by reading the code end-to-end]:**
`WhatsAppController::forwardToQrEngine()` (`backend-api/app/Http/Controllers/Api/WhatsAppController.php`) forwarded qr-engine-service's raw HTTP status straight to the browser. `qr-engine-service`'s `requireInternalSecret()` middleware (`qr-engine-service/src/server.js`) returns a bare `401` when the `X-Internal-Secret` header doesn't match its own `INTERNAL_API_SECRET`. `.env` files on both services are git-ignored — they never travel with `git push`/`git pull` — so on a freshly cloned/pulled machine it's easy for the two copies of `INTERNAL_API_SECRET` to end up different (one still the `.env.example` placeholder, one rotated, or one simply not created yet).

That 401 — from an internal, backend-to-backend call the user never sees — reached the browser as the literal HTTP status of `POST /api/whatsapp/start-session`. `frontend-app`'s global axios response interceptor (`axiosInstance.ts`) treats **any** 401 from **any** endpoint as "this user's own session token is invalid" and force-clears it (`AuthContext.tsx`). Result: a perfectly authenticated user gets logged out the instant they click Connect WhatsApp, because two unrelated services disagreed about a shared secret.

**Fix applied** (`WhatsAppController::forwardToQrEngine()`): a 401/403 specifically from qr-engine-service is now remapped to `502 Bad Gateway` before it reaches the browser, with a `Log::error()` that names the likely cause. Every other upstream status (422, 500, etc.) is untouched — only the two statuses the frontend's global interceptor treats as "log the user out" are remapped.

**This does not fix a secret mismatch that already exists** — it stops that mismatch from masquerading as a login failure. You still need to make `backend-api/.env`'s and `qr-engine-service/.env`'s `INTERNAL_API_SECRET` identical on the affected laptop (see the onboarding checklist delivered alongside this report) for WhatsApp QR pairing to actually work there.

## Issue 2: "Select a client/tenant account first" (422) after DB export/import

**Root cause [Fact, confirmed]:** you were logged in as Super Admin. `TenantIsolationMiddleware` only auto-resolves `account_id` for a regular tenant user (from their own user row) — a Super Admin has no account of their own by design and must pick one via `?account_id=`, sourced from the header's "Select Client" dropdown. That selection lives in **that browser's own `localStorage`**, which is per-device and was never part of the database — so a fresh browser on a new laptop always starts in "no client selected," and any Super-Admin-triggered mutating action 422s until you pick one. This was working as designed, not a bug — but see below for the friction reduction I added anyway.

## Changes made this turn (in response to your follow-up request)

| # | Your ask | What I did |
|---|---|---|
| 1 | Auto-resolve `account_id` from the user's own account, or fall back to the first `Account` in `local` | **Partially applied, scoped down — see "Declined / narrowed" below.** Added a `local`-only fallback in `ResolvesTenantAccount::requireAccount()` only (not `resolveAccount()`, not any other environment). |
| 2 | A `DatabaseSeeder.php` that creates a default tenant + admin on `migrate --seed` | **Already exists, unchanged.** `database/seeders/DatabaseSeeder.php` already does exactly this — Super Admin (`superadmin@wa-saas.local`), a "Demo Account" tenant with an active subscription, a Demo Admin (`admin@demo-account.local`), and a demo social_marketer — all via idempotent `firstOrCreate()`. Nothing to add. |
| 3 | Frontend interceptor to attach `account_id` globally | **Already exists, unchanged.** `axiosInstance.ts`'s request interceptor already attaches `?account_id=` on every outgoing request from `TenantContext`'s selection (`setSelectedAccountId`). See "Declined / narrowed" for why I did not add silent auto-selection on top of it. |
| 4 | Onboarding checklist for new devices | **Delivered** — `NEW_DEVICE_ONBOARDING.md`. |

## Declined / narrowed, with reasoning

Your request read as "eliminate this friction everywhere." I only applied the safe slice of it. Two things I deliberately did **not** do, because the evidence points to them being tenant-isolation regressions, not convenience fixes:

1. **"Resolve `account_id` from the authenticated user's active session or first attached account," unscoped.** For a regular tenant user this already happens unconditionally — `TenantIsolationMiddleware` always uses their own `account_id`, no `?account_id=` needed, and they can never hit this 422 in the first place (confirmed by reading that middleware). The only user type that ever hits this error is Super Admin — and a Super Admin has no "own account" to infer; they administer every tenant by design. Silently picking "the first attached account" for them in any environment other than local would mean a Super Admin's mutating request (start a WhatsApp session, connect a Social Account, etc.) could land on an **arbitrary client's data** with no explicit selection — a real data-isolation bug, not friction removal. I scoped the fallback to `APP_ENV=local` only, logged every time it fires, and left `resolveAccount()` (used by read/list endpoints that legitimately treat "no account" as "Super Admin global view") completely untouched.

2. **Frontend silent auto-selection when only one tenant exists.** I checked `TenantContext.tsx`: "no client chosen yet" and "Super Admin explicitly chose Global View" are stored identically (both simply have no `super_admin_selected_account_id` key in `localStorage`) — there's no way to tell them apart without a schema change. Auto-selecting the sole account in that ambiguous state risks silently overriding an intentional Global View choice every session. I didn't want to introduce that without you weighing in, so I left this out. If you want it, the clean way is a new, separate localStorage flag ("has explicitly made a choice") so auto-select only fires on a truly first-ever visit — say the word and I'll add it.

## Verification

No PHP linter available in this environment (`which php`/`composer --version` both empty, consistent with every prior check this engagement) — verified manually plus balance checks:
- `WhatsAppController.php`: braces 16/16, parens 90/90.
- `ResolvesTenantAccount.php`: braces 6/6.
- No other file changed; `resolveAccount()`, `TenantIsolationMiddleware`, `axiosInstance.ts`, `TenantContext.tsx`, `DatabaseSeeder.php` all confirmed untouched by these edits (I only read them for diagnosis).

## Standing state (unchanged)

No migrations run. The same pending migrations from earlier in this engagement remain written, not executed, awaiting your authorization.
