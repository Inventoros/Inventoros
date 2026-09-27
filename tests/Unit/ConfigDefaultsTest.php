<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Config keys the code reads with a fallback. The fallback hid the fact that
 * the config file did not exist, so the setting could not be changed without
 * editing code. The files must exist and keep the same defaults.
 */
class ConfigDefaultsTest extends TestCase
{
    public function test_notifications_config_file_exists_with_the_low_stock_cooldown(): void
    {
        $this->assertFileExists(config_path('notifications.php'));

        $config = require config_path('notifications.php');

        $this->assertArrayHasKey('low_stock_cooldown_minutes', $config);
        $this->assertSame(1440, config('notifications.low_stock_cooldown_minutes'));
    }

    public function test_reports_config_file_exists_with_the_row_cap(): void
    {
        $this->assertFileExists(config_path('reports.php'));

        $config = require config_path('reports.php');

        $this->assertArrayHasKey('max_rows', $config);
        $this->assertSame(10000, config('reports.max_rows'));
    }
}
