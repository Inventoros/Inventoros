<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * .env.example (and the cPanel package) store sessions and the cache in the
 * database, whose tables only exist once the installer has migrated. Before
 * that, /install and / answered 500 because the session was read from a
 * missing table. Until the installation is complete, file drivers are used
 * instead.
 */
final class InstallerFreshEnvironmentTest extends TestCase
{
    /**
     * The shipped defaults, on a database with no tables at all (the suite's
     * in-memory SQLite; on MySQL and PostgreSQL the tables of other tests
     * exist, but nothing is installed either).
     */
    private function useShippedDriversOnAnEmptyDatabase(): void
    {
        config(['session.driver' => 'database', 'cache.default' => 'database']);

        if (DB::connection()->getDatabaseName() === ':memory:') {
            $this->assertFalse(Schema::hasTable('sessions'));
        }
    }

    public function test_the_installer_opens_before_any_table_exists(): void
    {
        $this->useShippedDriversOnAnEmptyDatabase();

        $this->get('/install')->assertOk();
        $this->get('/install/requirements')->assertOk();
        $this->get('/install/database')->assertOk();
    }

    public function test_the_installer_opens_when_the_default_sqlite_file_does_not_exist(): void
    {
        config([
            'session.driver' => 'database',
            'cache.default' => 'database',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => storage_path('framework/testing/missing-'.uniqid().'.sqlite'),
        ]);
        DB::purge('sqlite');

        $this->get('/install')->assertOk();
        $this->get('/')->assertRedirect(route('install.index'));
    }

    public function test_the_home_page_sends_a_fresh_install_to_the_installer(): void
    {
        $this->useShippedDriversOnAnEmptyDatabase();

        $this->get('/')->assertRedirect(route('install.index'));
        $this->get('/login')->assertRedirect(route('install.index'));
    }

    public function test_the_installer_keeps_its_session_between_steps(): void
    {
        $this->useShippedDriversOnAnEmptyDatabase();

        $first = $this->get('/install/database')->assertOk();
        $this->assertSame('file', config('session.driver'));
        $this->assertSame('file', config('cache.default'));
        $first->assertCookie(config('session.cookie'));
    }
}
