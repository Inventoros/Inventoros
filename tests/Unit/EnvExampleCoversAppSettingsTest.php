<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every Inventoros-specific setting read in config/ must be discoverable in
 * .env.example (set or commented out), so operators can find it without
 * reading the config files. Framework and vendor keys are not checked.
 */
class EnvExampleCoversAppSettingsTest extends TestCase
{
    private const APP_PREFIXES = [
        'INVENTOROS_', 'INVENTORY_', 'REPORTS_', 'EXPORT_', 'IMPORT_',
        'LOW_STOCK_', 'API_DOCS_', 'REGISTRATION_', 'DEFAULT_CURRENCY', 'ENABLE_GRAPHIQL',
    ];

    public function test_every_app_setting_in_config_is_listed_in_env_example(): void
    {
        $root = dirname(__DIR__, 2);

        $keys = [];
        foreach (glob($root.'/config/*.php') as $file) {
            preg_match_all("/env\\(\\s*'([A-Z0-9_]+)'/", (string) file_get_contents($file), $m);
            $keys = array_merge($keys, $m[1]);
        }

        $appKeys = array_values(array_filter(array_unique($keys), function (string $key): bool {
            foreach (self::APP_PREFIXES as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return true;
                }
            }

            return false;
        }));

        $this->assertNotEmpty($appKeys);

        $example = str_replace("\r", '', (string) file_get_contents($root.'/.env.example'));
        preg_match_all('/^#?\s*([A-Z0-9_]+)=/m', $example, $m);
        $listed = $m[1];

        $missing = array_values(array_diff($appKeys, $listed));
        sort($missing);

        $this->assertSame([], $missing, 'Settings read in config/ but missing from .env.example');
    }
}
