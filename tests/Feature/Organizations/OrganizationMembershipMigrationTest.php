<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The membership migrations roll back and forward on every driver (the
 * role_user unique index is swapped while MySQL needs an index for the
 * role_id foreign key at all times).
 */
final class OrganizationMembershipMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = [
        '2026_10_05_053128_create_organization_user_table.php',
        '2026_10_05_053129_add_organization_id_to_role_user_table.php',
        '2026_10_05_053130_add_organization_id_to_personal_access_tokens_table.php',
    ];

    public function test_the_migrations_roll_back_and_run_again(): void
    {
        $migrations = array_map(fn (string $file) => require database_path('migrations/'.$file), self::MIGRATIONS);

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        $this->assertFalse(Schema::hasTable('organization_user'));
        $this->assertFalse(Schema::hasColumn('role_user', 'organization_id'));
        $this->assertFalse(Schema::hasColumn('personal_access_tokens', 'organization_id'));

        foreach ($migrations as $migration) {
            $migration->up();
        }

        $this->assertTrue(Schema::hasTable('organization_user'));
        $this->assertTrue(Schema::hasColumn('role_user', 'organization_id'));
        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'organization_id'));
    }
}
