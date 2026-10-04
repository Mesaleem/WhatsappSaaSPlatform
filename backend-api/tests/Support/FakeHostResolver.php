<?php

namespace Tests\Support;

use App\Support\Security\HostResolver;

/**
 * A controllable resolver for tests — no DNS, no network. IP literals resolve to themselves; a name resolves to the
 * answers queued for it (a list per call, so a test can make the SECOND lookup differ — DNS rebinding), else to
 * the mapped addresses, else to a public documentation-independent default.
 */
class FakeHostResolver implements HostResolver
{
    public const PUBLIC_IP = '93.184.216.34';

    /** @var array<string, list<string>> */
    public array $map = [];

    /** @var array<string, list<list<string>>> */
    public array $sequence = [];

    /** @var list<string> */
    public array $lookups = [];

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (! empty($this->sequence[$host])) {
            return array_shift($this->sequence[$host]);
        }

        return $this->map[$host] ?? [self::PUBLIC_IP];
    }
}
