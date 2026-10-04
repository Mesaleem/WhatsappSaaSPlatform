<?php

/*
 * Phase 12 Task 6 — operational risk of migrations 111-142, one entry per file. Read by
 * tests/Feature/MigrationSafetyTest, which fails when a migration in this range is unclassified, when an entry
 * names a file that no longer exists, and when a classification understates what the file's up() actually does
 * (e.g. a data write classed as schema-only, an ALTER classed as schema-only, ->change() classed below `locking`).
 *
 * risk:
 *   schema-only    only creates NEW tables - touches no existing data, takes no lock on existing tables
 *   additive       adds nullable/defaulted columns or a small index to an EXISTING table
 *   locking        ALTER on an existing, potentially large table that builds an index/FK/CHECK or modifies a column -
 *                  can be slow or block writes on a big table: run off-peak
 *   data-changing  writes, updates or backfills ROWS (idempotent unless noted)
 * rollback (what `migrate:rollback` does when this migration is reversed):
 *   clean          drops exactly what up() added; no data outside that is touched
 *   conditional    reversible only in some data states (see note)
 *   data-loss      down() deletes rows other data may reference - only from a backup-verified state
 *   none           down() is a no-op
 *
 * Never run any of these against the live database to "test" them: rehearse with
 * `php artisan ops:rehearse-migrations` on a throwaway database (docs/RELEASE_AND_RECOVERY.md).
 */
return [
    '2026_09_23_130000_create_super_admin_platform_crm_account' => ['risk' => 'data-changing', 'rollback' => 'conditional', 'note' => 'Creates the platform CRM account only when a Super Admin exists (idempotent). down() removes it ONLY while it holds no CRM data, otherwise leaves it.'],
    '2026_09_24_100000_add_temporal_state_to_whatsapp_flow_sessions_table' => ['risk' => 'locking', 'rollback' => 'clean', 'note' => 'Adds 3 columns + a (status, wait_until) index to whatsapp_flow_sessions: the index build scans a table that grows per customer run.'],
    '2026_09_24_110000_support_journey_automation_on_qr_provider' => ['risk' => 'data-changing', 'rollback' => 'clean', 'note' => 'Updates ONE provider_capabilities row (qr/journey_automation); no-op if absent. down() restores the old value.'],
    '2026_09_24_120000_create_group_dispatch_recipients_table' => ['risk' => 'schema-only', 'rollback' => 'clean', 'note' => 'New table; touches no existing data.'],
    '2026_09_24_130000_create_whatsapp_flow_versions_table' => ['risk' => 'locking', 'rollback' => 'clean', 'note' => 'New table plus nullable FK columns (and FKs) on whatsapp_flows and whatsapp_flow_sessions: FK creation validates/rebuilds existing rows.'],
    '2026_09_24_130001_backfill_whatsapp_flow_versions' => ['risk' => 'data-changing', 'rollback' => 'none', 'note' => 'Backfills one version row per existing flow and pins sessions; chunked (200), per-flow transaction, idempotent. down() is a no-op; rolling back migration 115 drops the columns it filled. Duration grows with flow/session count.'],
    '2026_09_24_140000_create_inbound_message_events_and_conversation_locks_tables' => ['risk' => 'schema-only', 'rollback' => 'clean', 'note' => 'Two new tables (dedup ledger, conversation locks).'],
    '2026_09_24_150000_add_claim_columns_to_message_dispatch_logs_table' => ['risk' => 'locking', 'rollback' => 'clean', 'note' => 'Adds claim_token/claimed_at and a 3-column index to message_dispatch_logs, a high-volume table: schedule off-peak.'],
    '2026_09_24_160000_add_plan_terms_snapshot_to_invoices_table' => ['risk' => 'additive', 'rollback' => 'clean', 'note' => 'Six nullable snapshot columns on invoices; no index, no backfill.'],
    '2026_09_24_170000_create_journey_execution_events_table' => ['risk' => 'schema-only', 'rollback' => 'clean', 'note' => 'New table with seven indexes (empty at creation).'],
    '2026_09_25_100000_create_credit_system_tables' => ['risk' => 'schema-only', 'rollback' => 'clean', 'note' => 'Three new credit tables; CHECK constraints are added (MySQL family) while the tables are empty.'],
    '2026_09_25_110000_add_plan_credit_allocation' => ['risk' => 'locking', 'rollback' => 'clean', 'note' => 'Adds columns to plans/invoices and columns + unique + indexes + FK to usage_quotas: index/FK builds over existing quota rows.'],
    '2026_09_25_120000_add_credit_reservation_expiry' => ['risk' => 'locking', 'rollback' => 'clean', 'note' => 'ALTER TABLE ADD CONSTRAINT CHECK (MySQL family) validates every existing credit_reservations row; plus a nullable column and index.'],
    '2026_09_29_100000_create_ai_operations_table' => ['risk' => 'schema-only', 'rollback' => 'clean', 'note' => 'New table.'],
    '2026_09_29_110000_create_knowledge_base_tables' => ['risk' => 'schema-only', 'rollback' => 'clean', 'note' => 'New knowledge-base tables.'],
    '2026_09_29_120000_create_ai_agent_tables' => ['risk' => 'schema-only', 'rollback' => 'clean', 'note' => 'New AI-agent tables.'],
    '2026_09_29_130000_add_connection_lifecycle_to_social_accounts' => ['risk' => 'additive', 'rollback' => 'clean', 'note' => 'Four nullable columns on social_accounts; no index.'],
    '2026_09_30_100000_add_health_check_claim_to_social_accounts' => ['risk' => 'additive', 'rollback' => 'clean', 'note' => 'One nullable column + index on social_accounts (small table).'],
    '2026_09_30_110000_add_publishing_lifecycle_to_organic_posts' => ['risk' => 'additive', 'rollback' => 'clean', 'note' => 'Twelve nullable/defaulted columns and one FK on organic_posts.'],
    '2026_09_30_120000_create_organic_post_insights_table' => ['risk' => 'schema-only', 'rollback' => 'clean', 'note' => 'New table.'],
    '2026_09_30_130000_create_ad_attributions_table' => ['risk' => 'schema-only', 'rollback' => 'clean', 'note' => 'New table.'],
    '2026_09_30_140000_harden_ad_campaign_lifecycle' => ['risk' => 'locking', 'rollback' => 'conditional', 'note' => 'ad_campaigns: column modification (->change()) plus a unique index. down() re-applies NOT NULL on meta_campaign_id, which fails if rows with NULL exist.'],
    '2026_09_30_150000_add_currency_to_ad_campaigns' => ['risk' => 'additive', 'rollback' => 'clean', 'note' => 'One nullable column on ad_campaigns.'],
    '2026_10_01_100000_add_converted_at_index_to_ad_attributions' => ['risk' => 'additive', 'rollback' => 'clean', 'note' => 'One composite index on ad_attributions.'],
    '2026_10_01_110000_create_collections_tables' => ['risk' => 'schema-only', 'rollback' => 'clean', 'note' => 'New collections tables.'],
    '2026_10_01_110100_register_billing_collections_capability' => ['risk' => 'data-changing', 'rollback' => 'data-loss', 'note' => 'Idempotent upsert of the billing_collections capability. down() DELETES every account/plan entitlement and provider row for it.'],
    '2026_10_01_110200_add_lifecycle_to_collection_charge_assignments' => ['risk' => 'additive', 'rollback' => 'clean', 'note' => 'Three nullable columns + FK on collection_charge_assignments.'],
    '2026_10_02_100000_create_account_industries_table' => ['risk' => 'schema-only', 'rollback' => 'clean', 'note' => 'New table.'],
    '2026_10_02_100100_register_industry_modules_foundation' => ['risk' => 'data-changing', 'rollback' => 'data-loss', 'note' => 'Idempotent upsert of industry capabilities, a permission and route-master rows. down() DELETES entitlements, plan entitlements and the permission for them.'],
    '2026_10_03_100000_create_education_foundation_tables' => ['risk' => 'schema-only', 'rollback' => 'clean', 'note' => 'New education tables.'],
    '2026_10_03_100100_register_education_permissions' => ['risk' => 'data-changing', 'rollback' => 'data-loss', 'note' => 'Idempotent permission upsert. down() DELETES those permissions (and with them any role assignments).'],
    '2026_10_04_100000_create_education_attendances_table' => ['risk' => 'schema-only', 'rollback' => 'clean', 'note' => 'New table.'],
];
