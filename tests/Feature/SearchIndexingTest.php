<?php

namespace Tests\Feature;

use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchIndexingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_redirects_errors_and_api_responses_do_not_allow_indexing(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        foreach (['/login', '/dashboard', '/not-a-real-page', '/portal/missing/login', '/api/v1/products'] as $uri) {
            $this->get($uri)->assertHeader('X-Robots-Tag', 'noindex, nofollow, nosnippet');
        }
        $this->get('/login')->assertSee('<meta name="robots" content="noindex, nofollow, nosnippet">', escape: false);
        $this->assertStringContainsString('Disallow: /', file_get_contents(public_path('robots.txt')));
    }

    public function test_installer_redirects_also_have_the_directive(): void
    {
        $this->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow, nosnippet');
    }
}
