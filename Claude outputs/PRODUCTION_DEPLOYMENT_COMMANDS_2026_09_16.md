# Production Deployment Command List — Schema Catch-Up

**Date:** 2026-09-16
**Scope:** wa-saas-platform / backend-api
**Companion file:** `2026_09_16_999999_sync_production_baseline_schema.php` (place in `database/migrations/` before Step 4)

## STRICT RULE (non-negotiable, enforced throughout this guide)

No command below drops a table, drops a column, truncates a row, or runs `migrate:fresh` / `db:wipe`. Every schema-changing command is either read-only (a status/backup check) or additive-only (the catch-up migration itself). If any step's output looks unexpected, **stop and do not proceed to the next step** — re-read the output first.

---

## 0. Before you start — one-time preparation (on your local machine)

1. Copy `2026_09_16_999999_sync_production_baseline_schema.php` into `backend-api/database/migrations/` in your working tree.
2. Review the file's own docblock (top of file) — it explains exactly why each guarded block exists.
3. Commit it and push/deploy it to the production server through your normal release process (git pull, rsync, CI/CD artifact — whichever this project already uses). Do **not** hand-copy it directly onto the server outside your normal deploy pipeline; it must go through the same code review / version control path as any other migration.

---

## 1. SSH in and go read-only first

```bash
ssh <deploy-user>@<production-host>
cd /path/to/wa-saas-platform/backend-api
```

**Read-only pre-flight check — run this first, look at the output, and do not proceed until you've read it:**

```bash
php artisan migrate:status
```

This lists every migration file and whether the database already thinks it "Ran". It costs nothing and touches nothing. If you see migrations already marked "Ran" that you did not expect, or migrations missing entirely, stop and investigate before continuing — the catch-up migration is designed to be safe either way, but you should know what you're looking at.

---

## 2. Take a full database backup (mandatory, non-negotiable)

```bash
mkdir -p ~/db-backups
mysqldump --single-transaction --routines --triggers \
  -u <db_user> -p <db_name> \
  | gzip > ~/db-backups/wa_saas_platform_pre_sync_$(date +%Y%m%d_%H%M%S).sql.gz
```

Confirm the file was written and has a non-trivial size before continuing:

```bash
ls -lh ~/db-backups/ | tail -5
```

This is your rollback path if anything downstream (application code, not this migration) misbehaves after deploy. This migration itself is additive-only and does not require a restore to be "undone" in the destructive sense, but a fresh backup before any production schema touch is standard practice and non-negotiable here.

---

## 3. Put the application into maintenance mode

```bash
php artisan down --secret="<a-private-bypass-token>" --render="errors::503"
```

(Use whatever maintenance-mode convention this project already has in its deploy scripts, if one exists — this is the Laravel default.)

---

## 4. Pull the new code (which includes the catch-up migration file)

```bash
git fetch origin
git checkout <release-branch-or-tag>
git pull
```

Or whatever this project's existing deploy mechanism is (rsync, CI artifact unpack, etc.) — the important part is that `database/migrations/2026_09_16_999999_sync_production_baseline_schema.php` is now present on disk before Step 6.

---

## 5. Install dependencies (composer only — no destructive artisan commands here)

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

---

## 6. Run the safe, additive-only migration

```bash
php artisan migrate --force
```

`--force` is required because `APP_ENV=production` otherwise blocks migrations from running without an interactive confirmation prompt — it does **not** change what the migration is allowed to do. Every operation inside `2026_09_16_999999_sync_production_baseline_schema.php` is wrapped in a `Schema::hasTable()` / `Schema::hasColumn()` / custom index-and-foreign-key-existence check, so this command only ever creates a table that is missing or adds a column/index/foreign key that is missing. It will not touch a table, column, index, or row that already exists.

Watch the output. Expect to see `2026_09_16_999999_sync_production_baseline_schema ... DONE`. If it also lists other, older pending migrations running first, that is expected — Laravel always applies pending migrations in filename order, and this file's own defensive checks make it safe to run after any subset of the other 70 have already applied (in whatever combination production's history actually has).

**If this step throws an error:** stop immediately, do not retry blindly, and do not proceed to Step 7. Copy the exact error text and cross-reference it against `storage/logs/laravel.log`. The most likely (and only expected) failure mode is a column type incompatibility if production's `accounts` table still carries its pre-2026-09-08 legacy names (`name` / `subscription_status` / `expires_at`) — see the migration file's own docblock, section 3, for what to check.

---

## 7. Fix file/folder permissions and ownership

Adjust the user:group below to whatever your web server actually runs as (commonly `www-data:www-data` on Ubuntu/Debian with Apache or Nginx+PHP-FPM):

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

---

## 8. Clear every cache layer, then rebuild the optimized versions

Clear first (always safe, never destructive to data — these only clear compiled PHP/cache artifacts, never database rows):

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear
php artisan cache:clear
```

Then rebuild the production-optimized versions:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

(`php artisan optimize` runs `config:cache` + `route:cache` + `event:cache` together in newer Laravel versions if you prefer a single command — either approach is equivalent and both are non-destructive to data.)

---

## 9. Re-sync permissions and the dynamic Route Master (safe, non-destructive)

These two seeders were read in full as part of this audit and confirmed idempotent: both use `firstOrCreate()` exclusively (never `updateOrCreate()`, never a raw `INSERT`/`UPDATE` on existing rows), so re-running them backfills anything missing and **never overwrites** a role, permission, category, or route a Super Admin has since customized through the app's own UI.

```bash
php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder --force
php artisan db:seed --class=Database\\Seeders\\RouteMasterSeeder --force
```

**Do NOT run** `php artisan db:seed --force` with no `--class` — that runs the full `DatabaseSeeder`, which also creates a hardcoded Super Admin account (`superadmin@wa-saas.local` / a placeholder password) and a "Demo Account" with a demo subscription and two demo users. That is correct for a fresh local dev database; it is never appropriate to run unscoped against production.

---

## 10. Restart PHP-FPM (and the queue worker, if one is running)

Clears PHP's in-process opcache of the old code so the newly deployed classes are actually used:

```bash
sudo systemctl restart php8.2-fpm   # match this to your actual PHP-FPM service name/version
sudo systemctl reload nginx         # or: sudo systemctl restart apache2
```

Check your `.env`'s `QUEUE_CONNECTION` first. Every dispatch pathway audited in this project (payment alerts, chatbot, journeys, broadcasts) is documented as running synchronously in this environment (`QUEUE_CONNECTION=sync`, per this project's own migration docblocks) — if that is still true on production, there is no separate queue worker process to restart. If production has since been configured with a real queue driver (`database`, `redis`, etc.) and a supervisor-managed `queue:work` process, restart it too:

```bash
sudo supervisorctl restart wa-saas-worker:*   # only if a queue worker actually exists on this server
```

---

## 11. Bring the application back up

```bash
php artisan up
```

---

## 12. Post-deploy verification (read-only — confirm before declaring done)

```bash
php artisan migrate:status | tail -20
tail -100 storage/logs/laravel.log
curl -I https://<your-production-domain>/api/health   # or whatever lightweight endpoint this app exposes
```

Also spot-check, from the application itself (not raw SQL against production):
- Log in as an existing Super Admin and confirm the dashboard loads.
- Open "Module & Feature Access" / Route Master screens and confirm existing categories/routes are still there, unchanged.
- Confirm an existing tenant's message history / analytics still renders (this exercises `message_dispatch_logs` and its newer columns).

If anything looks wrong, you have the Step 2 backup as your documented rollback path — restoring it is a decision to make deliberately, not a reflex, and should be authorized the same way the original deploy was.

---

## Summary of what this deployment intentionally never does

- Never `php artisan migrate:fresh`
- Never `php artisan db:wipe`
- Never a raw `DROP TABLE`, `TRUNCATE`, or `DELETE FROM` statement
- Never `php artisan db:seed --force` with no `--class` (the unscoped form) against production
- Never modifies an existing column's data, only ever adds missing schema structure
