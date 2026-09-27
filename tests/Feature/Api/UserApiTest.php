<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * REST parity for users: read, plus create/update behind the same
 * privilege-escalation guards as the web UserController.
 */
class UserApiTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
    }

    /**
     * @return array<string, mixed>
     */
    private function newUser(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Person',
            'email' => 'new'.uniqid().'@acme.test',
            'password' => 'Str0ng!Passw0rd#2026',
            'password_confirmation' => 'Str0ng!Passw0rd#2026',
            'role' => 'member',
        ], $overrides);
    }

    public function test_lists_and_shows_only_own_organization_users(): void
    {
        $foreign = $this->makeAdmin($this->makeOrganization('Other'));
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/users')->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('password', $response->getContent());

        $this->getJson("/api/v1/users/{$this->admin->id}")->assertOk()->assertJsonPath('data.email', $this->admin->email);
        $this->getJson("/api/v1/users/{$foreign->id}")->assertNotFound();
        $this->putJson("/api/v1/users/{$foreign->id}", $this->newUser())->assertNotFound();
        // Even an invalid payload must not reveal (via a 422) that the id exists.
        $this->putJson("/api/v1/users/{$foreign->id}", [])->assertNotFound();
    }

    public function test_admin_can_create_and_update_a_user(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/users', $this->newUser(['name' => 'Casey']))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Casey')
            ->assertJsonPath('data.organization_id', $this->org->id)
            ->json('data.id');

        $this->putJson("/api/v1/users/{$id}", ['name' => 'Casey R', 'email' => 'casey@acme.test', 'role' => 'manager'])
            ->assertOk()
            ->assertJsonPath('data.role', 'manager');
    }

    public function test_delegated_user_manager_cannot_mint_an_admin(): void
    {
        $manager = $this->makeMember($this->org, ['view_users', 'create_users', 'edit_users']);
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/users', $this->newUser(['role' => 'admin']))->assertForbidden();
        $this->assertDatabaseMissing('users', ['role' => 'admin', 'id' => $manager->id + 1]);

        // Nor promote an existing member, including themselves.
        $this->putJson("/api/v1/users/{$manager->id}", ['name' => 'Me', 'email' => $manager->email, 'role' => 'admin'])
            ->assertForbidden();
        $this->assertSame('member', $manager->fresh()->role);

        // Nor attach a role carrying permissions they do not hold.
        $powerful = Role::create([
            'name' => 'Powerful', 'slug' => 'powerful', 'organization_id' => $this->org->id,
            'permissions' => ['delete_products'], 'is_system' => false,
        ]);
        $this->postJson('/api/v1/users', $this->newUser(['role_ids' => [$powerful->id]]))->assertForbidden();

        // A plain member with a role inside their own permissions is fine.
        $this->postJson('/api/v1/users', $this->newUser())->assertCreated();
    }

    public function test_roles_from_another_organization_are_rejected(): void
    {
        $foreignRole = Role::create([
            'name' => 'Foreign', 'slug' => 'foreign', 'organization_id' => $this->makeOrganization('Other')->id,
            'permissions' => [], 'is_system' => false,
        ]);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', $this->newUser(['role_ids' => [$foreignRole->id]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role_ids.0']);
    }

    public function test_cannot_demote_the_last_admin(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/users/{$this->admin->id}", ['name' => 'A', 'email' => $this->admin->email, 'role' => 'member'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
        $this->assertSame('admin', $this->admin->fresh()->role);
    }

    public function test_permissions_are_enforced_per_verb(): void
    {
        $viewer = $this->makeMember($this->org, ['view_users']);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/users')->assertOk();
        $this->postJson('/api/v1/users', $this->newUser())->assertForbidden();
        $this->putJson("/api/v1/users/{$viewer->id}", ['name' => 'x', 'email' => $viewer->email, 'role' => 'member'])->assertForbidden();

        Sanctum::actingAs($this->makeMember($this->org, ['view_products']));
        $this->getJson('/api/v1/users')->assertForbidden();
    }
}
