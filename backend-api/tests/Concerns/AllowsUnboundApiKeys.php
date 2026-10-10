<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\ApiKey;
use App\Support\IpMatcher;

/**
 * Phase 4 Task 7 FIX — the `allow_legacy_unbound` config override this
 * trait used to flip on was REMOVED from ApiKeyBindingService::gate()
 * entirely (there must be no configuration flag that authenticates a key
 * with no live binding — see that method's own docblock and
 * InstallationBindingAuthenticationTest's regression tests proving the
 * flag is ignored). The config line below is kept but is genuinely inert
 * as far as gate() is concerned; it is no longer what makes this trait
 * work.
 *
 * FOLLOW-UP APPLIED: the ~20 suites that `use` this trait do so to get a
 * bare, factory-style ApiKey fixture past the binding gate for tests
 * about something else entirely (CRM, quota, idempotency, logging,
 * Meta/WhatsApp endpoints) — none of them set an account
 * authorized_server_ip or a legacy_binding_grace_expires_at themselves.
 * Rather than reintroducing a production bypass, this now registers an
 * `ApiKey::created` listener (scoped to this test's own, freshly booted
 * event dispatcher — never touching production code) that backfills,
 * on any key created AFTER this method runs:
 *   - the key's own legacy_binding_grace_expires_at (10 years out), and
 *   - its account's authorized_server_ip, to the normalized form of the
 *     Laravel test client's default REMOTE_ADDR (127.0.0.1),
 * only when those columns are still NULL — an explicit value a test set
 * itself is never overwritten. That is exactly the combination
 * gateUnboundLegacyKey() already requires to let a request through
 * (effectiveLegacyDeadline() in the future + ServerIpBindingService::
 * allows() matching the incoming IP), so it exercises the SAME
 * production legacy path every other grandfathered key uses instead of
 * a dead flag.
 *
 * Because this listener is registered in setUp (via Laravel's
 * `setUp<Trait>` convention, called before the test body runs), it never
 * affects a key that already existed beforehand — which is exactly what
 * InstallationBindingAuthenticationTest's
 * test_fix_legacy_override_flag_does_not_authenticate_an_unbound_key
 * relies on: it builds its key first and only then constructs a local
 * object that `use`s this trait and calls setUpAllowsUnboundApiKeys()
 * directly, so the listener registered there is too late to touch that
 * already-created key and the regression assertion (still
 * BINDING_REQUIRED) keeps holding.
 *
 * Server binding itself remains covered by ServerIpBindingTest and
 * InstallationBindingAuthenticationTest's own fixtures, which do not
 * `use` this trait for their binding-under-test cases.
 */
trait AllowsUnboundApiKeys
{
    protected function setUpAllowsUnboundApiKeys(): void
    {
        config(['api_binding.allow_legacy_unbound' => true]);

        ApiKey::created(function (ApiKey $key): void {
            if ($key->legacy_binding_grace_expires_at === null) {
                $key->forceFill(['legacy_binding_grace_expires_at' => now()->addYears(10)])->save();
            }

            $account = Account::query()->find($key->account_id);

            if ($account !== null && $account->authorized_server_ip === null) {
                $account->forceFill(['authorized_server_ip' => IpMatcher::normalize('127.0.0.1')])->save();
            }
        });
    }
}
