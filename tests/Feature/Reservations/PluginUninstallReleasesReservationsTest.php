<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Models\Auth\Organization;
use App\Models\Inventory\StockReservation;
use App\Services\PluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithFixturePlugins;
use Tests\TestCase;

/**
 * Deleting a plugin releases the stock reservations it holds (owner_type is
 * its slug) in every organization, and only those.
 */
final class PluginUninstallReleasesReservationsTest extends TestCase
{
    use InteractsWithFixturePlugins, RefreshDatabase, ReservationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReservationWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixturePlugins();
        parent::tearDown();
    }

    public function test_uninstall_releases_only_that_plugins_holds_in_every_organization(): void
    {
        $slug = $this->makeFixturePlugin();
        $other = Organization::create(['name' => 'Other', 'email' => 'o@other.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $otherProduct = $this->makeProduct('OTHER', 10, [], [], $other);

        $mine = $this->hold(3, 'B-1', owner: $slug);
        $mineElsewhere = $this->hold(2, 'B-2', product: $otherProduct, owner: $slug);
        $someoneElse = $this->hold(4, 'W-1', owner: 'wave-picking');
        $core = $this->hold(1, 'C-1', owner: StockReservation::CORE_OWNER);
        $consumed = $this->hold(1, 'B-3', owner: $slug);
        $this->reservations()->consume($slug, 'B-3', $this->admin);

        $released = [];
        add_action('stock_reservation_released', function ($reservation) use (&$released) {
            $released[] = $reservation->id;
        });

        // Signed in to one organization: the release still reaches the other.
        $this->actingAs($this->admin);
        $this->assertTrue(app(PluginService::class)->deletePlugin($slug));

        $this->assertSame(StockReservation::UNINSTALLED, $mine->fresh()->release_reason);
        $this->assertSame(StockReservation::UNINSTALLED, StockReservation::withoutGlobalScopes()->find($mineElsewhere->id)->release_reason);
        $this->assertTrue($someoneElse->fresh()->isActive());
        $this->assertTrue($core->fresh()->isActive());
        $this->assertNull($consumed->fresh()->released_at, 'A consumed hold stays a record of its consumption.');
        $this->assertEqualsCanonicalizing([$mine->id, $mineElsewhere->id], $released);
        $this->assertSame(5, $this->reservations()->reserved($this->product));
    }
}
