<?php

/*
 * Phase 12 Task 6 — recovery / restore-verification expectations.
 *
 * Nothing here is a secret. `app_key_fingerprint` is the first 12 hex characters of SHA-256(APP_KEY bytes): enough
 * to confirm "this is the key that was escrowed", useless for recovering the key. Record it (RECOVERY_APP_KEY_FINGERPRINT)
 * when the key is escrowed; `php artisan ops:check-recovery` then fails if the running key differs.
 */
return [
    'app_key_fingerprint' => env('RECOVERY_APP_KEY_FINGERPRINT'),

    // A database may be used as a restore-verification / migration-rehearsal target ONLY if its name matches this
    // pattern (and, for restore verification, differs from the application's own database). Real environment
    // databases must not match it. Overriding is an operator decision, deliberately via env only.
    'throwaway_name_pattern' => env('RECOVERY_THROWAWAY_DB_PATTERN', '/(throwaway|rehearsal|verify|scratch|_test$|^test_)/i'),

    // Runbook that must explain APP_KEY escrow/recovery, restore verification and provider-credential recovery.
    'runbook_path' => base_path('docs/RELEASE_AND_RECOVERY.md'),
    'runbook_required_headings' => [
        'APP_KEY escrow and recovery',
        'Backup and restore verification',
        'Provider credential recovery',
        'Migration safety',
    ],

    // Tables and columns whose absence after a restore means the application cannot run.
    'critical_tables' => [
        'migrations' => ['migration', 'batch'],
        'users' => ['id', 'account_id', 'email', 'password'],
        'accounts' => ['id', 'account_type', 'status', 'agent_id'],
        'subscriptions' => ['id', 'account_id', 'status'],
        'plans' => ['id'],
        'capabilities' => ['id', 'slug'],
        'account_entitlements' => ['id', 'account_id', 'capability_id'],
        'roles' => ['id', 'name'],
        'permissions' => ['id', 'name'],
        'model_has_roles' => ['role_id', 'model_id'],
        'role_has_permissions' => ['permission_id', 'role_id'],
        'personal_access_tokens' => ['id', 'tokenable_id', 'token'],
        'api_keys' => ['id', 'account_id', 'key_hash'],
        'contacts' => ['id', 'account_id'],
        'invoices' => ['id', 'account_id'],
        'credit_ledger_entries' => ['id', 'account_id'],
        'whatsapp_sessions' => ['id', 'account_id'],
        'inbound_message_events' => ['id', 'account_id', 'event_key'],
    ],

    // Seeded data that must survive a restore (a restore that lost it is a failed restore).
    'required_roles' => ['super_admin', 'admin', 'agent'],
    'non_empty_tables' => ['permissions', 'capabilities', 'plans'],

    // child.column → parent.id pairs: a restore that skipped foreign-key checks can leave orphans.
    'reference_checks' => [
        ['users', 'account_id', 'accounts'],
        ['subscriptions', 'account_id', 'accounts'],
        ['account_entitlements', 'account_id', 'accounts'],
        ['account_entitlements', 'capability_id', 'capabilities'],
        ['credit_ledger_entries', 'account_id', 'accounts'],
        ['whatsapp_sessions', 'account_id', 'accounts'],
    ],

    // Columns stored with Laravel's `encrypted` cast (ciphertext under APP_KEY). The backup holds only ciphertext;
    // without the matching APP_KEY every one of them is unreadable. tests/Feature/RecoveryConfigurationTest fails if a
    // model gains an `encrypted` cast that is not listed here.
    'encrypted_columns' => [
        'payment_gateway_settings' => ['test_key_secret', 'test_webhook_secret', 'live_key_secret', 'live_webhook_secret'],
        'social_provider_configs' => ['client_id', 'client_secret', 'webhook_verify_token'],
        'webhook_subscriptions' => ['secret'],
        'mail_settings' => ['password'],
        'social_accounts' => ['access_token', 'refresh_token'],
        // meta_app_secret added alongside meta_access_token - Phase (2026-10-09)
        // per-tenant Meta app credentials migration (add_meta_app_credentials_to_whatsapp_sessions_table).
        'whatsapp_sessions' => ['meta_access_token', 'meta_app_secret'],
        // Baileys ('qr' engine) credentials. Losing APP_KEY means every WhatsApp
        // connection must be re-paired, so this belongs in the escrow runbook.
        'whatsapp_engine_auth_states' => ['value'],
        'accounts' => ['gemini_api_key'],
        // Phase 8 Task 12 — AiProviderSettings::api_key (EncryptedOrNull),
        // the DB override for a provider's API key (see AiManager::resolve()).
        'ai_provider_settings' => ['api_key'],
    ],

    // Rows sampled per encrypted column when checking decryptability.
    'decrypt_sample_size' => 20,

    // Deployment-level provider/service credentials read from the environment. Only a boolean "configured" and a
    // boolean "still the .env.example placeholder" are derived here (so it works under `config:cache`); the value
    // itself is never stored in this file's result, never reported, never logged. Each credential must be recoverable
    // from the operator's secret store - none of them is in a database backup.
    'env_credentials' => (function (): array {
        $cred = fn (string $name, string $purpose) => [
            'purpose' => $purpose,
            'configured' => (string) env($name, '') !== '',
            'placeholder' => str_starts_with((string) env($name, ''), 'devsecret_'),
        ];

        return [
            'DB_PASSWORD' => $cred('DB_PASSWORD', 'database login'),
            'INTERNAL_API_SECRET' => $cred('INTERNAL_API_SECRET', 'shared secret between this API and qr-engine-service'),
            'OPENAI_API_KEY' => $cred('OPENAI_API_KEY', 'platform OpenAI provider key'),
            'ANTHROPIC_API_KEY' => $cred('ANTHROPIC_API_KEY', 'platform Anthropic provider key'),
            'GEMINI_API_KEY' => $cred('GEMINI_API_KEY', 'platform Gemini provider key'),
            'MAIL_PASSWORD' => $cred('MAIL_PASSWORD', 'SMTP login'),
            'AWS_SECRET_ACCESS_KEY' => $cred('AWS_SECRET_ACCESS_KEY', 'object-storage / SES login'),
            'REDIS_PASSWORD' => $cred('REDIS_PASSWORD', 'cache/queue store login'),
        ];
    })(),
];
