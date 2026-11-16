<?php

namespace Tests\Feature\Security;

use App\Services\Marketplace\EntitlementStatement;
use App\Services\Marketplace\MarketplaceClient;
use App\Services\Marketplace\PackageSignature;
use App\Services\PluginService;
use App\Services\Plugins\PluginAssetPublisher;
use App\Services\Plugins\PluginPermissionRegistry;
use App\Services\Plugins\PluginWebhookEventRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Validation patterns for slugs, versions, hashes and file names end in \z,
 * not $: "$" also matches before a final newline, so "name\n" would pass.
 */
final class TrailingNewlineAnchorTest extends TestCase
{
    use RefreshDatabase;

    public function test_plugin_service_refuses_a_slug_with_a_trailing_newline(): void
    {
        try {
            app(PluginService::class)->activatePlugin("demo\n");
            $this->fail('A slug with a trailing newline must be refused.');
        } catch (RuntimeException $e) {
            $this->assertSame('Invalid plugin slug.', $e->getMessage());
        }
    }

    public function test_plugin_asset_publisher_refuses_a_slug_with_a_trailing_newline(): void
    {
        $root = storage_path('app/testing/anchor_'.uniqid());
        File::makeDirectory($root, 0755, true, true);

        try {
            $publisher = new PluginAssetPublisher($root.'/plugins', $root.'/public');

            $this->assertFalse($publisher->isPublished('demo', 'ui.js'));

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid plugin slug.');
            $publisher->isPublished("demo\n", 'ui.js');
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_marketplace_identifiers_refuse_a_trailing_newline(): void
    {
        $this->assertTrue(MarketplaceClient::isValidSlug('demo-plugin'));
        $this->assertFalse(MarketplaceClient::isValidSlug("demo-plugin\n"));

        $this->assertTrue(PackageSignature::isValidVersion('1.2.3'));
        $this->assertFalse(PackageSignature::isValidVersion("1.2.3\n"));

        $sha = str_repeat('a', 64);
        $this->assertTrue(PackageSignature::isValidSha256($sha));
        $this->assertFalse(PackageSignature::isValidSha256($sha."\n"));

        $this->assertTrue(EntitlementStatement::isValidInstallId('install-1'));
        $this->assertFalse(EntitlementStatement::isValidInstallId("install-1\n"));

        $this->assertTrue(EntitlementStatement::isValidExpiresAt('2027-01-01T00:00:00Z'));
        $this->assertFalse(EntitlementStatement::isValidExpiresAt("2027-01-01T00:00:00Z\n"));
    }

    public function test_plugin_registries_refuse_a_name_with_a_trailing_newline(): void
    {
        $this->assertTrue(PluginPermissionRegistry::isValidName('demo.view_things'));
        $this->assertFalse(PluginPermissionRegistry::isValidName("demo.view_things\n"));

        $registry = new PluginWebhookEventRegistry;
        $registry->register('demo.thing_done', 'Thing done');

        $this->expectException(InvalidArgumentException::class);
        $registry->register("demo.other_done\n", 'Other done');
    }
}
