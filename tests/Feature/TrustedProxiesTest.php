<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Behind a reverse proxy that terminates TLS (nginx, Traefik, Caddy, a load
 * balancer), the app only sees plain http from the proxy. Unless the proxy is
 * trusted, Laravel builds http:// URLs for the page's scripts and styles, the
 * browser blocks them as mixed content on the https page, and only the static
 * markup renders. TRUSTED_PROXIES (config trustedproxy.proxies) names the
 * proxies whose X-Forwarded-* headers are believed; nobody else's are.
 */
final class TrustedProxiesTest extends TestCase
{
    private const FORWARDED = [
        'X-Forwarded-For' => '81.2.69.160',
        'X-Forwarded-Host' => 'inventory.example.com',
        'X-Forwarded-Port' => '443',
        'X-Forwarded-Proto' => 'https',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__proxy-probe', fn () => response()->json([
            'url' => url('/build/app.js'),
            'secure' => request()->isSecure(),
            'ip' => request()->ip(),
        ]));
    }

    private function probe(string $remoteAddr): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->withHeaders(self::FORWARDED)
            ->getJson('/__proxy-probe')
            ->assertOk()
            ->json();
    }

    public function test_forwarded_headers_from_a_trusted_proxy_are_honoured(): void
    {
        config(['trustedproxy.proxies' => '172.16.0.0/12']);

        $this->assertSame([
            'url' => 'https://inventory.example.com/build/app.js',
            'secure' => true,
            'ip' => '81.2.69.160',
        ], $this->probe('172.18.0.5'));
    }

    public function test_private_ranges_keyword_trusts_a_proxy_on_a_private_network(): void
    {
        config(['trustedproxy.proxies' => 'private_ranges']);

        $this->assertSame('https://inventory.example.com/build/app.js', $this->probe('10.1.2.3')['url']);
    }

    public function test_a_comma_separated_list_is_accepted(): void
    {
        config(['trustedproxy.proxies' => '192.0.2.10, 192.0.2.11']);

        $this->assertTrue($this->probe('192.0.2.11')['secure']);
    }

    public function test_forwarded_headers_from_an_untrusted_address_are_ignored(): void
    {
        config(['trustedproxy.proxies' => 'private_ranges']);

        $result = $this->probe('8.8.8.8');

        $this->assertFalse($result['secure']);
        $this->assertSame('8.8.8.8', $result['ip']);
        $this->assertStringStartsWith('http://localhost', $result['url']);
    }

    public function test_no_proxy_is_trusted_unless_configured(): void
    {
        // TRUSTED_PROXIES is not set for the test suite: the shipped default.
        $this->assertNull(config('trustedproxy.proxies'));

        $result = $this->probe('172.18.0.5');

        $this->assertFalse($result['secure']);
        $this->assertSame('172.18.0.5', $result['ip']);
    }

    public function test_the_forwarded_prefix_header_is_not_trusted(): void
    {
        config(['trustedproxy.proxies' => 'private_ranges']);

        $url = $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders(self::FORWARDED + ['X-Forwarded-Prefix' => '/evil'])
            ->getJson('/__proxy-probe')
            ->json('url');

        $this->assertSame('https://inventory.example.com/build/app.js', $url);
    }

    /**
     * docker-compose.prod.yml leaves SESSION_SECURE_COOKIE unset ("null"), so
     * the session cookie is Secure exactly when the request is https. Forcing
     * it on made every form answer 419 when the app was opened over plain
     * http, because the browser never sends a Secure cookie back.
     */
    public function test_an_unset_secure_cookie_setting_marks_the_cookie_secure_behind_an_https_proxy(): void
    {
        config(['trustedproxy.proxies' => 'private_ranges', 'session.secure' => null]);
        Route::middleware('web')->get('/__session-probe', fn () => 'ok');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders(self::FORWARDED)
            ->get('/__session-probe');

        $this->assertTrue($this->sessionCookie($response)->isSecure());
    }

    public function test_an_unset_secure_cookie_setting_leaves_the_cookie_usable_over_plain_http(): void
    {
        config(['trustedproxy.proxies' => 'private_ranges', 'session.secure' => null]);
        Route::middleware('web')->get('/__session-probe', fn () => 'ok');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])->get('/__session-probe');

        $this->assertFalse($this->sessionCookie($response)->isSecure());
    }

    private function sessionCookie(TestResponse $response): Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                return $cookie;
            }
        }

        $this->fail('No session cookie was set.');
    }

    public function test_the_setting_is_read_from_the_trusted_proxies_environment_variable(): void
    {
        $this->assertStringContainsString(
            "env('TRUSTED_PROXIES')",
            file_get_contents(config_path('trustedproxy.php')),
        );
    }
}
