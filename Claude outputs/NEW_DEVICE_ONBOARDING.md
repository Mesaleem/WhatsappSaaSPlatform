# New Device / Fresh Database Onboarding Checklist

For pulling `wa-saas-platform` onto a new laptop, or importing a DB export from another machine.

## 1. Backend `.env` — two files must be IDENTICAL for WhatsApp QR pairing to work

`.env` is git-ignored on both services, so it never travels with `git pull`. On the new machine:

```
backend-api/.env.example        → copy to backend-api/.env
qr-engine-service/.env.example  → copy to qr-engine-service/.env
```

**`INTERNAL_API_SECRET` must be byte-identical in both files.** If you generate/rotate this value, update it in both places and restart both services. A mismatch here is what causes "clicking Connect WhatsApp logs me out" (see the diagnosis in this session's chat — `backend-api` used to forward qr-engine-service's raw 401 straight to the browser, which the frontend's global interceptor mistook for an invalid login; this is now remapped to a 502 so it fails loudly instead of logging you out, but the underlying secret mismatch still needs fixing for QR pairing to actually work).

## 2. Database — migrate + seed, not just migrate

```
php artisan migrate --seed
```

`DatabaseSeeder.php` already creates, idempotently (`firstOrCreate`, safe to re-run):
- A Platform Super Admin: `superadmin@wa-saas.local` / `ChangeMe!123`
- A "Demo Account" tenant with an active `qr`-engine subscription
- A Demo Account Admin: `admin@demo-account.local` / `ChangeMe!123`
- A Demo Social Marketer: `marketer@demo-account.local` / `password`

**Change these passwords before anything touches a real network** — they're committed in plaintext in the seeder for local dev convenience only.

If you imported a raw SQL dump instead of running the seeder, you already have whatever real Account/User rows existed on the source machine — the seeder step isn't needed in that case (skip step 2, your imported data already has tenants).

## 3. First login as Super Admin — pick a client

The header's "Select Client" dropdown selection is stored in **that browser's own `localStorage`** (`super_admin_selected_account_id`) — it does not travel with a `git pull` or a DB export/import, because it never lived in the database or the repo to begin with. On a fresh browser on a new machine, a Super Admin always starts in "no client selected" (global view).

**Any Super-Admin-triggered action that mutates a specific tenant's data (connecting a Social Account, generating a WhatsApp QR for a client from the Admin Device table, etc.) requires picking a client from that dropdown first** — this is enforced server-side (`ResolvesTenantAccount::requireAccount()`, `422 Select a client/tenant account first`) and is intentional tenant-isolation behavior, not a bug.

As of this session, `requireAccount()` also has a **local-only** fallback: if `APP_ENV=local` and no account was resolved, it uses the first `Account` row instead of aborting (logged every time it fires, via `Log::warning`). This exists purely to smooth local dev friction — it is disabled in every other environment, and does not change what "Global View" (read/list) endpoints do.

## 4. Frontend `.env`

```
frontend-app/.env.example → copy to frontend-app/.env
```

Check `VITE_API_BASE_URL` and `VITE_QR_ENGINE_WS_URL` point at wherever `backend-api` and `qr-engine-service` are actually reachable from THIS machine (defaults assume `localhost:8000` / `localhost:4000` — fine for same-machine dev, wrong if you're pointing a frontend dev server at services running elsewhere on your LAN).

## 5. Quick sanity check after setup

1. `php artisan migrate:status` — confirm no pending migrations (or note which ones you're deliberately holding back).
2. Log in as `superadmin@wa-saas.local`.
3. Header → Select Client → "Demo Account" (or your real imported tenant).
4. Visit WhatsApp Setup → Connect WhatsApp. If this still fails after step 1, check the `Log::error` line the QR fix now writes on any qr-engine-service 401/403 — it names the exact cause (internal secret mismatch).
