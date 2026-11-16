<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\System\SystemSetting;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\E2ELayoutSeeder;
use Database\Seeders\E2EStockAuditHistorySeeder;
use Database\Seeders\E2EStockResetSeeder;
use Database\Seeders\E2ETestSeeder;
use Database\Seeders\E2EUiAuditSeeder;
use Database\Seeders\E2EVariantReplenishmentSeeder;
use Database\Seeders\ReadmeDemoSeeder;
use Database\Seeders\ScreenshotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fixture seeders create accounts with passwords published in this
 * repository. They may only run where those accounts are disposable: local
 * development (CI copies .env.example, APP_ENV=local) and the test suite.
 * Any other environment, not only production, is refused before a single
 * record is written.
 */
final class FixtureSeederEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function fixtureSeedersOutsideFixtureEnvironments(): array
    {
        $seeders = [
            E2ETestSeeder::class,
            ScreenshotSeeder::class,
            E2ELayoutSeeder::class,
            E2EUiAuditSeeder::class,
            E2EStockResetSeeder::class,
            E2EStockAuditHistorySeeder::class,
            E2EVariantReplenishmentSeeder::class,
            ReadmeDemoSeeder::class,
            DemoDataSeeder::class,
        ];

        $cases = [];
        foreach ($seeders as $seeder) {
            foreach (['production', 'staging'] as $environment) {
                $cases[class_basename($seeder).' in '.$environment] = [$seeder, $environment];
            }
        }

        return $cases;
    }

    #[DataProvider('fixtureSeedersOutsideFixtureEnvironments')]
    public function test_fixture_seeders_refuse_outside_local_and_testing(string $seeder, string $environment): void
    {
        $this->app['env'] = $environment;

        $refused = false;
        try {
            $this->artisan('db:seed', ['--class' => $seeder, '--force' => true])->run();
        } catch (\RuntimeException $error) {
            $refused = true;
            $this->assertStringContainsString('only runs in the local or testing environment', $error->getMessage());
        }

        $this->assertTrue($refused, class_basename($seeder)." must refuse the {$environment} environment, including with --force.");
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('roles', 0);
        $this->assertFalse(SystemSetting::get('installed', false));
    }

    public function test_the_default_seeder_creates_no_test_login_outside_local_and_testing(): void
    {
        $this->app['env'] = 'staging';

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_the_e2e_seeder_runs_in_testing(): void
    {
        $this->seed(E2ETestSeeder::class);

        $this->assertTrue(User::where('email', E2ETestSeeder::TEST_EMAIL)->exists());
        $this->assertTrue(User::where('email', E2ETestSeeder::STAFF_EMAIL)->exists());
    }

    public function test_the_e2e_seeder_runs_in_local(): void
    {
        $this->app['env'] = 'local';

        $this->seed(E2ETestSeeder::class);

        $this->assertTrue(User::where('email', E2ETestSeeder::TEST_EMAIL)->exists());
    }

    public function test_the_screenshot_seeder_runs_in_testing(): void
    {
        $this->seed(ScreenshotSeeder::class);

        $this->assertTrue(User::where('email', E2ETestSeeder::TEST_EMAIL)->exists());
        $this->assertTrue((bool) SystemSetting::get('installed', false));
    }

    public function test_the_demo_data_seeder_runs_in_testing(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertTrue(User::where('email', E2ETestSeeder::TEST_EMAIL)->exists());
    }
}
