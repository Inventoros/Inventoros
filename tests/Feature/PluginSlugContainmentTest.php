<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PluginSlugContainmentTest extends TestCase
{
    use RefreshDatabase;

    public static function maliciousSlugs(): array
    {
        return [
            'parent traversal'      => ['../storage'],
            'double dots only'      => ['..'],
            'embedded traversal'    => ['foo/../bar'],
            'backslash'             => ['foo\\bar'],
            'absolute-ish'          => ['/etc'],
            'url-encoded traversal' => ['..%2fstorage'],
        ];
    }

    #[DataProvider('maliciousSlugs')]
    public function test_delete_plugin_rejects_malicious_slugs(string $slug): void
    {
        $this->expectException(\RuntimeException::class);
        app(PluginService::class)->deletePlugin($slug);
    }

    #[DataProvider('maliciousSlugs')]
    public function test_activate_plugin_rejects_malicious_slugs(string $slug): void
    {
        $this->expectException(\RuntimeException::class);
        app(PluginService::class)->activatePlugin($slug);
    }

    #[DataProvider('maliciousSlugs')]
    public function test_deactivate_plugin_rejects_malicious_slugs(string $slug): void
    {
        $this->expectException(\RuntimeException::class);
        app(PluginService::class)->deactivatePlugin($slug);
    }

    public function test_delete_plugin_still_returns_false_for_missing_but_valid_slug(): void
    {
        $this->assertFalse(app(PluginService::class)->deletePlugin('no-such-plugin'));
    }
}
