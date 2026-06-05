<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');
    }

    public function test_csp_present_with_nonce_and_no_xss_protection(): void
    {
        $response = $this->get('/login');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'CSP header should be present');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("'nonce-", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString('https://fonts.bunny.net', $csp);
        $this->assertFalse($response->headers->has('X-XSS-Protection'));
        $this->assertStringContainsString('camera=(self)', $response->headers->get('Permissions-Policy'));
    }
}
