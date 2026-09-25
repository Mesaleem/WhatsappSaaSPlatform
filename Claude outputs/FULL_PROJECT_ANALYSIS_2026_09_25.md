# `wa-saas-platform` — Full Project Analysis (2026-09-25)

**Method:** Read-only, on-device source inspection (`C:\xampp\htdocs\old-saas\WhatsappSaaSPlatform`) via the connected-folder shell: `git log` / `git diff 3d8f15b..HEAD` / `git status`, the new bulk-dispatch services, job, controller, request classes, migrations, `routes/api.php`, `routes/console.php`, `.github/workflows/ci.yml`. Nothing executed, nothing mutated, no server started. `.env` files were not readable from this shell (protected location) and were not needed.

This is a delta report on top of `FULL_PROJECT_ANALYSIS_2026_09_16.md`, whose architecture/security/feature inventory remains accurate and is not re-derived here. Tags: **[Fact]** verified in a file/command · **[Inference]** conclusion from facts · **[Unknown]** not verifiable from here.

---

## 1. What changed since Sep 16 (12 commits, 65 files, +3,735 / −695)

**[Fact]** Commits `5d60e22` → `0c53bce` (Sep 16–25). Substantive features, grouped:

| Area | Change | Key files |
|---|---|---|
| **Anti-spam bulk template send** (new) | `POST /api/alerts/send-template-bulk` queues ≤150 recipients as individually delayed jobs: batches of 10, 2–4 s jitter per message, 60–120 s pause per batch boundary. A full 150-recipient dispatch starts a 6 h cooldown (4 h if `contact_groups` module enabled). `GET /api/alerts/bulk-cooldown-status` feeds the UI banner. | `Services/Templates/BulkMessageDispatcher.php`, `BulkMessageCooldown.php`, `Jobs/SendWhatsAppTemplateJob.php`, `Requests/SendBulkTemplateMessageRequest.php`, `routes/console.php`, `SendAlertPage.tsx` (+400) |
| **Template code / group code** | Human-readable `template_code` on `message_templates` and `group_code` on `contact_groups`; external API can send by code (`SendTemplateByCodeRequest`). | migrations `…add_template_code…`, `…add_group_code…`, `V1/TemplateMessageController.php` |
| **Media on dispatch logs** | `media_url` persisted on `message_dispatch_logs`; media threaded through both dispatchers. | migration, `TemplateMessageDispatcher.php`, `DirectMessageDispatcher.php` |
| **Quota top-up by amount** | `quota_requests.requested_topup_amount`; approval credits `subscription.price_paid` inside a `DB::transaction`, balance = `floor(price_paid / rate_per_message)`. Three follow-up "fix price_paid display" commits on Sep 18. | `QuotaRequestController.php` (+222), `BillingController.php`, `ClientBillingSummaryTable.tsx`, `QuotaTopUpModal.tsx` |
| **User soft delete** | `users.deleted_at` + `SoftDeletes` trait; `TeamController::destroy` revokes tokens then soft-deletes. | migration `2026_09_25_100000`, `Models/User.php`, `TeamController.php` |
| **Frontend** | `MessageLogsPage.tsx` largely rewritten (573 lines changed), new `PromptModal.tsx`, dashboard filter fixes (3 commits), `AccountsPage`, `TemplateManagerPage` growth. | — |

**[Fact]** `qr-engine-service/` has **zero** changes in this window. `backend-api` has zero uncommitted changes.

---

## 2. Findings on the new bulk-dispatch path (the largest new surface)

### 2.1 The "strict" 150 cap + cooldown is bypassable by design — [Fact]
`MessageTemplateController::sendBulk()` only calls `BulkMessageCooldown::lock()` when `queued_count >= 150`. A caller sending 149 recipients per request never triggers a cooldown and can repeat immediately. Five requests of 149 = 745 messages with no lock. If the intent is an hourly/daily *volume* ceiling, the cooldown must key on cumulative sends in a window, not on a single request hitting exactly the cap.

### 2.2 Check-then-act race on the cooldown — [Fact]
`remainingSeconds()` (read) and `lock()` (write) are separated by the whole dispatch. Two concurrent 150-recipient requests both pass the check and both enqueue. Low probability from the UI, trivial to do from a script against the API. Fix is small: `Cache::add()` (atomic set-if-absent) on a short "in-flight" key before dispatching, or move the lock before `BulkMessageDispatcher::dispatch()`.

### 2.3 Delivery depends entirely on an external cron that the repo cannot prove exists — [Fact] / [Unknown]
`SendWhatsAppTemplateJob` is dispatched to `database` / `whatsapp-bulk`, and the only drain is `Schedule::command('queue:work database --queue=whatsapp-bulk --stop-when-empty --max-time=55')->everyMinute()` in `routes/console.php`. That runs only if `php artisan schedule:run` is invoked every minute by the OS. The README (§ deployment) documents a Linux crontab line; the code's own docblock says no worker/supervisor definition exists in the repo. **[Unknown]** whether the XAMPP/Windows machine this runs on has a Task Scheduler entry. If not, every bulk send returns 200 "queued" and nothing is ever sent — a silent failure mode with no user-visible signal. This needs to be verified on the actual host before the feature is considered live.

### 2.4 Quota is checked per message at send time, not reserved at enqueue — [Fact]
`TemplateMessageDispatcher::dispatch()` (unchanged) does the quota check inside each job. An account with 10 messages left can queue 150; 140 will land as `quota_exhausted` rows on Message Logs 20–40 minutes later. Not a bug, but the 200 response ("150 message(s) queued") is misleading. A pre-flight `min(quota_remaining, count)` check in `sendBulk()` would cost one query.

### 2.5 What is done well — [Fact]
`$tries = 1` (no duplicate real-world sends on retry), reuse of the single-send dispatcher so audit logging is identical, `Cache::put` with an absolute expiry instant so `remainingSeconds()` works on the `database` cache driver, and the bulk path scoped to its own connection so the app's `QUEUE_CONNECTION=sync` behavior for every other job is untouched. The docblocks accurately describe the constraints they were written under.

---

## 3. Soft-deleting users — one functional gap [Fact]

`users.email` is `unique()` at the DB level, and `TeamController::store()` / `AccountController` validate with `Rule::unique('users','email')` **without** `->withoutTrashed()`. Laravel's unique rule does not exclude soft-deleted rows, so a deleted team member's email can never be re-invited: the user gets a 422 "email has already been taken" with no way to resolve it from the UI. Options: (a) add `->withoutTrashed()` to the rule *and* restore-on-reinvite logic; (b) on delete, rewrite the email to `<email>#deleted-<id>` (common pattern, preserves the unique index); (c) accept and document. Sanctum token lookup respects the global scope, so deleted users are correctly locked out — that side is fine.

---

## 4. Repository hygiene — the same issue, third report in a row [Fact]

`git status` shows **188 modified files**; `git diff -w --stat` is **empty**. Every one is CRLF-vs-LF (`file` confirms `App.tsx`, `CLAUDE.md` are CRLF on disk). All 188 are outside `backend-api/`, which is the only directory with a `.gitattributes` (`* text=auto eol=lf`). There is no root `.gitattributes`. This was recommended on Sep 15 and Sep 16; ten commits have landed since without it. **[Inference]** the noise is at least partly a Windows-checkout-viewed-from-Linux artifact, so it may look clean in the developer's own shell — but it also means any commit made from a different-`autocrlf` machine will produce whole-file diffs. One-time fix: root `.gitattributes` with `* text=auto eol=lf`, then `git add --renormalize .` in a dedicated commit.

Also **[Fact]**: 11 of the last 12 commit messages are `fix the X issue` / `fix the dashboard filter issue` ×3. The commit history no longer explains *what* changed; the `Claude outputs/` markdown files are doing that job instead, which does not survive a `git log` search.

---

## 5. Unchanged since Sep 16 (still open)

- **[Fact]** Test coverage: `tests/` is still the two stock `ExampleTest.php` files, 45 lines total. CI runs them against sqlite. None of the Sep 16–25 logic (cooldown, batch scheduling, top-up arithmetic, soft delete) has a test.
- **[Fact]** Baileys `sessions/<id>/creds.json` unencrypted at rest; single-instance in-memory session map.
- **[Fact]** `module.guard` not backfilled onto older route groups.
- **[Fact]** Dead `database/database.sqlite` still present.

---

## 6. Priority-ordered recommendations

1. **Verify the scheduler on the real host** (§2.3). Until `schedule:run` is confirmed running every minute there, treat bulk send as non-functional in production. Cheapest check: `SELECT COUNT(*) FROM jobs WHERE queue='whatsapp-bulk'` after a test send — a growing, never-draining count is the failure signature.
2. **Close the cooldown bypass and race** (§2.1, §2.2). Two small changes in `sendBulk()`; no schema change.
3. **Root `.gitattributes` + renormalize** (§4). One commit, ends the recurring noise.
4. **Decide the soft-delete/email policy** (§3) before a tenant hits it.
5. **First real tests** — `BulkMessageCooldown` and `BulkMessageDispatcher` are pure, static, cache-backed classes with no WhatsApp dependency: they are the cheapest place in the codebase to start a test suite, and they guard the newest revenue-relevant behavior.
6. Adopt conventional commit messages (already used once: `fix: updated price_paid balance display calculation`).
