<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan db:seed` on a release install (composer install --no-dev, so
 * no Faker) used to die with "Class Faker\Factory not found" from the user
 * factory, and on production it would have created a known test login.
 */
final class DatabaseSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_test_user_in_development(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(User::where('email', 'test@example.com')->exists());
    }

    public function test_it_does_nothing_without_faker(): void
    {
        $this->app->bind(DatabaseSeeder::class, fn () => new class extends DatabaseSeeder
        {
            protected function fakerAvailable(): bool
            {
                return false;
            }
        });

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_it_never_creates_the_test_login_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
    }
}
