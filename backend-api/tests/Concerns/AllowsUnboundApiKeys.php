<?php

namespace Tests\Concerns;

/**
 * For suites that exercise Public API contracts (auth, quota, idempotency, logging, CRM/WhatsApp/Meta endpoints)
 * with a bare, factory-style ApiKey and have nothing to do with server binding.
 *
 * The application default is that a key with no binding is refused (API_SERVER_BINDING_REQUIRED, see
 * config/api_binding.php). These suites opt in to the temporary legacy-unbound override so their key fixtures stay
 * minimal. Server binding itself - including the production default - is covered by ApiKeyServerBindingTest and
 * LegacyApiKeyEnrollmentTest, which must NOT use this trait.
 */
trait AllowsUnboundApiKeys
{
    protected function setUpAllowsUnboundApiKeys(): void
    {
        config(['api_binding.allow_legacy_unbound' => true]);
    }
}
