# Running WapHub fast on cPanel (250+ users)

The app is built so one cPanel account can serve a few hundred active users, **if** the server is
set up as below. Most "the app is slow" reports on a developer machine come from
`php artisan serve` on Windows: it runs **one request at a time** (`PHP_CLI_SERVER_WORKERS` is
ignored on Windows) with OPcache off and debug on. That is a development tool, never a live server.

## 1. PHP (cPanel → Select PHP Version / MultiPHP)

| Setting | Value | Why |
|---|---|---|
| PHP version | 8.2 or newer | what the app is tested on |
| Extension `opcache` | **on** | a request drops from ~1 s to ~50–100 ms |
| `opcache.memory_consumption` | 128 (or more) | the whole app fits in memory |
| `opcache.validate_timestamps` | 0 after deploy, *or* keep 1 with `revalidate_freq=60` | no file checks on every request |
| `memory_limit` | 256M | Excel imports and PDF documents |
| `max_execution_time` | 60 | |
| PHP handler | **PHP-FPM** (cPanel → MultiPHP Manager → PHP-FPM on) if your host offers it | several requests at once |

Ask the host how many PHP processes the account may run at once (often called "Entry
Processes" / "EP" in CloudLinux). 20–30 is enough for ~250 active users; 10 is tight.

## 2. `.env` for production

```
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning

CACHE_STORE=file          # faster than "database" on shared hosting; use redis if the host has it
SESSION_DRIVER=file       # or database; the API uses Bearer tokens, sessions are barely used
QUEUE_CONNECTION=sync     # keep: the heavy jobs (bulk WhatsApp, journeys, AI documents, social,
                          # plan reconciliation) already go to their own "database" queues,
                          # drained by the scheduler below; only small jobs run inline
```

## 3. After every deploy

```
php artisan migrate --force
php artisan optimize          # config + route + view + event cache in one go
```

`php artisan optimize:clear` undoes it (use it if a `.env` change does not seem to apply).

## 4. Cron (cPanel → Cron Jobs) — one line, every minute

```
* * * * * cd /home/USER/path/to/backend-api && php artisan schedule:run >> /dev/null 2>&1
```

This runs every scheduled task **and** drains the background queues (bulk sends, journeys,
reminders, social posts…) each minute, so a user who starts a bulk send never waits for it.

## 5. The frontend

Upload the `npm run build` output (`frontend-app/dist`) as static files. They are served by
Apache directly and never touch PHP. If you use Cloudflare (free plan is fine), turn it on for the
domain: static files are then served from its edge, and Website Analytics also gets the
visitor's country.

## 6. What the code already does to keep load low

- The public website's content and plans come from the cache, not the database, on every visit;
  an edit by the Super Admin clears it at once.
- The dashboard refreshes the signed-in user at most once every 30 s on page changes / tab focus
  (it used to on every click).
- The same GET asked for twice at the same moment (two cards, a page and its header) goes out as
  **one** request.
- Long work (bulk WhatsApp, group sends, journeys, AI documents) is queued, not done in the
  request.

## 7. Check it before going live — load test

`load-test/k6-dashboard.js` ramps to 250 signed-in users (plus website visitors) and fails if more
than 1 % of requests fail or the slowest 5 % take over 1.5 s.

```
k6 run -e BASE=https://your-domain/api -e EMAIL=loadtest@example.com -e PASSWORD=... load-test/k6-dashboard.js
```

Use a dedicated test client account, run it at a quiet hour (it is real load), then delete the
test visits from Website Analytics (source "K6").

## 8. When cPanel is no longer enough

Move to a small VPS (2 vCPU / 4 GB: DigitalOcean, Hostinger VPS, AWS Lightsail) with Nginx +
PHP-FPM + Redis + Supervisor when any of these happens: the load test fails with OPcache on and
PHP-FPM, the host caps you below ~15 concurrent PHP processes, or bulk sends need to start the
same second instead of within a minute (Supervisor runs the queue continuously instead of cron's
once a minute). The code needs no change for that move — only `.env` (`CACHE_STORE=redis`,
`SESSION_DRIVER=redis`) and a Supervisor `queue:work` per queue.
