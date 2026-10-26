<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class InventoryConfigurationTest extends TestCase
{
    public function test_strict_tracking_and_reset_budget_can_be_configured_independently(): void
    {
        $saved = [];
        foreach (['INVENTORY_STRICT_TRACKED_STOCK', 'INVENTOROS_STOCK_RESET_MAX_RECORDS'] as $key) {
            $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
        }
        try {
            foreach ([[false, 5000], [true, 17]] as [$strict, $budget]) {
                foreach (['INVENTORY_STRICT_TRACKED_STOCK' => $strict ? 'true' : 'false', 'INVENTOROS_STOCK_RESET_MAX_RECORDS' => (string) $budget] as $key => $value) {
                    $_SERVER[$key] = $_ENV[$key] = $value;
                    putenv($key.'='.$value);
                }
                $config = require config_path('inventory.php');
                $this->assertSame($strict, $config['strict_tracked_stock']);
                $this->assertSame($budget, $config['stock_reset_max_records']);
            }
        } finally {
            foreach ($saved as $key => [$server, $environment, $process]) {
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                if ($environment === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $environment;
                }
                $process === false ? putenv($key) : putenv($key.'='.$process);
            }
        }
    }
}
