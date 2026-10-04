<?php

namespace Tests\Feature;

use App\Support\Security\HostResolver;
use App\Support\Security\OutboundUrlGuard;
use App\Support\Security\UnsafeOutboundUrlException;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

/**
 * Phase 12 Task 2 (H7) — OutboundUrlGuard: blocked address ranges (IPv4 + IPv6), URL parsing, DNS resolution and
 * rebinding, connection pinning and redirect re-validation. No real DNS and no real network: a FakeHostResolver
 * answers every lookup and Http::fake answers every request.
 */
class OutboundUrlGuardTest extends TestCase
{
    private FakeHostResolver $dns;

    private OutboundUrlGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dns = new FakeHostResolver();
        $this->app->instance(HostResolver::class, $this->dns);
        $this->guard = $this->app->make(OutboundUrlGuard::class);
        config(['security.outbound' => ['enabled' => true, 'allow_http' => true, 'allowed_hosts' => [], 'max_redirects' => 3]]);
    }

    // ------------------------------------------------------------ blocked ranges

    /** @return array<string, array{0: string}> */
    public static function blockedUrls(): array
    {
        return [
            'ipv4 loopback' => ['http://127.0.0.1/hook'],
            'ipv4 loopback range' => ['http://127.5.6.7/hook'],
            'ipv4 loopback with port' => ['https://127.0.0.1:8443/hook'],
            'ipv6 loopback' => ['http://[::1]/hook'],
            'ipv6 unspecified' => ['http://[::]/hook'],
            'ipv4 unspecified' => ['http://0.0.0.0/hook'],
            '10/8' => ['http://10.0.0.5/hook'],
            '10/8 upper edge' => ['http://10.255.255.255/hook'],
            '172.16/12 lower edge' => ['http://172.16.0.1/hook'],
            '172.16/12 upper edge' => ['http://172.31.255.254/hook'],
            '192.168/16' => ['http://192.168.1.1/hook'],
            'aws/gcp/azure metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'link-local' => ['http://169.254.10.10/hook'],
            'carrier-grade nat / alibaba metadata' => ['http://100.100.100.200/latest/meta-data/'],
            'ipv6 unique local' => ['http://[fd00::1]/hook'],
            'ipv6 aws metadata' => ['http://[fd00:ec2::254]/latest/meta-data/'],
            'ipv6 link-local' => ['http://[fe80::1]/hook'],
            'ipv6 multicast' => ['http://[ff02::1]/hook'],
            'ipv6 documentation' => ['http://[2001:db8::1]/hook'],
            'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/hook'],
            'ipv4-mapped metadata' => ['http://[::ffff:169.254.169.254]/hook'],
            'ipv4-mapped private' => ['http://[::ffff:10.1.2.3]/hook'],
            'nat64 private' => ['http://[64:ff9b::a00:1]/hook'],
            '6to4 private' => ['http://[2002:c0a8:101::1]/hook'],
            'ipv4 multicast' => ['http://224.0.0.1/hook'],
            'ipv4 reserved' => ['http://240.0.0.1/hook'],
            'ipv4 broadcast' => ['http://255.255.255.255/hook'],
            'documentation range' => ['http://192.0.2.10/hook'],
            'localhost' => ['http://localhost/hook'],
            'localhost subdomain' => ['http://api.localhost/hook'],
            'localhost with trailing dot' => ['http://localhost./hook'],
            'decimal ip' => ['http://2130706433/hook'],
            'hex ip' => ['http://0x7f000001/hook'],
            'hex dotted ip' => ['http://0x7f.0.0.1/hook'],
            'octal ip' => ['http://017700000001/hook'],
            'short ip' => ['http://127.1/hook'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedUrls')]
    public function test_non_public_destinations_are_refused(string $url): void
    {
        $this->assertFalse($this->guard->isSafe($url), $url);
        $this->assertNotNull($this->guard->violation($url));
        $this->expectException(UnsafeOutboundUrlException::class);
        $this->guard->assertSafe($url);
    }

    /** @return array<string, array{0: string}> */
    public static function publicUrls(): array
    {
        return [
            'https host' => ['https://hooks.example.com/path?x=1'],
            'http host' => ['http://hooks.example.com/'],
            'host with port' => ['https://hooks.example.com:8443/in'],
            'public ipv4 literal' => ['https://93.184.216.34/hook'],
            'just outside 172.16/12' => ['http://172.32.0.1/hook'],
            'just outside 10/8' => ['http://11.0.0.1/hook'],
            'public ipv6' => ['https://[2606:2800:220:1:248:1893:25c8:1946]/hook'],
            'ipv4-mapped public' => ['https://[::ffff:93.184.216.34]/hook'],
            'host that merely looks hex' => ['https://bad.dad.example.com/hook'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publicUrls')]
    public function test_valid_public_urls_are_preserved(string $url): void
    {
        $this->assertNull($this->guard->violation($url), $url);
        $this->assertTrue($this->guard->isSafe($url));
    }

    // ------------------------------------------------------------ parsing

    /** @return array<string, array{0: string}> */
    public static function malformedUrls(): array
    {
        return [
            'empty' => [''],
            'not a url' => ['not a url'],
            'no scheme' => ['example.com/hook'],
            'scheme only' => ['https://'],
            'no host' => ['http:///path'],
            'whitespace in host' => ['http://exa mple.com/'],
            'control character' => ["http://example.com/\r\nHost: evil"],
            'embedded credentials' => ['https://user:pass@example.com/'],
            'userinfo trick to internal' => ['http://example.com@127.0.0.1/'],
            'too long' => ['https://example.com/'.str_repeat('a', 2100)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedUrls')]
    public function test_malformed_urls_are_refused(string $url): void
    {
        $this->assertFalse($this->guard->isSafe($url));
    }

    /** @return array<string, array{0: string}> */
    public static function unsupportedSchemes(): array
    {
        return [
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://example.com/_x'],
            'ftp' => ['ftp://example.com/x'],
            'dict' => ['dict://example.com:11211/stat'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/plain,hello'],
            'ldap' => ['ldap://example.com/'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedSchemes')]
    public function test_unsupported_schemes_are_refused(string $url): void
    {
        $this->assertFalse($this->guard->isSafe($url));
    }

    public function test_http_can_be_switched_off_leaving_https_only(): void
    {
        config(['security.outbound.allow_http' => false]);

        $this->assertFalse($this->guard->isSafe('http://hooks.example.com/'));
        $this->assertTrue($this->guard->isSafe('https://hooks.example.com/'));
    }

    // ------------------------------------------------------------ DNS

    public function test_a_host_name_that_resolves_to_a_private_address_is_refused(): void
    {
        $this->dns->map['internal.example.com'] = ['10.1.2.3'];
        $this->dns->map['meta.example.com'] = ['169.254.169.254'];
        $this->dns->map['v6.example.com'] = ['fd00::5'];

        foreach (['internal', 'meta', 'v6'] as $name) {
            $this->assertFalse($this->guard->isSafe("https://{$name}.example.com/"), $name);
        }
    }

    public function test_every_resolved_address_must_be_public_not_just_the_first(): void
    {
        $this->dns->map['mixed.example.com'] = ['93.184.216.34', '127.0.0.1'];

        $this->assertFalse($this->guard->isSafe('https://mixed.example.com/'));
    }

    public function test_an_unresolvable_host_fails_closed(): void
    {
        $this->dns->map['gone.example.com'] = [];

        $this->assertStringContainsString('could not be resolved', (string) $this->guard->violation('https://gone.example.com/'));
    }

    public function test_dns_rebinding_between_two_lookups_is_caught_because_every_use_re_resolves(): void
    {
        // first answer public (the registration-time check), second answer private (the later delivery)
        $this->dns->sequence['rebind.example.com'] = [['93.184.216.34'], ['127.0.0.1']];

        $this->assertTrue($this->guard->isSafe('https://rebind.example.com/hook'));
        $this->assertFalse($this->guard->isSafe('https://rebind.example.com/hook'));
    }

    public function test_the_connection_is_pinned_to_the_validated_address_so_a_later_dns_answer_cannot_redirect_it(): void
    {
        $target = $this->guard->inspect('https://hooks.example.com:8443/x');
        $options = $this->guard->connectionOptions($target);

        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(['hooks.example.com:8443:'.FakeHostResolver::PUBLIC_IP], $options['curl'][CURLOPT_RESOLVE]);

        $v6 = $this->guard->connectionOptions(['host' => 'h.example.com', 'port' => 443, 'ips' => ['2606:2800::1'], 'pin' => true]);
        $this->assertSame(['h.example.com:443:[2606:2800::1]'], $v6['curl'][CURLOPT_RESOLVE]);

        // an IP literal needs no pin
        $literal = $this->guard->connectionOptions($this->guard->inspect('https://93.184.216.34/x'));
        $this->assertArrayNotHasKey('curl', $literal);
    }

    // ------------------------------------------------------------ send() and redirects

    public function test_a_public_url_is_requested_normally(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(['ok' => true], 200)]);

        $response = $this->guard->send('POST', 'https://hooks.example.com/in', fn (string $m) => Http::timeout(5)->withBody('{"a":1}', 'application/json'));

        $this->assertSame(200, $response->status());
        Http::assertSentCount(1);
    }

    public function test_a_public_url_redirecting_to_a_private_address_is_blocked_before_it_is_followed(): void
    {
        Http::fake([
            'hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '169.254.169.254/*' => Http::response('secret', 200),
        ]);

        try {
            $this->guard->send('GET', 'https://hooks.example.com/in', fn () => Http::timeout(5));
            $this->fail('the redirect into the metadata address must be refused');
        } catch (UnsafeOutboundUrlException) {
            // expected
        }

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_a_redirect_to_a_host_that_resolves_privately_is_blocked(): void
    {
        $this->dns->map['inside.example.com'] = ['192.168.0.9'];
        Http::fake(['hooks.example.com/*' => Http::response('', 301, ['Location' => 'https://inside.example.com/admin'])]);

        $this->expectException(UnsafeOutboundUrlException::class);
        $this->guard->send('GET', 'https://hooks.example.com/in', fn () => Http::timeout(5));
    }

    public function test_relative_scheme_relative_and_absolute_redirects_are_each_re_validated(): void
    {
        $this->dns->map['b.example.com'] = ['127.0.0.1'];
        Http::fake([
            'hooks.example.com/start' => Http::response('', 302, ['Location' => '/next']),
            'hooks.example.com/next' => Http::response('', 302, ['Location' => '//b.example.com/x']),
        ]);

        try {
            $this->guard->send('GET', 'https://hooks.example.com/start', fn () => Http::timeout(5));
            $this->fail('the scheme-relative redirect to a private host must be refused');
        } catch (UnsafeOutboundUrlException) {
            // expected
        }

        Http::assertSentCount(2);
    }

    public function test_a_public_to_public_redirect_is_followed(): void
    {
        Http::fake([
            'hooks.example.com/*' => Http::response('', 308, ['Location' => 'https://other.example.org/final']),
            'other.example.org/*' => Http::response(['done' => true], 200),
        ]);

        $response = $this->guard->send('POST', 'https://hooks.example.com/in', fn (string $m) => Http::timeout(5)->withBody('{}', 'application/json'));

        $this->assertSame(200, $response->status());
        Http::assertSentCount(2);
    }

    public function test_a_post_redirected_with_302_becomes_a_body_less_get_while_307_keeps_the_method(): void
    {
        Http::fake([
            'hooks.example.com/a' => Http::response('', 302, ['Location' => 'https://hooks.example.com/b']),
            'hooks.example.com/b' => Http::response('', 200),
            'hooks.example.com/c' => Http::response('', 307, ['Location' => 'https://hooks.example.com/d']),
            'hooks.example.com/d' => Http::response('', 200),
        ]);
        $build = fn (string $m) => $m === 'GET' ? Http::timeout(5) : Http::timeout(5)->withBody('{"x":1}', 'application/json');

        $this->guard->send('POST', 'https://hooks.example.com/a', $build);
        $this->guard->send('POST', 'https://hooks.example.com/c', $build);

        $methods = [];
        Http::assertSent(function ($request) use (&$methods) {
            $methods[$request->url()] = $request->method();

            return true;
        });
        $this->assertSame('GET', $methods['https://hooks.example.com/b']);
        $this->assertSame('POST', $methods['https://hooks.example.com/d']);
    }

    public function test_a_redirect_loop_is_cut_off(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 302, ['Location' => 'https://hooks.example.com/again'])]);

        try {
            $this->guard->send('GET', 'https://hooks.example.com/in', fn () => Http::timeout(5));
            $this->fail('too many redirects');
        } catch (UnsafeOutboundUrlException $e) {
            $this->assertStringContainsString('redirects', $e->getMessage());
        }

        Http::assertSentCount(4); // the first request + three followed hops
    }

    public function test_a_blocked_url_never_makes_a_request(): void
    {
        Http::fake();

        try {
            $this->guard->send('GET', 'http://10.0.0.1/', fn () => Http::timeout(5));
        } catch (UnsafeOutboundUrlException) {
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ switches

    public function test_an_explicitly_allowed_host_bypasses_the_address_checks(): void
    {
        config(['security.outbound.allowed_hosts' => ['hooks.internal.example']]);
        $this->dns->map['hooks.internal.example'] = ['10.9.9.9'];
        $this->dns->map['other.internal.example'] = ['10.9.9.10'];

        $this->assertTrue($this->guard->isSafe('https://hooks.internal.example/in'));
        $this->assertFalse($this->guard->isSafe('https://other.internal.example/in'));
    }

    public function test_the_guard_can_be_switched_off_for_local_development(): void
    {
        config(['security.outbound.enabled' => false]);

        $this->assertTrue($this->guard->isSafe('http://127.0.0.1:9000/hook'));
        // a malformed URL or unsupported scheme is still refused
        $this->assertFalse($this->guard->isSafe('file:///etc/passwd'));
    }
}
