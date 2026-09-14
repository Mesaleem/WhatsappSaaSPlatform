# Delta Analysis — `wa-saas-platform` (since `PRODUCT_ANALYSIS_2026_09_13.md`)

**Method:** Re-verification pass on-device (`sl-laptop-d61`, `C:\xampp\htdocs\wa-saas-platform`). Read the prior audit (`Claude outputs/PRODUCT_ANALYSIS_2026_09_13.md`, written 2026-09-13 16:38 UTC) in full, then checked its claims and every commit made after that timestamp directly against current source — `git show`/`git diff` per commit, `grep`/`sed` on the actual files named in each commit message, and a direct `sqlite3`-equivalent (`python3`+`sqlite3` module) query against `backend-api/database/database.sqlite`. No code executed, no server started, nothing mutated. Tags: **[Fact]** (verified against a file or query result), **[Inference]** (conclusion drawn from facts), **[Hypothesis]** (plausible, unverified).

---

## 1. Commits since the last audit

Four commits landed after the 16:38 UTC audit, all same-day (2026-09-13, 17:12–17:39 UTC), all authored by Saleem Ansari with Claude Sonnet 5 co-authorship:

| Commit | Time | What it claims |
|---|---|---|
| `8cbc7c4` | 17:12 | Ships the previously-uncommitted Group Messaging feature (flagged as the audit's #1 action item) + closes 7 of the audit's 8 non-deferred §6 gaps |
| `81fb5ba` | 17:30 | Adds web-UI group sending to the Send Alert page |
| `d09afeb` | 17:32 | Makes Contact Groups page full-width |
| `81a3aa2` | 17:39 | Flags native WhatsApp groups deleted/left outside the app; adds a Recreate action |

## 2. Verification of `8cbc7c4`'s claimed fixes

Every item this commit claims to close was checked against the actual file, not just the commit message:

| # | Claim | Verified against | Verdict |
|---|---|---|---|
| High #1 | `qr-engine-service` now forwards inbound Baileys DMs to `/api/internal/whatsapp-inbound` so chatbot/Journey fire for `qr`-engine tenants | `sessionManager.js:269` (`messages.upsert` listener) → `notifyInboundMessage()` in `backendClient.js:46` → posts to `/api/internal/whatsapp-inbound` | **[Fact] Confirmed, real fix.** Scoped to 1:1 text/button/list replies only (group messages and non-text types intentionally excluded, per inline docblock) |
| Med #3 | `SocialWebhookController` now verifies `X-Hub-Signature-256` for `provider=meta` | `assertValidSignature()` at line 168, `hash_equals()` comparison at line 196, called from the webhook handler at line 122 | **[Fact] Confirmed** |
| Med #4 | Adds `.github/workflows/ci.yml` (PHPUnit, frontend tsc+build, qr-engine syntax check) | File exists, 3 jobs, migrate→test sequence on a **fresh** sqlite DB per run | **[Fact] Confirmed and sound** — see §4 below for why "fresh DB" matters |
| Med #5 | Baileys session dirs created with mode `0o700` | `sessionManager.js:224-225` — `fs.mkdir(..., {mode: 0o700})` + explicit `chmod` | **[Fact] Confirmed**, but the commit itself discloses this is close to a no-op on the actual Windows/NTFS deployment target — correctly flagged as partial, not resolved |
| Med #6 | `message_dispatch_logs` gets `gateway_message_id`; failed-status webhooks correlate back to it | `MessageDispatchLog.php:144-146`, `MetaWebhookController.php:259,302` | **[Fact] Confirmed, but only partial** — `sent`/`delivered`/`read` callbacks are explicitly still `Log::info()`-only, not persisted (see §5, this does **not** close original item #6) |
| Low #8 | `frontend-app/.env` untracked + gitignored | `git ls-files frontend-app/.env` → empty; `.gitignore` has the entry with an inline rationale comment | **[Fact] Confirmed** |
| Low #9 | Internal-secret comparisons made constant-time | `VerifyInternalSecret.php` → `hash_equals()`; `server.js:190-192` → `crypto.timingSafeEqual()` with a length-check guard first | **[Fact] Confirmed** |

**[Inference]** This is an unusually well-disclosed commit — it states plainly that it could not run `php -l` or PHPUnit anywhere in its environment (no `php` binary reachable), so PHP changes only got a brace-balance static check, and it explicitly lists what it deliberately did **not** do (pricing table, page-file refactor) rather than silently skipping them. That discipline held up under independent re-verification here — nothing above was overstated.

## 3. The three follow-up commits

- **`81fb5ba`** — `SendAlertPage.tsx` replaces the phone-number field with a multi-select group picker when sending to Contact Groups, calling `/groups/{id}/send-template` once per group via `Promise.allSettled` (one group's failure doesn't block the others). **[Fact]** Route confirmed at `routes/api.php:493` (`groups/` prefix, gated by `permission:send-messages` + `module.guard:contact_groups`).
- **`d09afeb`** — one-line width fix, `ContactGroupsPage.tsx` now matches `MessageLogsPage.tsx`'s `max-w-full`. **[Fact]** Trivial, no functional risk.
- **`81a3aa2`** — detects a native WhatsApp group that was deleted/left from inside WhatsApp itself (not this app) and surfaces a "Recreate" action next to the existing "Sync failed" badge, with a `confirm()` dialog warning about duplicate-group risk before acting. **[Fact]** Confirmed in `ContactGroupController.php` and `ContactGroupsPage.tsx`; reuses the existing pending-poll effect rather than adding new polling logic — consistent with the codebase's stated preference for minimal, additive changes.

## 4. New finding this pass surfaced — not covered by yesterday's audit

Yesterday's report explicitly disclaimed: *"No code executed, no DB queried."* This pass did query it, directly:

**[Fact]** `backend-api/database/database.sqlite` has **22 of 63** migrations applied. **41 are pending** — not just the ones from `8cbc7c4`/`81a3aa2` (contact groups, message_dispatch_logs, native-group support), but everything back to **2026-09-09**: `allowed_modules`, notification templates/broadcasts/inbox, mail logs, `message_templates`, the entire Social Suite (`social_provider_configs`, `social_accounts`, `leads`, `ad_campaigns`, comment automation, ad daily metrics), quota requests, WhatsApp Flows, and more. Confirmed by direct query — `contact_groups`, `contact_group_members`, and `message_dispatch_logs` do not exist as tables in this database file at all right now.

**[Inference]** This means, on this machine, right now: none of the Social Suite, notifications, message templates, quota top-up workflow, WhatsApp Flows, or the newly-shipped Group Messaging / unified message-log feature can actually run — any request touching them will fail with a "no such table" SQL error, regardless of how correct the application code is. This isn't a regression from the last 4 commits; the drift predates them (it goes back to Sep 9), but it makes the group-messaging work shipped today **untestable as-is**, and it's a bigger, more urgent gap than anything in yesterday's §6 list.

**Per your own state-mutation rule, I have not run this. This needs your explicit authorization:**

```bash
cd backend-api
php artisan migrate
```

This is additive (new tables/columns only — nothing in the pending list is a destructive migration such as a drop or a rename I could see), but it is a schema change, so it's your call to run, not mine to run unprompted.

## 5. Status of yesterday's other open items — unchanged

Re-checked, no commit since touched these:

| Item | Status |
|---|---|
| §6 item #6, inbound Meta message persistence | **[Fact] Still open.** `MetaWebhookController::handleInboundMessages()` still routes straight to the chatbot/Journey engine with no row written anywhere — confirmed by reading the method body directly. The `gateway_message_id` correlation added in `8cbc7c4` (§2 above) is a different, narrower thing (outbound failed-status correlation), not inbound history. |
| §6 item #4, real test coverage | **[Fact] Still effectively zero.** CI infrastructure now exists (§2, Med #4), but no new PHPUnit test bodies were added — the commit is explicit about why (no runnable `php` anywhere to verify tests against). The stub `ExampleTest.php` files are still the only tests in the repo. |
| §6 item #7, hardcoded pricing | **[Fact] Still open**, explicitly deferred as a business decision. |
| §6 item #10, frontend page-file bloat | **[Fact] Still open, still growing** — `ContactGroupsPage.tsx` added 617 lines this pass alone. |

## 6. If you do one thing after reading this

Run `php artisan migrate` in `backend-api` (command above) before testing anything shipped in the last 4 commits — right now the group-messaging feature, and a wide swath of older features going back to Sep 9, have no backing tables in this database file.
