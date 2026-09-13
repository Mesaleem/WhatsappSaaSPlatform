# qr-engine-service Cross-Device Connectivity Hardening — Summary

Scope: `qr-engine-service/src/server.js`, `qr-engine-service/src/services/sessionManager.js`,
`backend-api/config/services.php`, `backend-api/.env` / `.env.example`,
`backend-api/app/Http/Controllers/Api/WhatsAppController.php`. No migrations, no frontend changes.

## Correction before anything else: this codebase has no Puppeteer

[Fact, verified via `qr-engine-service/package.json` and `node_modules` on this device] The WhatsApp
engine dependency is **`@whiskeysockets/baileys`** only:

```
"dependencies": {
  "@whiskeysockets/baileys": "^7.0.0-rc14",
  "axios": "^1.20.0", "cors": "^2.8.6", "dotenv": "^17.4.2",
  "express": "^5.2.1", "pino": "^10.3.1", "qrcode": "^1.5.4", "socket.io": "^4.8.3"
}
```

There is no `puppeteer`, `whatsapp-web.js`, or Chromium anywhere in this repo. So on the new machine:
- **Do not run** `npx puppeteer browsers install chrome` — it installs an unused package and
  will not fix anything here.
- **Do not look for** `.wwebjs_auth` or `auth_info_baileys` — Baileys (this project's actual
  engine) stores its session under `qr-engine-service/sessions/<accountId>/`, and that path is
  already git-ignored (`qr-engine-service/.gitignore`: `sessions/*/`), so it never travels via
  `git pull` on its own — which is correct; a session from one machine should never be reused on
  another.
- **The correct setup step** on the new machine is a clean dependency install (see §4).

## 1. Files changed

| File | Change |
|---|---|
| `qr-engine-service/src/server.js` | Added top-of-file `process.on('uncaughtException', ...)` (logs full error, `process.exit(1)`) and `process.on('unhandledRejection', ...)` (logs, does not exit) — installed *before* any other code runs, so a boot-time crash in Baileys/Express init now prints a stack trace instead of the process silently hanging. Added `const HOST = process.env.HOST \|\| '0.0.0.0';`. Added a global request logger (`[INCOMING REQUEST] METHOD ORIGINAL_URL`) immediately after `express.json()`. Replaced the bare `app.listen(PORT, ...)` with `httpServer.on('error', ...)` (explicitly detects `EADDRINUSE`) + `httpServer.listen(PORT, HOST, ...)`. |
| `qr-engine-service/src/services/sessionManager.js` | The `connection.update` `'close'` handler previously cleared session files only when `loggedOut` was true; `DisconnectReason.badSession` fell through to the generic reconnect-with-backoff path and could loop forever against corrupted files. Now `badSession` triggers the same `clearSessionFiles()` cleanup as `loggedOut`. Also added cleanup to the "exhausted `MAX_RECONNECT_ATTEMPTS`" branch, which previously gave up without ever removing the files it kept failing against. |
| `backend-api/config/services.php` | `services.qr_engine.url` default changed from `http://localhost:4000` to `http://127.0.0.1:4000`. |
| `backend-api/.env` and `.env.example` | `QR_ENGINE_SERVICE_URL` changed from `http://localhost:4000` to `http://127.0.0.1:4000`. |
| `backend-api/app/Http/Controllers/Api/WhatsAppController.php` | `forwardToQrEngine()`: `->timeout(10)` (one combined bound) replaced with `->connectTimeout(5)->timeout(5)` (TCP handshake bounded separately from total request time). The `Throwable` catch around the HTTP call now `Log::error()`s the exception message before returning the existing 502 "unreachable" response. |

## 2. Root cause of each symptom you reported

**"No incoming request logs appear in the Node terminal" / hangs 2-3s:** [Inference — the exact
mechanism can't be confirmed remotely, since I can't see the second machine's terminal, but this
is the standard cause] On Windows, `localhost` resolves via the OS's dual-stack resolver, which
tries IPv6 `::1` first. If the Node process bound only to IPv4 (or vice versa), or the two ends
picked different families, the client's connection attempt stalls on the IPv6 path before falling
back — inside Laravel's old single 10s timeout, this reads as "no response," and no request ever
reaches Node's logger because the connection didn't complete. Binding Node explicitly to `0.0.0.0`
(all interfaces, both families) and pointing Laravel at the literal `127.0.0.1` removes the
ambiguity on both ends.

**"Node stalls silently right after `nodemon starting node src/server.js`":** Without the
`uncaughtException`/`unhandledRejection` handlers, a synchronous throw or rejected promise during
Baileys/Express initialization terminates or hangs the process with no output at all — nodemon
just shows its own banner and nothing else. The new handlers guarantee *something* prints before
the process dies, which is the only way to find out what's actually failing on that machine.

**Corrupted/stale session causing a hang:** covered by the `badSession` fix in §1 — a session
directory left in a bad state (e.g. copied from another machine, which you should not do, or a
partial write from a crashed process) no longer loops silently; it's deleted and the account goes
back to a clean `disconnected` state on the next connection attempt.

## 3. What this does NOT fix — needs manual action on the new machine

**`.env` files are git-ignored and never travel with `git pull`.** [Fact, unchanged from every
prior report this engagement] On the new/second machine you still need, by hand:

```
backend-api/.env.example        → copy to backend-api/.env
qr-engine-service/.env.example  → copy to qr-engine-service/.env
```

and `INTERNAL_API_SECRET` must be byte-identical in both files on *that* machine (checked and
confirmed already matching on **this** machine — `sl-laptop-d61` — during verification, so this is
specifically a new/second-machine setup step, not something broken here).

## 4. Exact Windows PowerShell commands for the new machine

Find and kill whatever is already bound to port 4000 (this is what makes a fresh `npm run dev`
appear to "hang" — the old process is still holding the port and the new one fails silently on
`EADDRINUSE`, which you'll now actually see logged after tonight's fix):

```powershell
netstat -ano | findstr :4000
# note the PID in the last column, then:
taskkill /PID <that_pid> /F
```

Clean dependency reinstall for `qr-engine-service` (replaces the "install Puppeteer" step — not
needed, see the correction above):

```powershell
cd C:\xampp\htdocs\wa-saas-platform\qr-engine-service
rmdir /s /q node_modules
del package-lock.json
npm ci
npm run dev
```

You should now see, in order: `qr-engine-service listening on 0.0.0.0:4000`, then
`[INCOMING REQUEST] POST /api/qr/start-session` the moment Laravel calls it. If it instead prints a
stack trace, that trace is the actual root cause on that machine — send it and I'll diagnose from
it directly instead of guessing.

## 5. Flagged, not in scope for this fix

`GET /api/admin/whatsapp/devices` (`WhatsAppController::adminIndex()`) does **not** call
qr-engine-service at all — [Fact, verified by reading the method] it's a pure local read
(`WhatsAppSession::query()->get()`, `Account::query()->get()`). If that specific endpoint is still
slow on the new machine, the cause is unrelated to anything in this report (likely local MySQL/DB
latency on that machine) and needs separate investigation — tell me if it's still an issue after
the qr-engine fixes above are in place.

## 6. Verification

No PHP linter available in this environment (`which php` / `composer --version` both empty,
consistent with every prior check this engagement) — verified via `node --check` (both JS files:
syntax OK) plus manual review and brace/paren balance:
- `server.js`: braces 68/68, parens 119/119.
- `sessionManager.js`: braces 73/73, parens 102/102.
- `WhatsAppController.php`: braces 16/16, parens 100/100.
- `config/services.php`: brackets 13/13, parens 27/27.

Confirmed on this device (`sl-laptop-d61`) after the edits: `qr_engine.url` resolves to
`http://127.0.0.1:4000` in both `services.php` and `.env`, and `INTERNAL_API_SECRET` already
matches between `backend-api/.env` and `qr-engine-service/.env` here — this machine was not the
one exhibiting the reported symptoms.

## 7. Standing state (unchanged)

No migrations run. The four migrations disclosed in earlier reports this engagement remain written
to disk, not executed, awaiting your authorization:
- `2026_09_11_190000_add_gemini_api_key_to_accounts_table.php`
- `2026_09_11_193000_create_organic_posts_table.php`
- `2026_09_11_200000_create_whatsapp_flows_table.php`
- `2026_09_11_200001_create_whatsapp_flow_sessions_table.php`
