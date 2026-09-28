<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * /docs/api renders Scramble's Stoplight Elements viewer, which loads from
 * unpkg and runs inline scripts. The CSP allows exactly that pinned package
 * on the docs page only, and the inline scripts carry the request nonce.
 */
class ApiDocsCspTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private const ELEMENTS = 'https://unpkg.com/@stoplight/elements@8.4.2/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        config(['scramble.public_docs' => true]);
    }

    private function csp(string $uri): string
    {
        return (string) $this->get($uri)->headers->get('Content-Security-Policy');
    }

    public function test_the_docs_page_allows_the_pinned_viewer_and_nonces_its_scripts(): void
    {
        $response = $this->get('/docs/api')->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("#script-src 'self' 'nonce-([^']+)' ".preg_quote(self::ELEMENTS, '#').';#', $csp);
        $this->assertStringContainsString('style-src '."'self' 'unsafe-inline' https://fonts.bunny.net ".self::ELEMENTS, $csp);

        preg_match("#'nonce-([^']+)'#", $csp, $m);
        $html = $response->getContent();
        // Every script tag on the page carries the nonce.
        $this->assertSame(
            substr_count($html, '<script'),
            substr_count($html, 'nonce="'.$m[1].'"'),
        );
        $this->assertStringContainsString(self::ELEMENTS.'web-components.min.js', $html);
    }

    public function test_other_pages_do_not_allow_the_viewer_host(): void
    {
        $this->assertStringNotContainsString('unpkg.com', $this->csp('/login'));
    }
}
