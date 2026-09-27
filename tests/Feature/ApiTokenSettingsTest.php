<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Settings > API tokens: list, create (abilities limited to the permissions
 * the user holds, plaintext shown once) and revoke the user's own tokens.
 */
class ApiTokenSettingsTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $this->member = $this->makeMember($this->org, ['view_products', 'view_orders']);
    }

    public function test_page_lists_only_the_users_own_tokens_and_offers_only_held_permissions(): void
    {
        $this->member->createToken('ci-reader', ['view_products']);
        $this->makeAdmin($this->org)->createToken('someone-else', ['*']);

        $this->actingAs($this->member)
            ->get(route('settings.api-tokens.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/ApiTokens/Index')
                ->has('tokens', 1)
                ->where('tokens.0.name', 'ci-reader')
                ->where('tokens.0.abilities', ['view_products'])
                ->has('tokens.0.last_used_at')
                ->has('tokens.0.created_at')
                ->missing('tokens.0.token')
                ->where('availableAbilities', fn ($groups) => collect($groups)->flatten(1)->pluck('value')->sort()->values()->all() === ['view_orders', 'view_products'])
            );
    }

    public function test_creating_a_token_reveals_the_plaintext_once(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('settings.api-tokens.store'), ['name' => 'Zapier', 'abilities' => ['view_orders']]);

        $response->assertRedirect(route('settings.api-tokens.index'))
            ->assertSessionHas('newApiToken');

        $plaintext = session('newApiToken');
        $token = PersonalAccessToken::findToken($plaintext);
        $this->assertNotNull($token);
        $this->assertSame($this->member->id, $token->tokenable_id);
        $this->assertSame(['view_orders'], $token->abilities);
        $this->assertSame('Zapier', $token->name);

        // The plaintext is usable against the API, within its abilities.
        auth()->forgetGuards();
        $this->withToken($plaintext)->getJson('/api/v1/orders')->assertOk();
        $this->withToken($plaintext)->getJson('/api/v1/products')->assertForbidden();

        // The redirected page shows it once; the next page load never does.
        $this->actingAs($this->member)->get(route('settings.api-tokens.index'))
            ->assertInertia(fn (Assert $page) => $page->where('flash.newApiToken', $plaintext));
        $this->actingAs($this->member)->get(route('settings.api-tokens.index'))
            ->assertInertia(fn (Assert $page) => $page->where('flash.newApiToken', null));
    }

    public function test_cannot_grant_a_permission_the_user_does_not_hold(): void
    {
        $this->actingAs($this->member)
            ->post(route('settings.api-tokens.store'), ['name' => 'Escalate', 'abilities' => ['view_orders', 'delete_products']])
            ->assertSessionHasErrors('abilities.1');

        $this->actingAs($this->member)
            ->post(route('settings.api-tokens.store'), ['name' => 'Wildcard', 'abilities' => ['*']])
            ->assertSessionHasErrors('abilities.0');

        $this->actingAs($this->member)
            ->post(route('settings.api-tokens.store'), ['name' => 'Empty', 'abilities' => []])
            ->assertSessionHasErrors('abilities');

        $this->assertSame(0, $this->member->tokens()->count());
    }

    public function test_can_revoke_own_token_but_not_someone_elses(): void
    {
        $mine = $this->member->createToken('mine', ['view_orders'])->accessToken;
        $theirs = $this->makeAdmin($this->org)->createToken('theirs', ['*'])->accessToken;

        $this->actingAs($this->member)
            ->delete(route('settings.api-tokens.destroy', $theirs->id))
            ->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $theirs->id]);

        $this->actingAs($this->member)
            ->delete(route('settings.api-tokens.destroy', $mine->id))
            ->assertRedirect(route('settings.api-tokens.index'));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $mine->id]);
    }

    public function test_requires_authentication(): void
    {
        $this->get(route('settings.api-tokens.index'))->assertRedirect(route('login'));
    }
}
