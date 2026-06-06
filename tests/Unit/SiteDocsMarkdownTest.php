<?php

namespace Tests\Unit;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class SiteDocsMarkdownTest extends TestCase
{
    public function test_site_docs_manifest_points_to_renderable_markdown_sections(): void
    {
        $docsPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'site';
        $manifestPath = $docsPath.DIRECTORY_SEPARATOR.'docs.json';

        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($manifest['sections'] ?? null);
        $this->assertNotEmpty($manifest['sections']);

        foreach ($manifest['sections'] as $section) {
            $slug = $section['slug'] ?? null;

            $this->assertIsString($slug);
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);

            $sectionPath = $docsPath.DIRECTORY_SEPARATOR.'sections'.DIRECTORY_SEPARATOR.$slug.'.md';

            $this->assertFileExists($sectionPath);

            $markdown = (string) file_get_contents($sectionPath);

            $this->assertGreaterThan(20, substr_count($markdown, "\n"), "{$slug}.md must keep real markdown line breaks.");
            $this->assertMatchesRegularExpression('/^###\s+\S/m', $markdown, "{$slug}.md should include section headings.");

            $html = Str::markdown($markdown, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);

            $this->assertStringContainsString('<h3>', $html, "{$slug}.md headings should render as HTML headings.");
            $this->assertStringNotContainsString('### ', $html, "{$slug}.md contains markdown headings that did not render.");
            $this->assertStringNotContainsString('```', $html, "{$slug}.md contains fenced code blocks that did not render.");
            $this->assertStringNotContainsString('<script', strtolower($html), "{$slug}.md must not render unsafe HTML.");
        }
    }
}
