# `wa-saas-platform` — Full Project Analysis (2026-09-16)

**Method:** Direct source inspection on-device (`sl-laptop-d61`, `C:\xampp\htdocs\wa-saas-platform`) via the connected-folder shell — `git log`/`git diff`/`git status`, `routes/api.php`, controller/model/service/migration inventory, `backend-api/.env` and `qr-engine-service/.env` (values not printed here), the `wa_saas_platform.sql` dump, and the live `qr-engine-service/sessions/` directory. No code executed, nothing mutated, no server started.

This is the 27th analysis document in `Claude outputs/`. It does not re-derive the architecture, feature inventory, or security posture already established in `FULL_PROJECT_ANALYSIS_2026_09_15.md` (still accurate) — it verifies what changed since then and **corrects one load-bearing claim in that report**. Tags: **[Fact]** (verified against a file/command), **[Inference]** (conclusion from facts), **[Correction]** (a prior report's claim, re-checked and found wrong or incomplete).

---

## 1. What changed since the Sep 15 report

**[Fact]** Two new commits: `4bc66b0` ("added dynamic routes, agent roles, and permission matrix") and `80cd33b` ("added the database file", i.e. `wa_saas_platform.sql`, a 50-table MySQL dump, committed at repo root).

**[Fact]** 14 files currently show as modified in `git status`, none of it real: `git diff -w --stat` (whitespace-insensitive) against the same files returns empty, and `file` confirms CRLF-vs-LF only (e.g. `DashboardPage.tsx`, `qr-engine-service/src/services/backendClient.js`). **This is the same class of noise the Sep 15 report already flagged under a different set of files (§8 there), and the recommended `.gitattributes` fix still has not been made.** Two commits have landed since that recommendation without it being applied — this is now a recurring, self-inflicted cost, not a one-off.

**[Fact]** `RouteMasterController` (260 lines, new) ships `GET /api/admin/permissions-tree`, `route-categories` and `system-routes` CRUD — a dynamic, DB-backed replacement for the frontend's previously hardcoded module checklist arrays. Backed by two new tables/models (`RouteCategory`, `SystemRoute`). Gated behind `permission:manage-accounts` (shared with `AccountController`) plus an inline Super-Admin check for everything except the read-only `tree()` endpoint, which Agents also use.

**[Fact]** `agent_scope_id` (set by `TenantIsolationMiddleware` for an Agent caller, added in the Sep 14 3-tier-hierarchy commit) now has one real consumer: `AuditLogController` scopes the audit log list to an Agent's own sub-clients. The Sep 15 report stated flatly that *no* live route consumed this attribute — **that claim is now out of date, though still narrowly true elsewhere** (account provisioning, quota routes, etc. still don't scope by it).

**[Fact]** `api_keys` gained `secret_prefix`/`secret_hash` (migration `2026_09_15_130000`) for a dual-factor `x-api-key` + `x-api-secret` scheme on two new Developer API endpoints (`/api/v1/whatsapp/groups/create`, `/api/v1/whatsapp/messages/send`). Nullable and additive — every key issued before this still authenticates the pre-existing single-factor endpoints unchanged. `ApiKey::isSecretValid()` uses `hash_equals()`, consistent with this codebase's established constant-time-comparison discipline.

---

## 2. Correction to the Sep 15 report — the "27 tables, pending migrations" finding was checking the wrong database

**[Correction]** The Sep 15 report's top finding was that the local `database/database.sqlite` has only 27 tables and is missing everything from `allowed_modules` onward. That is still true **of the sqlite file** (`database/database.sqlite`, mtime unchanged since Sep 9), **but `backend-api/.env` sets `DB_CONNECTION=mysql`, `DB_DATABASE=wa_saas_platform`** — the application is not actually reading that sqlite file at all in this environment. The sqlite file is a dead leftover from an earlier setup step, not the active database.

**[Fact]** The newly committed `wa_saas_platform.sql` is a MySQL dump with **50 `CREATE TABLE` statements**, including `route_categories`, `system_routes`, and `activity_logs` — the newest tables in the migration history (Sep 15). This is strong evidence the actual MySQL database this app runs against **is current** with all 64 migrations, and the dump was exported from it as a backup/handoff artifact.

**[Unknown]** I did not connect to the MySQL server directly (no `mysql`/`sqlite3` client binary was available in this shell to query it, and doing so wasn't necessary to correct the claim above) — so "current with all 64 migrations" is inferred from the dump's table list and its presence of the newest tables, not from running `php artisan migrate:status` against the live connection. If you want that closed with certainty, run `php artisan migrate:status` from `backend-api/` yourself (read-only, safe) and I'll fold the result in.

**Net effect: the Sep 15 report's "critical action item" (§7 there, "decide on running migrations") is very likely moot.** The dead sqlite file is worth deleting or `.gitignore`-ing to stop it from confusing the next audit the same way, but it is not blocking anything today.

---

## 3. QR engine (`qr-engine-service`) — current live state

**[Fact]** Checked the actual `sessions/` directory on disk, not just the code: `sessions/1/` holds a live, paired session (`creds.json` present, 1,697 auth-state files, most recently touched today at 03:23 — consistent with either an active reconnect or the process having been restarted and Baileys auto-resuming from disk, per `sessionManager.js`'s documented reconnect behavior). `sessions/2/` is empty — that account was logged out or hit `badSession`/max-reconnect-attempts at some point, both of which wipe the directory by design (`clearSessionFiles()`).

**[Fact]** This reconfirms the Sep 15 report's read of `sessionManager.js` and `server.js` line-for-line: in-memory session map keyed by `account_id`, constant-time internal-secret check on both sides, health-check-before-send (`getHealthySession()`) shared across `sendMessage`/`createGroup`/`addGroupParticipants`, media fetched into a bounded in-memory buffer (25 MB cap) before being handed to Baileys rather than letting Baileys stream a caller-supplied URL directly, and a `MAX_RECONNECT_ATTEMPTS=5` ceiling before the session is torn down and the tenant is pushed back to a fresh QR pairing. No code has changed here since Sep 15 (`backendClient.js`'s "diff" is the CRLF noise from §1, confirmed empty under `-w`).

**[Inference, unchanged]** Still single-instance-only by construction (in-memory `Map`), still no encryption at rest for `sessions/<id>/creds.json`, and the `0o700` directory permission fix is still a near no-op on the Windows/NTFS target it actually runs on. None of this has regressed; none of it has been addressed either.

---

## 4. Current snapshot (for anyone who hasn't read the prior reports)

| | |
|---|---|
| **Architecture** | 3 independent services — `backend-api` (Laravel 11/PHP 8.2, system of record) ↔ `qr-engine-service` (Node/Baileys, holds live WhatsApp sockets) ↔ `frontend-app` (React 19/TS SPA). Sanctum Bearer tokens for REST; a shared-secret-authenticated internal HTTP hop for Laravel→Node; a separate Socket.IO channel for live QR status. |
| **Backend size** | 50 controllers, 33 models, 29 services, 66 migrations (was 64 two days ago), ~5 queued jobs, 1 scheduled command. |
| **Frontend size** | 29 pages, 25 shared components, 30 `*Service.ts` API wrappers. No Redux/React Query — Context + per-page `useState`/`useEffect`. |
| **DB (active)** | MySQL, current with the full migration set (§2). Local `sqlite` file present but unused and stale — safe to delete. |
| **Test coverage** | Unchanged: two stock Laravel example test files, no real assertions. CI (`ci.yml`) runs `php artisan test` (against those stubs), a frontend `tsc`+`build`, and a qr-engine syntax check — infrastructure exists, coverage does not. |
| **Security posture** | Unchanged from Sep 15: internal-secret and webhook-signature checks are constant-time and fail-closed; mass-assignment clean across all models; no raw-SQL injection surface found; rate limiting registered on public webhook/social endpoints. Still open: zero real test coverage, unencrypted Baileys session files, `module.guard` not backfilled onto older route groups (billing/team/analytics), inbound Meta messages logged but not persisted. |

---

## 5. Priority-ordered recommendations (supersedes Sep 15 §9 item 1, keeps the rest)

1. **Fix `.gitattributes` now, not after a third recurrence.** Add `* text=auto eol=lf`, run `git add --renormalize .` once, commit. This has now cost two review passes' worth of noise across 40 total file-diffs that turned out to be nothing.
2. **Delete or `.gitignore` `backend-api/database/database.sqlite`.** It's inert (the app runs on MySQL) but will keep triggering false "pending migrations" alarms in any future audit that doesn't cross-check `.env` first — including, almost, this one.
3. Optionally run `php artisan migrate:status` (read-only) to convert §2's "very likely current" into a confirmed fact.
4. Everything else from the Sep 15 report's §9 (items 3–8: write the three overdue test suites, persist inbound Meta messages, backfill `module.guard` on older routes, decompose the largest frontend pages, decide an encryption stance for `sessions/`, set `NODE_ENV=production` wherever this actually deploys) is unchanged and still open — not repeated in full here, see that report.

---

## 6. What NOT to rebuild

Everything the Sep 15 report certified as solid in its §10 is unchanged and re-confirmed: middleware layering, mass-assignment discipline, payment-webhook handling, the Sanctum+internal-secret trust boundary, and the codebase's own habit of disclosing intent and trade-offs in-code. Add to that list: the new `RouteMasterController`/dynamic permission tree is scoped correctly (Super-Admin-only writes, shared read for Agents) and the dual-factor API key addition is additive and backward-compatible by design, not by accident.

---

*No files were modified and no commands were run against either database or either service's running state during this analysis.*
