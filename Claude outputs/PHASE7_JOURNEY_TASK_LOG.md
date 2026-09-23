# Phase 7 — Journey task log

| # | Task | Status | Migration | Suite after | Notes |
|---|---|---|---|---|---|
| 1 | Journey Foundation Audit + Temporal Execution Backbone | ✅ | `2026_09_24_100000_add_temporal_state_to_whatsapp_flow_sessions_table` (additive; **not run on the real DB** — owner runs `php artisan migrate`) | **1408** MariaDB · 1404 + 4 SQLite · frontend 545 | `delay` node executes: session parks as `waiting` (`wait_until`); `journeys:resume-due` → `ResumeJourneySessionJob` (`database:journeys`) → `WhatsAppJourneyEngine::resumeDueSession()` (atomic claim + lease, checkpointed retry 60 s/120 s, `failed` + `last_error` after 3, cancel, flow-toggle hold). Cancel route `POST /api/whatsapp/flows/{id}/sessions/{sessionId}/cancel`. Immediate path pinned by `JourneyImmediateExecutionTest` (10 tests, green before and after). `JourneyTemporalExecutionTest` 26 tests. |

State protection: all runs on throwaway MariaDB (`wa_p7t1_full`, `wa_p7t1_mig`) and in-memory SQLite. The real `wa_saas_platform` database was not touched.

| # | Task | Status | Migration | Suite after | Notes |
|---|---|---|---|---|---|
| 1.5 | Journey Capability / Entitlement Hardening | ✅ | `2026_09_24_110000_support_journey_automation_on_qr_provider` (data-only, one `provider_capabilities` row; **not run on the real DB**) | **1419** MariaDB · 1415 + 4 SQLite · frontend 545 (no frontend change) | Owner decision (AskUserQuestion): QR supports journey_automation. `capability.guard:journey_automation` on all 9 journey routes. Seeder row flipped. 7 existing test files updated to the new rule (QR-refusal examples moved to `ads`/`commerce`; API fixtures given journey_automation). New `JourneyCapabilityGateTest` (11). Existing Growth accounts: `php artisan entitlements:backfill-plan` (dry-run first). |
| 1.6 | Enforce Journey Entitlement at Runtime | ✅ | none | **1431** MariaDB · 1427 + 4 SQLite · frontend 545 (type only) | `JourneyRuntimeEntitlement` (chatbot module + journey_automation) checked on inbound (open session / matched trigger) and on resume (post-claim + per node). Refused → session `blocked`, state kept, no send/lead/quota. Restored inline on next inbound or by `journeys:resume-due`; continues from checkpoint. Fixtures granted journey_automation in `JourneyImmediateExecutionTest` (2 lines, assertions untouched) and `CrmLeadSourcesIndependenceTest`. New `JourneyRuntimeEntitlementTest` (12). |

## Phase 5 audit fixes

| Item | Fix | Status | Migration | Suite after |
|---|---|---|---|---|
| P5-1 | Freeze group recipients at reservation: both dispatchers read membership + `reserve()` + write `group_dispatch_recipients` in one transaction; both jobs iterate the frozen list (added → not sent; removed → failed + refunded; pre-fix batches capped at `recipient_count`). New `GroupRecipientFreezeTest` (15, both paths). | ✅ | `2026_09_24_120000_create_group_dispatch_recipients_table` (new table; **not run on the real DB**) | **1446** MariaDB · 1442 + 4 SQLite |

## Phase 7 (continued)

| # | Task | Status | Migration | Suite after | Notes |
|---|---|---|---|---|---|
| 2 | Journey Versioning and In-Progress Run Isolation | ✅ | `2026_09_24_130000_create_whatsapp_flow_versions_table`, `2026_09_24_130001_backfill_whatsapp_flow_versions` (**not run on the real DB**) | **1461** MariaDB · 1457 + 4 SQLite · frontend 545 (type only) | Immutable `whatsapp_flow_versions`; snapshot on every graph-changing save (locked numbering); `published_version_id` for new sessions; sessions pinned via `flow_version_id`; engine reads only the pinned graph. Version list/show/publish endpoints; optional `publish:false` drafts. New `JourneyVersioningTest` (14); Task 1 graph-drift test updated to the new semantics (+1 defensive test). |
| 3 | Journey Trigger Reliability, Idempotency & Event Inbox | ✅ | `2026_09_24_140000_create_inbound_message_events_and_conversation_locks_tables` (**not run on the real DB**) | **1478** MariaDB · 1474 + 4 SQLite | `InboundEventGate` in `ChatbotEngineService`: per-(account, phone) DB lease + durable claim (unique account/provider/event_key). Meta key = WAMID (replaces the 24 h cache claim); QR key = Baileys `msg.key.id` (qr-engine-service `notifyInboundMessage` now sends `message_id`). New `JourneyInboundIdempotencyTest` (17). Multi-process MariaDB proof: 8 identical concurrent events → 1 start; 6 concurrent distinct answers → 1 advance ("Thanks" ×1; without the lease ×5). |
