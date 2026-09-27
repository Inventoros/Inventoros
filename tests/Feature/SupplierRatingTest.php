<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Supplier;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Suppliers carry an optional 1-5 rating, editable on the supplier form and
 * shown on the supplier index and show pages.
 */
final class SupplierRatingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
    }

    public function test_a_supplier_can_be_created_with_a_rating(): void
    {
        $this->actingAs($this->admin)
            ->post(route('suppliers.store'), ['name' => 'Acme', 'rating' => 4])
            ->assertSessionHasNoErrors();

        $this->assertSame(4, Supplier::where('name', 'Acme')->sole()->rating);
    }

    public function test_the_rating_is_optional(): void
    {
        $this->actingAs($this->admin)
            ->post(route('suppliers.store'), ['name' => 'Acme', 'rating' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull(Supplier::where('name', 'Acme')->sole()->rating);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidRatings(): array
    {
        return [
            'zero' => [0],
            'six' => [6],
            'negative' => [-1],
            'fraction' => [2.5],
            'text' => ['great'],
        ];
    }

    #[DataProvider('invalidRatings')]
    public function test_an_out_of_range_rating_is_rejected_on_create(mixed $rating): void
    {
        $this->actingAs($this->admin)
            ->post(route('suppliers.store'), ['name' => 'Acme', 'rating' => $rating])
            ->assertSessionHasErrors('rating');

        $this->assertDatabaseMissing('suppliers', ['name' => 'Acme']);
    }

    public function test_the_rating_can_be_changed_and_cleared_on_update(): void
    {
        $supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'rating' => 2]);

        $this->actingAs($this->admin)
            ->put(route('suppliers.update', $supplier), ['name' => 'Acme', 'rating' => 5])
            ->assertSessionHasNoErrors();
        $this->assertSame(5, $supplier->fresh()->rating);

        $this->actingAs($this->admin)
            ->put(route('suppliers.update', $supplier), ['name' => 'Acme', 'rating' => 9])
            ->assertSessionHasErrors('rating');
        $this->assertSame(5, $supplier->fresh()->rating);

        $this->actingAs($this->admin)
            ->put(route('suppliers.update', $supplier), ['name' => 'Acme', 'rating' => null])
            ->assertSessionHasNoErrors();
        $this->assertNull($supplier->fresh()->rating);
    }

    public function test_index_and_show_expose_the_rating(): void
    {
        $supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'rating' => 3]);

        $this->actingAs($this->admin)->get(route('suppliers.index'))
            ->assertInertia(fn (Assert $page) => $page->where('suppliers.data.0.rating', 3));

        $this->actingAs($this->admin)->get(route('suppliers.show', $supplier))
            ->assertInertia(fn (Assert $page) => $page->where('supplier.rating', 3));
    }

    public function test_the_api_validates_and_returns_the_rating(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/suppliers', ['name' => 'Acme', 'rating' => 7])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rating');

        $this->postJson('/api/v1/suppliers', ['name' => 'Acme', 'rating' => 5])
            ->assertCreated()
            ->assertJsonPath('data.rating', 5);
    }
}
