<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Role;
use App\Models\SavedReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * The route middleware checks a scoped token's ability for the route's own
 * permission only. The service-layer guards behind it (role assignment, user
 * management, report data sources) must also honour the token: an
 * administrator acting through a narrowly scoped token is not an
 * unrestricted administrator.
 *
 * Uses REAL personal access tokens; Sanctum::actingAs carries no abilities.
 */
class ScopedTokenServiceAuthorizationTest extends TestCase
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

    /**
     * @param  array<int, string>  $abilities
     */
    private function token(array $abilities): string
    {
        return $this->admin->createToken('integration', $abilities)->plainTextToken;
    }

    public function test_a_scoped_admin_token_cannot_create_an_administrator(): void
    {
        $this->withToken($this->token(['create_users']))
            ->postJson('/api/v1/users', $this->newUser(['email' => 'minted@acme.test', 'role' => 'admin']))
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'minted@acme.test']);
    }

    public function test_a_scoped_admin_token_cannot_attach_the_administrator_role(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            ['name' => 'Administrator', 'is_system' => true, 'permissions' => ['create_users', 'delete_products']]
        );

        $this->withToken($this->token(['create_users']))
            ->postJson('/api/v1/users', $this->newUser(['email' => 'minted@acme.test', 'role_ids' => [$adminRole->id]]))
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'minted@acme.test']);
    }

    public function test_a_scoped_admin_token_cannot_grant_a_permission_the_token_lacks(): void
    {
        $powerful = Role::create([
            'name' => 'Powerful', 'slug' => 'powerful', 'organization_id' => $this->org->id,
            'permissions' => ['delete_products'], 'is_system' => false,
        ]);

        $this->withToken($this->token(['create_users']))
            ->postJson('/api/v1/users', $this->newUser(['email' => 'minted@acme.test', 'role_ids' => [$powerful->id]]))
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'minted@acme.test']);
    }

    public function test_a_scoped_admin_token_may_grant_what_the_token_allows(): void
    {
        $clerk = Role::create([
            'name' => 'Clerk', 'slug' => 'clerk', 'organization_id' => $this->org->id,
            'permissions' => ['view_products'], 'is_system' => false,
        ]);

        $this->withToken($this->token(['create_users', 'view_products']))
            ->postJson('/api/v1/users', $this->newUser(['email' => 'clerk@acme.test', 'role_ids' => [$clerk->id]]))
            ->assertCreated()
            ->assertJsonPath('data.role', 'member');

        $this->assertDatabaseHas('users', ['email' => 'clerk@acme.test', 'role' => 'member']);
    }

    public function test_a_scoped_admin_token_cannot_reset_another_administrators_password(): void
    {
        $otherAdmin = $this->makeAdmin($this->org);

        $this->withToken($this->token(['edit_users']))
            ->putJson("/api/v1/users/{$otherAdmin->id}", [
                'name' => $otherAdmin->name,
                'email' => $otherAdmin->email,
                'role' => 'admin',
                'password' => 'N3w!Passw0rd#Taken',
                'password_confirmation' => 'N3w!Passw0rd#Taken',
            ])
            ->assertForbidden();

        $this->assertTrue(Hash::check('password', $otherAdmin->fresh()->password));
    }

    public function test_a_scoped_admin_token_cannot_promote_a_member_to_administrator(): void
    {
        $member = $this->makeMember($this->org);

        $this->withToken($this->token(['edit_users']))
            ->putJson("/api/v1/users/{$member->id}", ['name' => 'M', 'email' => $member->email, 'role' => 'admin'])
            ->assertForbidden();

        $this->assertSame('member', $member->fresh()->role);
    }

    public function test_an_unrestricted_admin_token_keeps_full_user_management(): void
    {
        $otherAdmin = $this->makeAdmin($this->org);
        $token = $this->token(['*']);

        $this->withToken($token)
            ->postJson('/api/v1/users', $this->newUser(['email' => 'second@acme.test', 'role' => 'admin']))
            ->assertCreated()
            ->assertJsonPath('data.role', 'admin');

        $this->withToken($token)
            ->putJson("/api/v1/users/{$otherAdmin->id}", [
                'name' => $otherAdmin->name,
                'email' => $otherAdmin->email,
                'role' => 'admin',
                'password' => 'N3w!Passw0rd#Taken',
                'password_confirmation' => 'N3w!Passw0rd#Taken',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('N3w!Passw0rd#Taken', $otherAdmin->fresh()->password));
    }

    public function test_a_scoped_admin_token_cannot_build_a_permission_set_beyond_the_token(): void
    {
        $this->withToken($this->token(['create_roles', 'view_products']))
            ->postJson('/api/v1/permission-sets', ['name' => 'Wide', 'permissions' => ['view_products', 'delete_users']])
            ->assertForbidden();

        $this->assertDatabaseMissing('permission_sets', ['name' => 'Wide']);
    }

    public function test_a_scoped_admin_token_may_build_a_permission_set_within_the_token(): void
    {
        $this->withToken($this->token(['create_roles', 'view_products']))
            ->postJson('/api/v1/permission-sets', ['name' => 'Narrow', 'permissions' => ['view_products']])
            ->assertCreated();

        $this->assertDatabaseHas('permission_sets', ['name' => 'Narrow', 'organization_id' => $this->org->id]);
    }

    private function customerReport(): SavedReport
    {
        Customer::create([
            'organization_id' => $this->org->id,
            'name' => 'Private Customer',
            'email' => 'private@customer.test',
        ]);

        return SavedReport::create([
            'organization_id' => $this->org->id,
            'created_by' => $this->admin->id,
            'name' => 'Customers',
            'data_source' => 'customers',
            'columns' => ['name', 'email'],
            'is_shared' => false,
        ]);
    }

    public function test_a_reports_only_token_cannot_read_a_data_source_it_is_not_scoped_for(): void
    {
        $report = $this->customerReport();
        $token = $this->token(['view_reports']);

        $this->withToken($token)->getJson("/api/v1/reports/{$report->id}")
            ->assertForbidden()
            ->assertDontSee('private@customer.test');

        $this->withToken($token)->get("/api/v1/reports/{$report->id}/export")
            ->assertForbidden();
    }

    public function test_a_token_scoped_for_the_data_source_can_read_the_report(): void
    {
        $report = $this->customerReport();

        $this->withToken($this->token(['view_reports', 'view_customers']))
            ->getJson("/api/v1/reports/{$report->id}")
            ->assertOk()
            ->assertSee('private@customer.test');
    }
}
