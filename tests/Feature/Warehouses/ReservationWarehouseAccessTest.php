<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\StockReservation;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The stock reservation list and the release action follow the warehouse
 * restriction: a user assigned to warehouse A neither sees nor releases
 * holds placed in warehouse B.
 */
class ReservationWarehouseAccessTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    private StockReservation $holdA;

    private StockReservation $holdB;

    private StockReservation $unbinnedHold;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpWarehouseAccess();

        $holds = app(StockReservationService::class);
        $this->holdA = $holds->reserve($this->productA, null, 2, 'rental', 'ALPHA-HOLD', $this->locationA->id);
        $this->holdB = $holds->reserve($this->productB, null, 3, 'rental', 'BRAVO-HOLD', $this->locationB->id);
        $this->unbinnedHold = $holds->reserve($this->productA, null, 1, 'rental', 'UNBINNED-HOLD');
    }

    /**
     * @return array<int, string>
     */
    private function listedReferences($user): array
    {
        $references = [];

        $this->actingAs($user)->get(route('reservations.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$references) {
                $references = collect($page->toArray()['props']['reservations']['data'])
                    ->pluck('owner_reference')->sort()->values()->all();
            });

        return $references;
    }

    public function test_restricted_user_only_sees_holds_in_their_warehouse(): void
    {
        $this->assertSame(['ALPHA-HOLD'], $this->listedReferences($this->restricted));
    }

    public function test_unrestricted_users_still_see_every_hold(): void
    {
        $everything = ['ALPHA-HOLD', 'BRAVO-HOLD', 'UNBINNED-HOLD'];

        $this->assertSame($everything, $this->listedReferences($this->admin));
        $this->assertSame($everything, $this->listedReferences($this->unassigned));
    }

    public function test_restricted_user_cannot_release_a_hold_in_another_warehouse(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('reservations.release', $this->holdB))
            ->assertForbidden();

        $this->assertTrue($this->holdB->fresh()->isActive());
    }

    public function test_restricted_user_cannot_release_a_hold_bound_to_no_location(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('reservations.release', $this->unbinnedHold))
            ->assertForbidden();

        $this->assertTrue($this->unbinnedHold->fresh()->isActive());
    }

    public function test_restricted_user_can_release_a_hold_in_their_own_warehouse(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('reservations.release', $this->holdA))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(StockReservation::ADMIN, $this->holdA->fresh()->release_reason);
    }

    public function test_admin_can_release_a_hold_in_any_warehouse(): void
    {
        $this->actingAs($this->admin)
            ->post(route('reservations.release', $this->holdB))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(StockReservation::ADMIN, $this->holdB->fresh()->release_reason);
    }
}
