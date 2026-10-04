# Release pipeline, backup/restore verification and recovery

Scope: how a release is verified, how a database backup is proven restorable, how the Laravel `APP_KEY` and provider
credentials are recovered, and how migrations are rehearsed. This document contains **no secrets**; it names variables,
tables and commands only. It does not describe production backup automation, DR/failover or replication — those are
deliberately out of scope.

## Release verification (CI)

`.github/workflows/ci.yml` runs, on every pull request and push to `main`:

| Job | What it proves |
|---|---|
| `backend-sqlite` | `php artisan test --group=release-safety` (fast gate), then the full SQLite suite, then a fresh-SQLite migration rehearsal |
| `backend-mariadb` | the full suite on a fresh MariaDB 10.11, `ops:rehearse-migrations --seed`, a dump → restore → `ops:verify-restore` cycle on throwaway databases, and the concurrency / inbound-gate / outbound-pacing probes |
| `frontend` | `tsc --noEmit`, Vitest, production build |
| `qr-engine-service` | syntax check (unchanged) |

Local equivalent: `php artisan test`, the same with `DB_CONNECTION=mariadb` against a throwaway database, the probes under
`backend-api/tests/Probes`, and in `frontend-app`: `npx tsc --noEmit`, `npx vitest run`, `npm run build`.
`tests/Feature/ReleasePipelineTopologyTest` fails if the workflow loses one of these steps.

## Backup and restore verification

A backup is only a backup once a restore of it has been verified. Never restore over, or run migrations against, the
live database to test this.

1. Take the backup (existing procedure, e.g. `mysqldump --single-transaction --routines --triggers <db> | gzip > backup.sql.gz`).
2. Create an empty **throwaway** database whose name matches `RECOVERY_THROWAWAY_DB_PATTERN`
   (default: contains `throwaway`, `rehearsal`, `verify` or `scratch`, or starts/ends with `test`), e.g. `wa_throwaway_restore_check`.
3. Load the backup into it: `gunzip -c backup.sql.gz | mysql <throwaway-db>`.
4. Run `php artisan ops:verify-restore --database=wa_throwaway_restore_check` (add `--json` for machines).

`ops:verify-restore` is **read-only**: it issues SELECTs and schema reads only, refuses any database that is not
recognisably a throwaway one, refuses the application's own database, and prints check names and counts — never row values.
It checks: connection; the migrations table matches this release exactly; critical tables and columns exist; permissions,
capabilities and plans are populated; the `super_admin`, `admin` and `agent` roles and at least one Super Admin user survive;
no orphaned rows in the key relationships; every sampled `encrypted` column decrypts with the running `APP_KEY`; and the
application's own models can read the restored data. Exit `0` = passed, `1` = a check failed, `2` = refused.

## APP_KEY escrow and recovery

**What the key protects.** `APP_KEY` encrypts, with Laravel's encrypter, every `encrypted` column (listed in
`config/recovery.php`: payment-gateway secrets, social provider client secrets and tokens, outbound-webhook secrets, SMTP
password, per-tenant Gemini key, the Meta access token of each WhatsApp session) and secret values inside journey graphs
(`enc:v1:` prefix). It also signs sessions, cookies and signed URLs. **A database backup contains only the ciphertext. Without
the exact key the backup restores structurally but every one of those values is permanently unreadable.**

**Escrow (do this when the key is generated, and again after any rotation).**

1. Store the key in at least two independent secret stores controlled by different people (e.g. the organisation's vault and
   a sealed offline copy). Never in the repository, a ticket, a chat or a backup file.
2. Record the key's *fingerprint* — the first 12 hex characters of SHA-256 of the key bytes — in `RECOVERY_APP_KEY_FINGERPRINT`
   (the fingerprint is not a secret). `php artisan ops:check-recovery` prints the running key's fingerprint.
3. Note who may retrieve the key and the date of the last escrow check.

**Verification (run after every deploy and every restore).** `php artisan ops:check-recovery` fails if `APP_KEY` is missing or
not a valid key for the configured cipher, if `APP_PREVIOUS_KEYS` contains an invalid entry, if the running key's fingerprint
differs from `RECOVERY_APP_KEY_FINGERPRINT`, or if sampled encrypted values cannot be decrypted. It warns when no fingerprint is
recorded. It never prints the key.

**Rotation.** Put the new key in `APP_KEY` and the old one in `APP_PREVIOUS_KEYS` (comma separated) so existing ciphertext stays
readable; re-save the encrypted values over time; escrow the new key and update the fingerprint; keep the old key escrowed until
`ops:check-recovery` shows every sampled value decrypts under the new key alone.

**Recovery.** (1) Restore the key from escrow into the deployment's secret store, never a file in the repo. (2) Confirm
`ops:check-recovery` reports a matching fingerprint. (3) Verify any restored database with `ops:verify-restore`. If the key is
lost entirely, the encrypted values cannot be recovered: re-enter provider credentials (below) and have tenants reconnect.

## Provider credential recovery

| Credential | Where it lives | In a DB backup? | Recovery |
|---|---|---|---|
| `APP_KEY`, `APP_PREVIOUS_KEYS` | environment / secret store | no | escrow (above) |
| `DB_PASSWORD`, `REDIS_PASSWORD`, `MAIL_PASSWORD`, `AWS_*` | environment / secret store | no | operator secret store; rotate at the provider if lost |
| `INTERNAL_API_SECRET` | environment of this API **and** qr-engine-service | no | generate a new value, set it on both, restart both |
| `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY` | environment | no | re-issue at the provider |
| Per-tenant Gemini key (`accounts.gemini_api_key`) | database, encrypted | ciphertext only | needs `APP_KEY`; otherwise the tenant re-enters it |
| Payment gateway secrets (`payment_gateway_settings`) | database, encrypted | ciphertext only | needs `APP_KEY`; otherwise Super Admin re-enters from the gateway dashboard |
| Social app credentials (`social_provider_configs`), connected accounts (`social_accounts`) | database, encrypted | ciphertext only | needs `APP_KEY`; otherwise re-enter app credentials and have tenants reconnect |
| Meta access token per WhatsApp session (`whatsapp_sessions.meta_access_token`) | database, encrypted | ciphertext only | needs `APP_KEY`; otherwise reconnect the number |
| Outbound webhook secrets, SMTP password | database, encrypted | ciphertext only | needs `APP_KEY`; otherwise re-enter / re-create |
| API keys and secrets issued to tenants (`api_keys`), Sanctum tokens | database, **hashed** | hash only | not recoverable by design — tenants re-issue |

A restored backup therefore never contains a usable plaintext credential, and the commands above print none. Logs and test
output are covered by the existing redaction rules (Phase 12 Task 3).

## Migration safety

`database/migration_risk.php` classifies migrations 111–142 as `schema-only`, `additive`, `locking` or `data-changing`, and
states what each rollback does (`clean`, `conditional`, `data-loss`, `none`). `tests/Feature/MigrationSafetyTest` fails when a
migration is unclassified or the classification understates what the file does.

Before deploying migrations: take a backup and verify its restore (above); read the `locking` and `data-changing` entries among
the pending migrations and schedule them off-peak; rehearse the whole chain on a throwaway database with
`php artisan ops:rehearse-migrations --seed` (it runs `migrate:fresh`, so it refuses any database that is not a throwaway one and
refuses `APP_ENV=production`). Rollback is supported for every migration in 111–142, but `data-loss` entries (136, 139, 141) delete
rows and `conditional` entries (111, 132) depend on the data, so roll back only from a backup-verified state, using
`migrate:rollback --step=N` for exactly the migrations you intend to undo.
