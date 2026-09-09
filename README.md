# WhatsApp SaaS Platform (`wa-saas-platform`)

A multi-tenant SaaS platform that lets businesses connect a WhatsApp number (via QR pairing or the official Meta Cloud API), send transactional payment alerts and bulk campaigns, run a no-code chatbot rule engine, and track usage against a subscription quota — with per-tenant billing, an external Developer API, and outbound webhooks.

The system is composed of three independently-run services:

| Service | Role | Stack |
|---|---|---|
| **`backend-api/`** | REST API, auth, billing, quota, job orchestration — the system of record | Laravel 11 (PHP 8.2+) |
| **`frontend-app/`** | Tenant & Super Admin web console (SPA) | React 19 + TypeScript + Vite + Tailwind |
| **`qr-engine-service/`** | Maintains live WhatsApp Web (Baileys) sessions and sends/receives messages for accounts on the "QR" engine | Node.js + Express + Socket.IO + `@whiskeysockets/baileys` |

> **Note on naming:** the frontend package is `frontend-app`, not `frontend` — this document (and every command below) uses the real folder names as they exist in this repository.

---

## 1. Overview

### 1.1 Architecture summary

`backend-api` is the single source of truth: every tenant, subscription, quota counter, and message record lives there. It never talks to WhatsApp directly. Instead, each tenant account is configured with one of two **engines**:

- **`qr` engine** — `backend-api` calls `qr-engine-service` over an internal, shared-secret-authenticated HTTP API. `qr-engine-service` holds the actual Baileys (WhatsApp Web multi-device) socket per account and is the only process that talks to WhatsApp's servers for that engine.
- **`meta` engine** — `backend-api` calls Meta's official WhatsApp Cloud API directly (no Node service involved).

`frontend-app` is a pure SPA: it authenticates against `backend-api` (Laravel Sanctum Bearer tokens) for everything, and opens a **separate, direct Socket.IO connection** to `qr-engine-service` only for the live QR-pairing UI (so the pairing status updates in real time without polling).

```
┌───────────────┐        Bearer token (Sanctum)        ┌──────────────────┐
│  frontend-app │ ────────────────────────────────────▶│    backend-api    │
│  (React SPA)  │◀──────────────────────────────────── │     (Laravel)     │
└───────┬───────┘              JSON REST                └─────────┬────────┘
        │                                                          │
        │ Socket.IO (live QR / connection status)     X-Internal-Secret
        │                                                          │
        ▼                                                          ▼
┌────────────────────────────────────────────────────────────────────┐
│                        qr-engine-service (Node)                    │
│           Baileys multi-device sockets, one per account_id         │
└────────────────────────────────────────────────────────────────────┘
                                   │
                                   ▼
                          WhatsApp (via Baileys)
```

### 1.2 Core features

- **WhatsApp account connection** — QR pairing (Baileys, `qr` engine) or Meta Cloud API credential vault (`meta` engine), one active engine per tenant.
- **Payment alerts** — single-send and CSV bulk-upload, with `payment_ref` deduplication, quota deduction, and per-account anti-ban send pacing.
- **Mass / bulk messaging** — CSV bulk-upload path in `PaymentAlertController::bulkUpload()`, plus an external Developer API endpoint (`POST /api/v1/messages/send-payment-alert`) for server-to-server integrations.
- **Live QR preview** — real-time pairing status and QR code refresh over Socket.IO, no polling.
- **Quota management** — per-subscription `used_messages` / `total_allocated_messages` counters, enforced before every send, with an `unlimited` billing tier.
- **Chatbot rule engine** — keyword/intent-based auto-reply rules with an execution log.
- **Billing & payments** — Razorpay/Stripe checkout, invoices, and platform-level gateway credential management (Super Admin).
- **Analytics & exports** — usage KPIs/charts, per-message logs (row-level, permission-gated separately from aggregate analytics), CSV/PDF export.
- **Developer Portal** — external API keys and outbound webhook subscriptions (with delivery logs) so tenants can integrate the platform into their own systems.
- **Multi-tenancy & RBAC** — every tenant-scoped route runs through `tenant.isolation` + `subscription.guard`, with `spatie/laravel-permission` roles/permissions (Super Admin, Admin, User, and any dynamic role an Admin creates) on top.

---

## 2. System Architecture & Tech Stack

### Frontend (`frontend-app/`)
| | |
|---|---|
| Framework | React 19.2 + TypeScript, built with Vite 8 |
| Routing | `react-router-dom` 7 (nested layout route + `<Outlet/>`) |
| Styling | Tailwind CSS 3.4 |
| Icons | `lucide-react` |
| HTTP | `axios` (Bearer token interceptor, global 401 / subscription-blocked event dispatch) |
| Realtime | `socket.io-client` (QR pairing status) |
| Charts | `recharts` |
| Payments UI | `@stripe/react-stripe-js` / `@stripe/stripe-js` |
| Lint | `oxlint` (not ESLint) |

### Backend API (`backend-api/`)
| | |
|---|---|
| Framework | Laravel 11.31, PHP ^8.2 |
| Auth | Laravel Sanctum 4.3 (Bearer personal access tokens — **not** cookie/SPA session mode) |
| Authorization | `spatie/laravel-permission` 6.25 (roles + permissions) |
| Queue / Jobs | Laravel Queue (`ProcessPaymentAlertJob`, `DispatchWebhookJob`) |
| REST | `routes/api.php` — versioned external API under `/api/v1/*`, internal service-to-service routes under `/api/internal/*` |

### WhatsApp Engine (`qr-engine-service/`)
| | |
|---|---|
| Runtime | Node.js (ESM, `"type": "module"`) |
| HTTP | Express 5 |
| Realtime | Socket.IO 4 (per-tenant room `account:{id}`, auth'd via a call back to `backend-api`'s `/api/auth/me`) |
| WhatsApp | `@whiskeysockets/baileys` ^7.0.0-rc14 (multi-device Web API, unofficial) |
| Dev runner | `nodemon` (watches `src/`, ignores `sessions/` so a live pairing handshake is never interrupted by a file-watcher restart) |

### Database & Cache
- **Local development default:** SQLite (`DB_CONNECTION=sqlite`, zero setup — `database/database.sqlite`). This is what ships in `.env.example`.
- **Recommended for staging/production:** MySQL — the connection variables are already present (commented out) in `.env.example`; uncomment and point them at a real MySQL instance.
- **Redis:** the client and connection env vars (`REDIS_HOST`, `REDIS_PORT`, …) are present, but **not wired as the default** cache/session/queue store — `CACHE_STORE` and `SESSION_DRIVER` default to `database`. For production, switch `CACHE_STORE`, `SESSION_DRIVER`, and `QUEUE_CONNECTION` to `redis` (see §6 and §7).

---

## 3. Directory & Repository Structure

```
wa-saas-platform/
├── README.md                      ← this file
│
├── backend-api/                   Laravel REST API
│   ├── app/
│   │   ├── Http/Controllers/Api/  All REST endpoints (Admin/, Internal/, V1/ sub-namespaces)
│   │   ├── Jobs/                  ProcessPaymentAlertJob, DispatchWebhookJob
│   │   ├── Models/                Account, User, Subscription, PaymentAlert, WhatsAppSession, …
│   │   ├── Services/
│   │   │   ├── WhatsApp/          WhatsAppEngineFactory, BaileysDriver, MetaCloudApiDriver
│   │   │   ├── PaymentAlerts/     PaymentAlertDispatcher (shared dedup/create/queue logic)
│   │   │   ├── Payment/           Razorpay / Stripe gateway drivers
│   │   │   ├── Webhooks/          Outbound webhook dispatch + delivery
│   │   │   ├── Billing/, Chatbot/, Pdf/
│   │   │   └── Support/           Small static helpers (PhoneNumberNormalizer, PlanCatalog)
│   │   └── Http/Middleware/       TenantIsolationMiddleware, SubscriptionGuardMiddleware, VerifyInternalSecret, AuthenticateApiKey
│   ├── config/                    services.php (qr_engine URL/secret), queue.php, …
│   ├── database/
│   │   ├── migrations/            22 migrations — schema history
│   │   └── seeders/                RolePermissionSeeder, …
│   ├── routes/
│   │   ├── api.php                Every REST route (see §5)
│   │   └── console.php            Artisan commands (no scheduled jobs defined yet — see §7)
│   ├── .env.example
│   └── composer.json
│
├── frontend-app/                  React + TypeScript SPA
│   ├── src/
│   │   ├── core/
│   │   │   ├── api/axiosInstance.ts     Bearer-token interceptor, 401 / subscription-blocked events
│   │   │   ├── context/AuthContext.tsx  Auth state, permissions, refreshUser()
│   │   │   └── guards/                  Route guards (ProtectedRoute, permission checks)
│   │   ├── components/
│   │   │   ├── layout/            AppLayout (sidebar + Header + <Outlet/>), ProfileModal
│   │   │   ├── qr/                QRScannerModal (Socket.IO client)
│   │   │   ├── admin/, billing/, settings/
│   │   ├── pages/                 One folder per feature area (alerts/, analytics/, billing/, chatbot/, developer/, users/, admin/, settings/, auth/, errors/)
│   │   ├── services/               One *Service.ts per API resource (teamService, whatsappService, billingService, …)
│   │   ├── theme/                 signalIndigo.ts — centralized design tokens
│   │   └── types/                 Shared TS interfaces mirroring backend-api's JSON shapes
│   ├── .env.example
│   └── package.json
│
└── qr-engine-service/              Node.js Baileys microservice
    ├── src/
    │   ├── server.js               Express app, Socket.IO server, all HTTP routes
    │   └── services/
    │       ├── sessionManager.js   Per-account Baileys session lifecycle (start/reconnect/logout/sendMessage)
    │       └── backendClient.js    Outbound calls back to backend-api (status webhook, token verification)
    ├── sessions/                    Per-account Baileys auth state (gitignored, created at runtime)
    ├── nodemon.json                 Dev watcher config — excludes sessions/ deliberately
    ├── .env.example
    └── package.json
```

---

## 4. Environment Setup & Installation Guide

### 4.1 Prerequisites

| Tool | Minimum version | Used by |
|---|---|---|
| PHP | **>= 8.2** | `backend-api` |
| Composer | 2.x | `backend-api` |
| Node.js | **>= 18** (tested with 22–24) | `frontend-app`, `qr-engine-service` |
| npm | bundled with Node | both Node projects |
| MySQL | 8.x (optional — SQLite works out of the box for local dev) | `backend-api` production/staging |
| Redis | 6.x+ (optional for local dev, recommended for production) | `backend-api` production queue/cache/session |
| A physical phone with WhatsApp | — | required once, to scan the QR code and pair the `qr` engine |

### 4.2 `backend-api` (Laravel)

```bash
cd backend-api
composer install

cp .env.example .env
php artisan key:generate

# Local dev default is SQLite — the file below just needs to exist:
touch database/database.sqlite

# For MySQL instead, edit .env first (see §4.5), then:
php artisan migrate

# Seed roles/permissions (Super Admin, Admin, User + their permission sets):
php artisan db:seed --class=RolePermissionSeeder

php artisan serve
# → http://127.0.0.1:8000
```

To also run the queue worker and Vite dev server together, `composer.json` already defines a convenience script:

```bash
composer run dev
# Runs: php artisan serve + php artisan queue:listen --tries=1 + php artisan pail + npm run dev, concurrently
```

### 4.3 `frontend-app` (React)

```bash
cd frontend-app
npm install

cp .env.example .env
# Defaults already point at localhost:8000 (API) and localhost:4000 (QR engine WS) — see §4.5

npm run dev
# → http://localhost:5173
```

### 4.4 `qr-engine-service` (Node.js / Baileys)

```bash
cd qr-engine-service
npm install

cp .env.example .env
# Set INTERNAL_API_SECRET to the SAME value as backend-api's .env (see §4.5)

npm run dev
# → listening on :4000 (nodemon; auto-restarts on src/ changes, ignores sessions/)
```

Start all three in separate terminals, **in this order**: `backend-api` → `qr-engine-service` → `frontend-app` (the frontend needs the API up to authenticate, and needs the QR engine up for the live pairing socket).

### 4.5 `.env` configuration reference

**`backend-api/.env`** (key variables — see `.env.example` for the full file):

```dotenv
APP_NAME=Laravel
APP_ENV=local
APP_KEY=                          # filled in by `php artisan key:generate`
APP_URL=http://localhost

# --- Database: SQLite (default, zero-config) ---
DB_CONNECTION=sqlite
# --- OR MySQL (uncomment + fill in for staging/production) ---
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=wa_saas_platform
# DB_USERNAME=root
# DB_PASSWORD=

SESSION_DRIVER=database           # switch to `redis` in production
CACHE_STORE=database              # switch to `redis` in production
QUEUE_CONNECTION=sync             # see §6 — switch to `database` or `redis` + queue:work in production

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# --- qr-engine-service bridge ---
QR_ENGINE_SERVICE_URL=http://localhost:4000
INTERNAL_API_SECRET=              # MUST be identical to qr-engine-service's INTERNAL_API_SECRET
```

**`frontend-app/.env`:**

```dotenv
VITE_API_BASE_URL=http://localhost:8000/api
VITE_QR_ENGINE_WS_URL=http://localhost:4000
```

**`qr-engine-service/.env`:**

```dotenv
PORT=4000
FRONTEND_ORIGIN=http://localhost:5173
BACKEND_API_URL=http://localhost:8000
INTERNAL_API_SECRET=              # MUST be identical to backend-api's INTERNAL_API_SECRET
```

> **The `INTERNAL_API_SECRET` value must match exactly between `backend-api` and `qr-engine-service`.** It is the only credential that authenticates the two internal, service-to-service routes in each direction (`backend-api → qr-engine-service`: start-session/logout/send-message; `qr-engine-service → backend-api`: the status webhook). A mismatch here fails silently as a 401, not an obvious error.

---

## 5. API & Event Flow — Payment Alert Send Sequence

This is the end-to-end path for a single payment alert, from the tenant clicking "Send" in the UI to the message landing on the recipient's phone:

```
1. frontend-app          POST /api/alerts/send  { recipient_phone, customer_name, amount, payment_ref }
   (SendAlertPage.tsx)   Authorization: Bearer <sanctum token>
        │
        ▼
2. backend-api            PaymentAlertController::send()
   (Laravel)              → PaymentAlertDispatcher::dispatch()
                             - checks payment_ref dedup (per account_id)
                             - creates payment_alerts row, status='queued'
                             - ProcessPaymentAlertJob::dispatch($alert->id)
                           ← HTTP 202 { message: 'Payment alert queued.', alert }
        │
        ▼   (QUEUE_CONNECTION=sync → runs inline; =database/redis → picked up by `queue:work`)
3. ProcessPaymentAlertJob  - re-checks account subscription is active & has quota
   ::handle()              - anti-ban jitter: sleep(random_int(3, 8))
                            - WhatsAppEngineFactory::make($account) → resolves BaileysDriver (qr) or MetaCloudApiDriver (meta)
                            - PhoneNumberNormalizer::normalize($alert->recipient_phone)
        │
        ▼   (qr engine only)
4. BaileysDriver           POST {QR_ENGINE_SERVICE_URL}/api/message/send
   ::sendMessage()         X-Internal-Secret: <shared secret>
                            { account_id, to: "<digits>@s.whatsapp.net", message }
                            timeout: 15s
        │
        ▼
5. qr-engine-service        POST /api/message/send  (server.js)
   (sessionManager.js)      → sock.sendMessage(to, { text: message })  [Baileys → WhatsApp]
                             ← { success: true, message_id } | { success: false, error }
        │
        ▼
6. ProcessPaymentAlertJob   on success: DB transaction — lock subscription row, increment used_messages,
   (back in Laravel)                    alert.status = 'sent', gateway_message_id, sent_at
                            on failure: alert.status = 'failed', error_reason (no quota deducted)
                            WebhookDispatcher::fire($account_id, 'message.sent' | 'message.failed', payload)
                            → any subscribed WebhookSubscription gets an outbound POST (DispatchWebhookJob)
```

The **Meta engine** path is shorter: step 4 goes directly to Meta's Cloud API via `MetaCloudApiDriver` — `qr-engine-service` is never involved for `meta`-engine accounts.

---

## 6. Queue & Background Jobs Troubleshooting

### `sync` vs `queue:work`

| | `QUEUE_CONNECTION=sync` | `QUEUE_CONNECTION=database` / `redis` + `php artisan queue:work` |
|---|---|---|
| When a job runs | **Immediately, inline**, inside the HTTP request that dispatched it | Whenever a separate `queue:work` worker process picks it up |
| Request latency | The `POST /api/alerts/send` request blocks until the job finishes — **5–18 seconds** for `ProcessPaymentAlertJob`, because of its deliberate 3–8s anti-ban `sleep()` plus the HTTP call to the WhatsApp engine | Fast (~milliseconds) — the request just inserts a queue row and returns 202 |
| Requires a worker process | No | **Yes** — nothing runs the job if `queue:work` (or `queue:listen`, or Horizon) isn't running |
| Good for | Local development, debugging, low-volume setups where you'd rather see the real result immediately | Production — decouples the API's response time from WhatsApp send latency, and lets you scale workers independently |

**This project ships with `QUEUE_CONNECTION=sync`** so a fresh local setup works end-to-end with zero extra processes. Before going to production, switch to `database` or `redis` and run a supervised `queue:work` process (see §7) — otherwise every alert send will hold the tenant's browser request open for several seconds.

### Common issues

**Alert stuck at `status: "queued"` forever ("looks like it's running but nothing happens")**
There is no `RUNNING` status in this system — `payment_alerts.status` is only `pending | queued | sent | failed`. A row stuck at `queued` almost always means:
1. `QUEUE_CONNECTION` is `database`/`redis` but **no `queue:work` process is running** — the job was dispatched but nothing will ever pick it up. Fix: start a worker, or switch to `sync` for local dev.
2. Check `storage/logs/laravel.log` for `ProcessPaymentAlertJob failed unexpectedly` — an uncaught exception is logged there with the full trace before the job marks the alert `'failed'`.

**Port mismatches**
The three services must agree on each other's ports, or the whole chain silently breaks:
- `backend-api/.env` → `QR_ENGINE_SERVICE_URL` must match the port `qr-engine-service` actually listens on (`PORT` in its own `.env`, default `4000`).
- `frontend-app/.env` → `VITE_API_BASE_URL` must match `backend-api`'s port (default `8000`, i.e. `php artisan serve`'s default), and `VITE_QR_ENGINE_WS_URL` must match `qr-engine-service`'s port.
- `qr-engine-service/.env` → `FRONTEND_ORIGIN` must match the origin `frontend-app` is actually served from (Vite dev default `http://localhost:5173`), or Socket.IO's CORS check rejects the browser connection outright.
- `qr-engine-service/.env` → `BACKEND_API_URL` must match `backend-api`'s actual base URL (used both for the outbound status webhook and for verifying a Socket.IO client's Bearer token via `GET /api/auth/me`).

**Session disconnects / "Session disconnected" errors on send**
`qr-engine-service` keeps Baileys sockets in memory, keyed by `account_id` — restarting the process (or nodemon auto-restarting it) drops every in-memory session, even though the auth credentials on disk (`sessions/{account_id}/`) survive and let the socket reconnect automatically. If `sock.sendMessage()` is called while the socket is mid-reconnect or fully logged out, `POST /api/message/send` returns `{"success": false, "error": "Session disconnected"}` (HTTP 422). Fix: re-open the QR pairing modal in the frontend to trigger `startSession()` again, or check the `[qr-engine] CONNECTION_CLOSED …` log line for the Baileys `status_code` (515 = normal post-pairing restart, handled automatically; 401 = logged out on the phone side, requires a fresh QR scan).

**`INTERNAL_API_SECRET` mismatch**
Any call between `backend-api` and `qr-engine-service` (in either direction) returns a bare `401 Unauthorized` with no further detail if the two services' `INTERNAL_API_SECRET` values don't match byte-for-byte. This is the first thing to check if `BaileysDriver` reports every send as `"error": "The QR engine rejected the message."`.

**Job never times out but never finishes either**
Not possible as currently written — `BaileysDriver::sendMessage()` wraps its HTTP call in `try/catch` with an explicit 15-second timeout, and `ProcessPaymentAlertJob::handle()` wraps the entire job body in `try/catch` as a second safety net, routing any unexpected exception through `fail()` so the alert always ends at `'failed'` (never stuck) and the failure reason is always logged.

---

## 7. Production Deployment Checklist

### 7.1 `backend-api` — Laravel via Supervisor + Nginx

```dotenv
# .env changes for production
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=wa_saas_platform
DB_USERNAME=wa_saas_user
DB_PASSWORD=<strong password>

SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis          # or `database` if Redis isn't provisioned yet
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=<strong password>
```

```bash
composer install --optimize-autoloader --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Supervisor config for the queue worker** (`/etc/supervisor/conf.d/wa-saas-queue.conf`):

```ini
[program:wa-saas-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/wa-saas-platform/backend-api/artisan queue:work redis --sleep=3 --tries=1 --max-time=3600
directory=/var/www/wa-saas-platform/backend-api
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/wa-saas-platform/backend-api/storage/logs/queue-worker.log
stopwaitsecs=3600
```

`--tries=1` matches `ProcessPaymentAlertJob`'s own `$tries = 1` (WhatsApp sends are not idempotent — a failed alert is left `'failed'` for a human/future resend flow, never auto-retried). `numprocs=2` runs two parallel workers so throughput isn't capped by the job's own 3–8s anti-ban `sleep()`.

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start wa-saas-queue-worker:*
```

Serve the app itself with **php-fpm + Nginx** (not `php artisan serve`, which is single-threaded and dev-only).

### 7.2 `qr-engine-service` — PM2

```bash
cd /var/www/wa-saas-platform/qr-engine-service
npm ci --omit=dev
```

```bash
pm2 start src/server.js --name wa-saas-qr-engine \
  --time \
  --max-memory-restart 500M

pm2 save
pm2 startup      # follow the printed systemd/init command so PM2 survives a server reboot
```

Use `npm start` semantics (`node src/server.js`), **not** `npm run dev`/nodemon, in production — nodemon's file-watcher is a development-only convenience and has no place restarting a process holding live WhatsApp sockets. Back up the `sessions/` directory as part of your regular backup strategy — it holds every tenant's WhatsApp pairing credentials; losing it forces every tenant to re-scan their QR code.

```bash
pm2 logs wa-saas-qr-engine        # tail the [qr-engine] QR_RECEIVED / CONNECTION_OPEN / CONNECTION_CLOSED logs
pm2 restart wa-saas-qr-engine     # after a deploy
```

### 7.3 `frontend-app` — static build behind Nginx

```bash
cd frontend-app
npm ci
npm run build          # tsc -b && vite build → dist/
```

Serve `dist/` as static files (Nginx `root`, or any CDN/static host). Set `VITE_API_BASE_URL` and `VITE_QR_ENGINE_WS_URL` to the **public HTTPS** URLs of `backend-api` and `qr-engine-service` at build time (Vite inlines `import.meta.env.*` at build, not at runtime) — a separate `.env.production` is the cleanest way to do this.

### 7.4 Cron

`routes/console.php` currently defines no scheduled commands beyond Laravel's default `inspire` example — there is nothing that requires cron *today*. Still, add the standard Laravel scheduler entry now so any scheduled command introduced later (subscription-expiry sweeps, webhook-delivery retries, etc.) works without a further deploy step:

```cron
* * * * * cd /var/www/wa-saas-platform/backend-api && php artisan schedule:run >> /dev/null 2>&1
```

### 7.5 SSL

- Terminate TLS at Nginx (or your load balancer) in front of all three services; none of them implement HTTPS themselves.
- `backend-api` and `qr-engine-service` should only be reachable over the internal network / a private security-group rule from each other and from `frontend-app`'s server — neither is designed to be exposed directly to the public internet with the `INTERNAL_API_SECRET` as its only protection on those internal routes.
- `frontend-app`'s public origin is the only one that needs a public-facing SSL certificate for typical deployments (e.g. via Let's Encrypt / Certbot, or your CDN's managed TLS) — point `backend-api`'s `APP_URL` and `qr-engine-service`'s `FRONTEND_ORIGIN` at that same HTTPS origin so CORS and Sanctum's stateful-domain checks line up.
- Set `SESSION_SECURE_COOKIE=true` in `backend-api/.env` once served over HTTPS (not present in `.env.example` — add it for production).

---

## Quick Reference — Ports & URLs (local development)

| Service | URL | Port |
|---|---|---|
| `backend-api` | http://localhost:8000 | 8000 |
| `frontend-app` | http://localhost:5173 | 5173 |
| `qr-engine-service` | http://localhost:4000 | 4000 |
