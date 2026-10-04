<?php

namespace App\Support\Security;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Phase 12 Task 2 (H7) — runtime SSRF protection for URLs a tenant controls and our servers fetch.
 *
 * Validating a host NAME once is not enough: DNS can answer differently between the check and the connection
 * (rebinding), one name can carry several addresses, and a public URL can redirect to an internal one. So:
 *
 *   inspect()  parses the URL (http/https only, no credentials, no numeric-obfuscated hosts), resolves the host
 *              through the injected resolver and requires EVERY resulting address to be public — loopback,
 *              RFC1918, CGNAT, link-local (incl. the 169.254.169.254 / fd00:ec2::254 metadata services),
 *              multicast, reserved and documentation ranges, and IPv4 addresses embedded in IPv6 (mapped,
 *              NAT64, 6to4) are all refused.
 *   send()     performs the request ITSELF: it connects to the address that was validated (curl CURLOPT_RESOLVE
 *              pin, so a second DNS answer cannot redirect the connection), turns automatic redirects off and
 *              follows them manually, re-running inspect() on every hop.
 *
 * Only for customer-controlled URLs. Trusted application configuration (Graph API, payment gateways, the QR
 * engine's own base URL) is deliberately not routed through it.
 *
 * Residual: a URL that is only VALIDATED here and then fetched by another process (the QR engine's media_url)
 * cannot be pinned or redirect-checked from here — see the Phase 12 Task 2 report.
 */
class OutboundUrlGuard
{
    /** @var list<array{0: string, 1: int, 2: string}> [network, prefix length, label] */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8, 'this-network'],
        ['10.0.0.0', 8, 'private (RFC1918)'],
        ['100.64.0.0', 10, 'carrier-grade NAT / cloud metadata'],
        ['127.0.0.0', 8, 'loopback'],
        ['169.254.0.0', 16, 'link-local / cloud metadata'],
        ['172.16.0.0', 12, 'private (RFC1918)'],
        ['192.0.0.0', 24, 'IETF protocol assignments'],
        ['192.0.2.0', 24, 'documentation'],
        ['192.88.99.0', 24, '6to4 relay'],
        ['192.168.0.0', 16, 'private (RFC1918)'],
        ['198.18.0.0', 15, 'benchmarking'],
        ['198.51.100.0', 24, 'documentation'],
        ['203.0.113.0', 24, 'documentation'],
        ['224.0.0.0', 4, 'multicast'],
        ['240.0.0.0', 4, 'reserved'],
    ];

    /** @var list<array{0: string, 1: int, 2: string}> */
    private const BLOCKED_V6 = [
        ['::', 96, 'unspecified / loopback / IPv4-compatible'],
        ['100::', 64, 'discard-only'],
        ['2001::', 23, 'IETF protocol assignments / Teredo'],
        ['2001:db8::', 32, 'documentation'],
        ['3fff::', 20, 'documentation'],
        ['64:ff9b:1::', 48, 'local-use NAT64'],
        ['fc00::', 7, 'unique local (private) / cloud metadata'],
        ['fe80::', 10, 'link-local'],
        ['fec0::', 10, 'site-local (deprecated private)'],
        ['ff00::', 8, 'multicast'],
    ];

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(private readonly HostResolver $resolver)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('security.outbound.enabled', true);
    }

    /** @return string|null why the URL is refused, or null when it is acceptable */
    public function violation(string $url): ?string
    {
        try {
            $this->inspect($url);

            return null;
        } catch (UnsafeOutboundUrlException $e) {
            return $e->getMessage();
        }
    }

    public function isSafe(string $url): bool
    {
        return $this->violation($url) === null;
    }

    /** @throws UnsafeOutboundUrlException */
    public function assertSafe(string $url): void
    {
        $this->inspect($url);
    }

    /**
     * @return array{url: string, scheme: string, host: string, port: int, ips: list<string>, pin: bool}
     *
     * @throws UnsafeOutboundUrlException
     */
    public function inspect(string $url): array
    {
        $url = trim($url);
        $parts = $url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) ? false : parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeOutboundUrlException('The URL is malformed.');
        }

        $scheme = strtolower($parts['scheme']);
        $allowed = config('security.outbound.allow_http', true) ? ['http', 'https'] : ['https'];

        if (! in_array($scheme, $allowed, true)) {
            throw new UnsafeOutboundUrlException('Only '.implode(' / ', array_map(fn ($s) => $s.'://', $allowed)).' URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeOutboundUrlException('URLs with embedded credentials are not allowed.');
        }

        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if ($host === '') {
            throw new UnsafeOutboundUrlException('The URL is malformed.');
        }

        $result = ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => [], 'pin' => false];

        if (! $this->enabled() || in_array($host, (array) config('security.outbound.allowed_hosts', []), true)) {
            return $result;
        }

        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if (! $isLiteral) {
            if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
                throw new UnsafeOutboundUrlException('The URL points to a non-public address (loopback).');
            }

            if ($this->looksNumeric($host)) {
                throw new UnsafeOutboundUrlException('The URL host is not a valid public host name.');
            }
        }

        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw new UnsafeOutboundUrlException('The URL host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if ($reason = $this->blockedReason($ip)) {
                throw new UnsafeOutboundUrlException("The URL points to a non-public address ({$reason}).");
            }
        }

        $result['ips'] = array_values($ips);
        $result['pin'] = ! $isLiteral;

        return $result;
    }

    /** Why this single IP address must not be contacted, or null when it is a public address. */
    public function blockedReason(string $ip): ?string
    {
        $bin = @inet_pton($ip);

        if ($bin === false) {
            return 'invalid address';
        }

        if (strlen($bin) === 4) {
            return $this->matchV4($bin);
        }

        // IPv4 carried inside IPv6 is judged as that IPv4 address.
        $embedded = $this->embeddedV4($bin);
        if ($embedded !== null) {
            return $this->matchV4($embedded) ?? null;
        }

        foreach (self::BLOCKED_V6 as [$net, $prefix, $label]) {
            if ($this->inCidr($bin, inet_pton($net), $prefix)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * An HTTP request that cannot be steered to an internal address: connects to the validated address, never
     * follows a redirect without re-validating it.
     *
     * @param  Closure(string): PendingRequest  $build  returns a fresh PendingRequest for the given method (a redirect
     *                                                  may turn POST into GET, so the caller decides whether a body is attached)
     *
     * @throws UnsafeOutboundUrlException when the URL or any redirect hop is refused
     */
    public function send(string $method, string $url, Closure $build, ?int $maxRedirects = null): Response
    {
        $maxRedirects ??= (int) config('security.outbound.max_redirects', 3);
        $method = strtoupper($method);
        $hops = 0;

        while (true) {
            $target = $this->inspect($url);

            $response = $build($method)->withOptions($this->connectionOptions($target))->send($method, $url);

            $status = $response->status();
            $location = $response->header('Location');

            if (! in_array($status, self::REDIRECT_STATUSES, true) || $location === '' || $location === null) {
                return $response;
            }

            if (++$hops > $maxRedirects) {
                throw new UnsafeOutboundUrlException('Too many redirects.');
            }

            $url = $this->absolute($url, $location);

            if ($status === 303 || (in_array($status, [301, 302], true) && $method === 'POST')) {
                $method = 'GET';
            }
        }
    }

    /**
     * Guzzle options for one validated hop: redirects are never followed by the client, and the connection is pinned
     * (curl CURLOPT_RESOLVE) to the address that was just validated, so DNS cannot answer differently at connect time.
     *
     * @param  array{host: string, port: int, ips: list<string>, pin: bool}  $target
     * @return array<string, mixed>
     */
    public function connectionOptions(array $target): array
    {
        $options = ['allow_redirects' => false];

        if ($target['pin'] && defined('CURLOPT_RESOLVE') && $target['ips'] !== []) {
            $ip = $target['ips'][0];
            $ip = str_contains($ip, ':') ? "[{$ip}]" : $ip;
            $options['curl'] = [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]];
        }

        return $options;
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }

        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'http').'://'.($b['host'] ?? '').(isset($b['port']) ? ':'.$b['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($b['scheme'] ?? 'http').':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $dir = rtrim(dirname($b['path'] ?? '/'), '/');

        return $origin.$dir.'/'.$location;
    }

    /** "2130706433", "0x7f.1", "017700000001" — forms some stacks read as an IPv4 address. */
    private function looksNumeric(string $host): bool
    {
        foreach (explode('.', $host) as $label) {
            if (! preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', $label)) {
                return false;
            }
        }

        return true;
    }

    private function matchV4(string $bin): ?string
    {
        foreach (self::BLOCKED_V4 as [$net, $prefix, $label]) {
            if ($this->inCidr($bin, inet_pton($net), $prefix)) {
                return $label;
            }
        }

        if ($bin === "\xff\xff\xff\xff") {
            return 'broadcast';
        }

        return null;
    }

    /** IPv4 inside ::ffff:0:0/96 (mapped), 64:ff9b::/96 (NAT64) or 2002::/16 (6to4), else null. */
    private function embeddedV4(string $bin): ?string
    {
        if ($this->inCidr($bin, inet_pton('::ffff:0:0'), 96) || $this->inCidr($bin, inet_pton('64:ff9b::'), 96)) {
            return substr($bin, 12, 4);
        }

        if ($this->inCidr($bin, inet_pton('2002::'), 16)) {
            return substr($bin, 2, 4);
        }

        return null;
    }

    private function inCidr(string $bin, string $net, int $prefix): bool
    {
        if (strlen($bin) !== strlen($net)) {
            return false;
        }

        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;

        if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
