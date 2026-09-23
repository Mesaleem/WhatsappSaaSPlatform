# Phase 6 — CRM Task 10: Meta / Ads Lead Capture → CRM Integration

**Date:** 2026-09-23 · **Status:** ✅ PASS · Backend 1252/1252 (MariaDB 10.11.14) · Frontend 523/523 (unchanged)

## 1. Starting point (inspected, not assumed)

The capture → CRM promotion already existed from CRM Hardening Rounds 1–2:
`CaptureLeadLinker::linkQuietly()` is called by all three capture writers
(`MetaLeadWebhookHandler`, `MetaWebhookController::captureCtwaLead()`,
`WhatsAppJourneyEngine::upsertLead()`), the link lives on `crm_leads.capture_lead_id`
(composite tenant FK + unique), Contacts are resolved/reused by `ContactResolver`, and
failures go to `crm_capture_link_failures`. Task 10 therefore closes three gaps against
the spec instead of building a parallel path:

| Gap | Before | After |
|---|---|---|
| CTWA source | `whatsapp_ctwa` → `whatsapp` | `whatsapp_ctwa` → **`meta_ad`** |
| CRM entitlement | promoted for **every** account, entitled or not | promoted only with active `crm` capability **and** `lead_crm` module; otherwise `not_entitled` failure row, retryable |
| Concurrent duplicate | losing insert → unique violation → spurious `exception` failure row | losing insert returns the winner, no failure |

## 2. Files

| File | Change |
|---|---|
| `app/Services/Crm/CaptureLeadLinker.php` | `accountMayUseCrm()`; entitlement gate in `linkQuietly()` (after the existing-link read); unique-violation race handling in `link()` (creation moved into private `createFor()`) |
| `app/Models/CrmLead.php` | `SOURCE_BY_CAPTURE_PROVIDER['whatsapp_ctwa'] = meta_ad` + docblock |
| `app/Models/CrmCaptureLinkFailure.php` | `REASON_NOT_ENTITLED` |
| `tests/Feature/CrmMetaAdsCaptureIntegrationTest.php` | **new**, 32 tests |
| `tests/Feature/CrmHardeningTest.php` | CTWA expectation → `meta_ad`; Lead Ads end-to-end test grants `crm` |
| `tests/Feature/CrmHardeningRound2Test.php` | 3 failure/retry tests grant `crm` (they exercise `linkQuietly()`) |

No migration, route, permission, capability, plan, seeder or frontend change.

## 3. Verification

| Check | Result |
|---|---|
| Full suite, MariaDB 10.11.14 (throwaway DB) | **1252 passed**, 4882 assertions, 0 failed, 0 skipped |
| Full suite, SQLite | 1248 passed + 4 skipped (pre-existing FK skips), 4873 assertions |
| CRM tests (`--filter=Crm`, MariaDB) | 594 passed |
| Meta/Ads/Social/Lead tests (`--filter='Meta\|Social\|Lead\|Ads\|Ctwa'`, MariaDB) | 651 passed |
| New test file | 32 passed on both engines |
| Mutation checks | gate removed → 6 fail; race catch removed → 1 fails; old CTWA mapping → 1 fails |
| `migrate` on empty throwaway MariaDB | 110 ran, 0 pending; `database/` byte-identical to baseline |
| `php -l` changed files | clean |
| Frontend `tsc -b` / `npm test` / `npm run build` / `npm run lint` | clean / 16 files 523 passed / built / 64 warnings 0 errors (baseline) |

## 4. Known limitations

1. CTWA leads promoted before Task 10 keep `source = whatsapp` (no data rewrite).
2. `not_entitled` captures are not auto-promoted on upgrade; `retry()` exists but has no UI/API/command.
3. The gate also applies to journey `save_lead` captures (same linker, same CRM rule).
4. A repeat CTWA click by the same person is a new CRM lead on the same Contact (Task 3 rule); Lead Ads keeps its 24 h same-phone collapse.
5. The Instant Lead screen (`/api/social/leads`) does not show the linked CRM lead; the link is visible from the CRM lead detail (`capture_lead`).
