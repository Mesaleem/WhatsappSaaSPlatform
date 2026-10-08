<?php

namespace Tests\Unit\Services\ApiAccess;

use App\Services\ApiAccess\InstallationAllowanceResolver;
use App\Services\Access\AccessControlService;
use App\Services\Access\PlanEntitlementReconciliationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4 Task 2 — the pure installation-allowance rule
 * (resolveFromUsageLimit), evaluated in isolation: no framework, no
 * database. The account-level convenience method (resolveForAccount())
 * is NOT covered here — it reads AccessControlService/
 * PlanEntitlementReconciliationService/Plan from the database and
 * belongs in a Feature test instead (see
 * tests/Feature/Access/PlanManagementApiInstallationsTest.php).
 */
class InstallationAllowanceResolverTest extends TestCase
{
    private InstallationAllowanceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        // The constructor dependencies are never called by
        // resolveFromUsageLimit() itself — only by resolveForAccount(),
        // which this test does not exercise — so plain, uninstantiated
        // mocks are enough; no behavior needs to be stubbed on them.
        $this->resolver = new InstallationAllowanceResolver(
            $this->createStub(AccessControlService::class),
            $this->createStub(PlanEntitlementReconciliationService::class),
        );
    }

    /** Requirement 1 — capability absent -> default allowance 1, no warning. */
    public function test_absent_capability_resolves_to_default_allowance(): void
    {
        $result = $this->resolver->resolveFromUsageLimit(false, null);

        $this->assertSame(1, $result['allowance']);
        $this->assertSame(InstallationAllowanceResolver::SOURCE_CAPABILITY_ABSENT, $result['source']);
        $this->assertNull($result['warning']);
    }

    /**
     * Requirement 1 — a usage_limit value is irrelevant when the
     * capability itself is absent; a caller should never pass one in
     * that case, but if it did, presence (not the limit) must still
     * decide the branch.
     */
    public function test_absent_capability_resolves_to_default_allowance_even_if_a_limit_was_somehow_passed(): void
    {
        $result = $this->resolver->resolveFromUsageLimit(false, 5);

        $this->assertSame(1, $result['allowance']);
        $this->assertSame(InstallationAllowanceResolver::SOURCE_CAPABILITY_ABSENT, $result['source']);
    }

    /** Requirement 2 — capability present, NULL usage_limit -> default allowance 1, WITH a warning. */
    public function test_null_usage_limit_resolves_to_default_allowance_with_a_warning(): void
    {
        $result = $this->resolver->resolveFromUsageLimit(true, null);

        $this->assertSame(1, $result['allowance']);
        $this->assertSame(InstallationAllowanceResolver::SOURCE_NULL_LIMIT, $result['source']);
        $this->assertNotNull($result['warning']);
        $this->assertStringContainsString('api_installations', $result['warning']);
    }

    /** Requirement 3 — usage_limit = 0 -> explicit deny (allowance 0), no warning: this is a deliberate, valid configuration, not a misconfiguration. */
    public function test_zero_usage_limit_resolves_to_explicit_deny(): void
    {
        $result = $this->resolver->resolveFromUsageLimit(true, 0);

        $this->assertSame(0, $result['allowance']);
        $this->assertSame(InstallationAllowanceResolver::SOURCE_EXPLICIT_LIMIT, $result['source']);
        $this->assertNull($result['warning']);
    }

    /** Requirement 4 — a positive usage_limit resolves to exactly that allowance. */
    #[DataProvider('positiveLimits')]
    public function test_positive_usage_limit_resolves_exactly(int $limit): void
    {
        $result = $this->resolver->resolveFromUsageLimit(true, $limit);

        $this->assertSame($limit, $result['allowance']);
        $this->assertSame(InstallationAllowanceResolver::SOURCE_EXPLICIT_LIMIT, $result['source']);
        $this->assertNull($result['warning']);
    }

    /** @return array<string, array{0: int}> */
    public static function positiveLimits(): array
    {
        return [
            'one' => [1],
            'small tier' => [3],
            'large tier' => [10],
            'very large' => [1_000_000],
        ];
    }

    /**
     * Requirement 5 — a negative persisted value resolves defensively to
     * 0, with a warning. Unreachable through this codebase's own write
     * paths today (plan_entitlements.usage_limit is an UNSIGNED INT
     * column), but the rule itself must still hold for any other source
     * of a negative integer reaching this method.
     */
    #[DataProvider('negativeLimits')]
    public function test_negative_usage_limit_resolves_defensively_to_zero(int $limit): void
    {
        $result = $this->resolver->resolveFromUsageLimit(true, $limit);

        $this->assertSame(0, $result['allowance']);
        $this->assertSame(InstallationAllowanceResolver::SOURCE_NEGATIVE_LIMIT, $result['source']);
        $this->assertNotNull($result['warning']);
    }

    /** @return array<string, array{0: int}> */
    public static function negativeLimits(): array
    {
        return [
            'minus one' => [-1],
            'large negative' => [-1000],
        ];
    }

    /** No unlimited sentinel exists: no input value of any kind produces PHP_INT_MAX, -1-as-unlimited, or any other "no cap" marker. */
    public function test_there_is_no_unlimited_sentinel(): void
    {
        foreach ([null, 0, 1, 2, 100, 1_000_000] as $limit) {
            $result = $this->resolver->resolveFromUsageLimit(true, $limit);
            $this->assertIsInt($result['allowance']);
            $this->assertLessThan(PHP_INT_MAX, $result['allowance']);
        }
    }
}
