<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Once installed, the configured session and cache drivers are left alone.
 */
final class InstallerFreshEnvironmentInstalledTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_installed_application_keeps_its_database_drivers(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        config(['session.driver' => 'database', 'cache.default' => 'database']);

        $this->get('/login')->assertOk();

        $this->assertSame('database', config('session.driver'));
        $this->assertSame('database', config('cache.default'));
    }
}
