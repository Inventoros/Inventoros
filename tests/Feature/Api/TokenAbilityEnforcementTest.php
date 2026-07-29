<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A personal access token's abilities must be enforced, not just the user's
 * role. An admin (who holds every role permission) acting through a scoped
 * "read-only" token can read but not write; a `*` token is unrestricted.
 */
class TokenAbilityEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        $org = Organization::create([
            'name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@o.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'admin', // holds every role permission
        ]);
        $this->product = Product::create([
            'organization_id' => $org->id, 'sku' => 'P-1', 'name' => 'P',
            'price' => 10, 'currency' => 'USD', 'stock' => 5, 'is_active' => true,
        ]);
    }

    public function test_a_read_only_token_can_read_but_not_write(): void
    {
        // A REAL scoped token (not the Sanctum::actingAs mock, which carries no
        // abilities array). Admin role holds delete_products; the token doesn't.
        $token = $this->admin->createToken('ro', ['view_products'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/products')->assertStatus(200);
        $this->withToken($token)->deleteJson("/api/v1/products/{$this->product->id}")
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');

        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'deleted_at' => null]);
    }

    public function test_a_wildcard_token_is_unrestricted(): void
    {
        $token = $this->admin->createToken('full', ['*'])->plainTextToken;

        $this->withToken($token)->deleteJson("/api/v1/products/{$this->product->id}")->assertStatus(200);
        $this->assertSoftDeleted('products', ['id' => $this->product->id]);
    }

    public function test_actingas_session_helper_stays_unrestricted(): void
    {
        // The suite-wide Sanctum::actingAs($user) convention (empty abilities,
        // no PersonalAccessToken abilities array) must remain full-access so it
        // keeps meaning "authenticated with full role permissions".
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/v1/products/{$this->product->id}")->assertStatus(200);
    }
}
