<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * POST /api/v1/tokens must never mint a token broader than the one calling
 * it. A scoped token could mint an unrestricted one (admin default `*`), and
 * a non-admin's default was `[]`, which the ability check treats as
 * unrestricted.
 */
class TokenMintScopeTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
        $this->product = $this->makeProduct($this->org);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function mint(string $callerToken, array $body): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($callerToken)->postJson('/api/v1/tokens', $body);
    }

    private function deleteProductWith(string $token): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->deleteJson("/api/v1/products/{$this->product->id}");
    }

    public function test_a_scoped_admin_token_cannot_mint_a_wildcard_token_by_default(): void
    {
        $readOnly = $this->admin->createToken('ro', ['view_products'])->plainTextToken;

        $minted = $this->mint($readOnly, ['name' => 'escalate'])
            ->assertCreated()
            ->assertJsonPath('abilities', ['view_products'])
            ->json('token');

        $this->deleteProductWith($minted)->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'deleted_at' => null]);
    }

    public function test_a_scoped_token_cannot_request_abilities_it_does_not_have(): void
    {
        $readOnly = $this->admin->createToken('ro', ['view_products'])->plainTextToken;

        $this->mint($readOnly, ['name' => 'x', 'abilities' => ['*']])
            ->assertStatus(422)->assertJsonValidationErrors(['abilities']);
        $this->mint($readOnly, ['name' => 'x', 'abilities' => ['delete_products']])
            ->assertStatus(422)->assertJsonValidationErrors(['abilities']);

        $this->mint($readOnly, ['name' => 'x', 'abilities' => ['view_products']])
            ->assertCreated()->assertJsonPath('abilities', ['view_products']);
    }

    public function test_a_non_admin_default_token_is_never_unrestricted(): void
    {
        $member = $this->makeMember($this->org, ['view_products', 'edit_products']);
        $login = $member->createToken('login')->plainTextToken; // login tokens carry ['*']

        $abilities = $this->mint($login, ['name' => 'default'])->assertCreated()->json('abilities');

        $this->assertNotSame([], $abilities);
        $this->assertNotContains('*', $abilities);
        $this->assertEqualsCanonicalizing(['view_products', 'edit_products'], $abilities);
    }

    public function test_a_non_admin_cannot_request_abilities_they_do_not_hold(): void
    {
        $member = $this->makeMember($this->org, ['view_products']);
        Sanctum::actingAs($member);

        $this->postJson('/api/v1/tokens', ['name' => 'x', 'abilities' => ['delete_products']])
            ->assertStatus(422)->assertJsonValidationErrors(['abilities']);
    }

    public function test_a_non_admin_without_permissions_gets_no_token_rather_than_an_unrestricted_one(): void
    {
        Sanctum::actingAs($this->makeMember($this->org));

        $this->postJson('/api/v1/tokens', ['name' => 'nothing'])
            ->assertStatus(422)->assertJsonValidationErrors(['abilities']);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_an_unrestricted_admin_still_gets_a_wildcard_token_by_default(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/tokens', ['name' => 'full'])->assertCreated()->assertJsonPath('abilities', ['*']);
    }

    public function test_existing_tokens_with_no_declared_abilities_keep_working(): void
    {
        // Backward compatibility: tokens minted before this change with [].
        $legacy = $this->admin->createToken('legacy', [])->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($legacy)->getJson('/api/v1/products')->assertOk();
    }
}
