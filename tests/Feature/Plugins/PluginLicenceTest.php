<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Jobs\RefreshMarketplaceEntitlementsJob;
use App\Models\Auth\Organization;
use App\Models\System\MarketplaceEntitlement;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Marketplace\EntitlementStatement;
use App\Services\Marketplace\PluginLicence;
use App\Services\Marketplace\PluginLicenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * plugin_licence(): signed runtime entitlements for paid plugins, verified
 * against the marketplace public key, cached per organization, with an
 * offline grace period, and failing safe (never valid without a verified
 * signature, never throwing, never calling the marketplace inline).
 */
final class PluginLicenceTest extends TestCase
{
    use RefreshDatabase;

    private string $secretKey;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        config([
            'marketplace.url' => 'https://market.test',
            'marketplace.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'marketplace.entitlement_grace_days' => 14,
        ]);

        $this->org = Organization::create(['name' => 'Licensed', 'email' => 'l@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->org->forceFill(['marketplace_token' => 'tok-123'])->save();

        Queue::fake();
        Http::preventStrayRequests();
    }

    private function installId(): string
    {
        return app(PluginLicenceService::class)->installId($this->org->id);
    }

    /**
     * @return array{slug: string, expires_at: string, signature: string}
     */
    private function entry(string $slug, Carbon $expiresAt, ?string $installId = null, ?string $secret = null): array
    {
        $expires = $expiresAt->copy()->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'slug' => $slug,
            'expires_at' => $expires,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                EntitlementStatement::message($installId ?? $this->installId(), $slug, $expires),
                $secret ?? $this->secretKey,
            )),
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $entries
     */
    private function store(array $entries, ?Carbon $fetchedAt = null): void
    {
        MarketplaceEntitlement::withoutGlobalScopes()->updateOrCreate(['organization_id' => $this->org->id], [
            'install_id' => $this->installId(),
            'document' => json_encode(['install_id' => $this->installId(), 'issued_at' => null, 'entitlements' => $entries]),
            'fetched_at' => $fetchedAt ?? now(),
            'attempted_at' => $fetchedAt ?? now(),
        ]);
    }

    private function fakeMarketplace(array $entries, int $status = 200): void
    {
        Http::fake([
            'https://market.test/api/v1/marketplace/entitlements*' => Http::response(
                ['data' => ['install_id' => $this->installId(), 'issued_at' => now()->toIso8601String(), 'entitlements' => $entries]],
                $status,
            ),
        ]);
    }

    public function test_a_signed_current_entitlement_is_valid(): void
    {
        $this->store([$this->entry('insights', now()->addDays(20))]);

        $licence = plugin_licence('insights', $this->org);

        $this->assertSame(PluginLicence::VALID, $licence->status);
        $this->assertTrue($licence->isValid());
        $this->assertTrue($licence->allowsWrites());
        $this->assertFalse($licence->inGrace);
    }

    public function test_it_defaults_to_the_signed_in_users_organization(): void
    {
        $this->store([$this->entry('insights', now()->addDays(20))]);
        $user = User::create(['name' => 'U', 'email' => 'u@l.test', 'password' => bcrypt('x'), 'organization_id' => $this->org->id, 'role' => 'admin']);

        $this->actingAs($user);

        $this->assertTrue(plugin_licence('insights')->isValid());
    }

    public function test_a_tampered_expiry_does_not_verify(): void
    {
        $entry = $this->entry('insights', now()->subDays(30));
        $entry['expires_at'] = now()->addYear()->utc()->format('Y-m-d\TH:i:s\Z');
        $this->store([$entry]);

        $licence = plugin_licence('insights', $this->org);

        $this->assertSame(PluginLicence::UNKNOWN, $licence->status);
        $this->assertSame('invalid_signature', $licence->reason);
        $this->assertFalse($licence->allowsWrites());
    }

    public function test_an_entitlement_signed_by_another_key_does_not_verify(): void
    {
        $this->store([$this->entry('insights', now()->addDays(20), secret: sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);

        $this->assertSame('invalid_signature', plugin_licence('insights', $this->org)->reason);
    }

    public function test_an_entitlement_issued_to_another_installation_does_not_verify(): void
    {
        $this->store([$this->entry('insights', now()->addDays(20), installId: 'f3c1a0d4-0000-4000-8000-000000000000:'.$this->org->id)]);

        $this->assertSame('invalid_signature', plugin_licence('insights', $this->org)->reason);
    }

    public function test_a_document_copied_from_another_organization_is_rejected(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'o@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $otherInstall = app(PluginLicenceService::class)->installId($other->id);
        $this->store([$this->entry('insights', now()->addDays(20), installId: $otherInstall)]);
        MarketplaceEntitlement::withoutGlobalScopes()->where('organization_id', $this->org->id)->update([
            'document' => json_encode(['install_id' => $otherInstall, 'entitlements' => [$this->entry('insights', now()->addDays(20), installId: $otherInstall)]]),
        ]);

        $this->assertSame('install_mismatch', plugin_licence('insights', $this->org)->reason);
    }

    public function test_an_expired_entitlement_reported_by_the_marketplace_is_expired(): void
    {
        // Fetched after it expired: the marketplace itself says it is over.
        $this->store([$this->entry('insights', now()->subDays(2))], fetchedAt: now()->subHour());

        $licence = plugin_licence('insights', $this->org);

        $this->assertSame(PluginLicence::EXPIRED, $licence->status);
        $this->assertFalse($licence->allowsWrites());
    }

    public function test_offline_grace_keeps_an_entitlement_valid_until_it_runs_out(): void
    {
        // Last reached the marketplace 10 days ago, before the lease ran out 3 days ago.
        $this->store([$this->entry('insights', now()->subDays(3))], fetchedAt: now()->subDays(10));

        $licence = plugin_licence('insights', $this->org);
        $this->assertSame(PluginLicence::VALID, $licence->status);
        $this->assertTrue($licence->inGrace);
        $this->assertSame('offline_grace', $licence->reason);

        $this->travel(12)->days(); // 15 days past expiry, grace is 14
        app()->forgetScopedInstances();
        $this->assertSame(PluginLicence::EXPIRED, plugin_licence('insights', $this->org)->status);
    }

    public function test_without_a_public_key_nothing_is_valid(): void
    {
        $this->store([$this->entry('insights', now()->addDays(20))]);
        config(['marketplace.public_key' => '']);

        $licence = plugin_licence('insights', $this->org);

        $this->assertSame(PluginLicence::UNKNOWN, $licence->status);
        $this->assertSame('no_public_key', $licence->reason);
    }

    public function test_a_plugin_the_account_does_not_own_is_missing(): void
    {
        $this->store([$this->entry('insights', now()->addDays(20))]);

        $this->assertSame(PluginLicence::MISSING, plugin_licence('forecasting', $this->org)->status);
    }

    public function test_an_organization_without_a_marketplace_connection_is_missing(): void
    {
        $this->org->forceFill(['marketplace_token' => null])->save();

        $licence = plugin_licence('insights', $this->org);

        $this->assertSame(PluginLicence::MISSING, $licence->status);
        $this->assertSame('not_connected', $licence->reason);
    }

    public function test_reading_a_licence_never_calls_the_marketplace_and_queues_a_refresh_when_stale(): void
    {
        config(['queue.default' => 'database']);

        $licence = plugin_licence('insights', $this->org);

        $this->assertSame(PluginLicence::UNKNOWN, $licence->status);
        $this->assertSame('not_checked', $licence->reason);
        Http::assertNothingSent();
        Queue::assertPushed(RefreshMarketplaceEntitlementsJob::class, fn ($job) => $job->organizationId === $this->org->id);

        // Throttled: a second stale read in the same hour queues nothing more.
        plugin_licence('insights', $this->org);
        Queue::assertPushed(RefreshMarketplaceEntitlementsJob::class, 1);
    }

    public function test_with_the_sync_queue_the_refresh_runs_after_the_response(): void
    {
        Bus::fake();

        plugin_licence('insights', $this->org);

        Bus::assertDispatchedAfterResponse(RefreshMarketplaceEntitlementsJob::class);
        Http::assertNothingSent();
    }

    public function test_a_fresh_document_queues_no_refresh(): void
    {
        $this->store([$this->entry('insights', now()->addDays(20))]);

        plugin_licence('insights', $this->org);

        Queue::assertNothingPushed();
    }

    public function test_refresh_stores_a_verified_document(): void
    {
        $this->fakeMarketplace([$this->entry('insights', now()->addDays(30))]);

        $this->assertTrue(app(PluginLicenceService::class)->refresh($this->org));

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://market.test/api/v1/marketplace/entitlements?install_id=')
            && $request->hasHeader('Authorization', 'Bearer tok-123'));
        app()->forgetScopedInstances();
        $this->assertTrue(plugin_licence('insights', $this->org)->isValid());
    }

    public function test_refresh_rejects_a_document_that_does_not_verify_and_keeps_the_previous_one(): void
    {
        $this->store([$this->entry('insights', now()->addDays(20))]);
        $bad = $this->entry('insights', now()->addDays(400));
        $bad['signature'] = base64_encode(str_repeat('x', 64));
        $this->fakeMarketplace([$bad]);

        $this->assertFalse(app(PluginLicenceService::class)->refresh($this->org));

        $row = MarketplaceEntitlement::withoutGlobalScopes()->where('organization_id', $this->org->id)->sole();
        $this->assertStringContainsString('signature', (string) $row->last_error);
        app()->forgetScopedInstances();
        $licence = plugin_licence('insights', $this->org);
        $this->assertTrue($licence->isValid());
        $this->assertTrue($licence->expiresAt->lt(now()->addDays(21)));
    }

    public function test_an_unreachable_marketplace_keeps_the_previous_document(): void
    {
        $this->store([$this->entry('insights', now()->addDays(20))], fetchedAt: now()->subDays(2));
        Http::fake(['https://market.test/*' => Http::response('down', 503)]);

        $this->assertFalse(app(PluginLicenceService::class)->refresh($this->org));
        app()->forgetScopedInstances();

        $this->assertTrue(plugin_licence('insights', $this->org)->isValid());
    }

    public function test_the_refresh_command_and_job_refresh_connected_organizations(): void
    {
        $this->fakeMarketplace([$this->entry('insights', now()->addDays(30))]);

        $this->artisan('marketplace:refresh-entitlements')->assertSuccessful();

        $this->assertNotNull(MarketplaceEntitlement::withoutGlobalScopes()->where('organization_id', $this->org->id)->value('fetched_at'));
    }

    public function test_disconnecting_forgets_the_document(): void
    {
        $this->store([$this->entry('insights', now()->addDays(20))]);
        $admin = User::create(['name' => 'A', 'email' => 'a@l.test', 'password' => bcrypt('x'), 'organization_id' => $this->org->id, 'role' => 'admin']);

        $this->actingAs($admin)->delete(route('plugins.marketplace.disconnect'));

        $this->assertSame(0, MarketplaceEntitlement::withoutGlobalScopes()->count());
        $this->assertSame(PluginLicence::MISSING, plugin_licence('insights', $this->org->fresh())->status);
    }

    public function test_the_licence_check_never_throws(): void
    {
        MarketplaceEntitlement::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'install_id' => $this->installId(), 'document' => '{not json', 'fetched_at' => now(),
        ]);

        $licence = plugin_licence('insights', $this->org);

        $this->assertFalse($licence->isValid());
    }
}
