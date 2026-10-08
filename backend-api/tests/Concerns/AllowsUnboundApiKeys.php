<?php

namespace Tests\Concerns;

/**
 * Phase 4 Task 7 FIX — INERT. The `allow_legacy_unbound` override this
 * trait used to flip on has been REMOVED from
 * ApiKeyBindingService::gate() entirely: there must be no configuration
 * flag that authenticates a key with no live binding. Setting the config
 * key below no longer has any effect on gate() — see that method's own
 * docblock and InstallationBindingAuthenticationTest's explicit
 * regression test proving the flag is ignored.
 *
 * CONSEQUENCE, DISCLOSED, NOT YET FIXED: every suite below that applies
 * this trait does so to get a bare, unbound ApiKey fixture past the
 * binding gate for tests about something else entirely (CRM, quota,
 * idempotency, logging, Meta/WhatsApp endpoints) — none of them set an
 * account authorized_server_ip or a legacy_binding_grace_expires_at.
 * With the override gone, every /api/v1/* call those fixtures make now
 * gets INSTALLATION_BINDING_REQUIRED instead of reaching the behavior
 * under test. This was flagged, not silently repaired, in the Task 7 Fix
 * report — migrating ~20 files to a real bound-key fixture (provision()
 * + sending the installation credential header) is a separate, sizeable
 * follow-up, not a one-line change this trait can make on their behalf.
 *
 * Originally: suites exercising Public API contracts with a bare,
 * factory-style ApiKey that have nothing to do with server binding opted
 * in to this override so their key fixtures could stay minimal. Server
 * binding itself remains covered by ServerIpBindingTest and
 * InstallationBindingAuthenticationTest, which must NOT use this trait.
 */
trait AllowsUnboundApiKeys
{
    /**
     * No-op as far as gate() is concerned (see class docblock) — kept
     * only so every file still calling this method does not hard-error;
     * it sets a config key nothing reads anymore.
     */
    protected function setUpAllowsUnboundApiKeys(): void
    {
        config(['api_binding.allow_legacy_unbound' => true]);
    }
}
