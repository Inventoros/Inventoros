<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The production Compose file and the Coolify template list each container's
 * environment explicitly, so the XLSX import limits must be listed there to
 * be overridable. They are passed with empty defaults, which must leave the
 * config defaults in force rather than turning every limit into 0.
 */
final class XlsxImportLimitsDeploymentEnvTest extends TestCase
{
    private const VARIABLES = [
        'IMPORT_XLSX_MAX_ENTRIES',
        'IMPORT_XLSX_MAX_UNCOMPRESSED_BYTES',
        'IMPORT_XLSX_MAX_COMPRESSION_RATIO',
        'IMPORT_XLSX_RATIO_MIN_BYTES',
    ];

    /** @return array<string, mixed> */
    private function compose(string $file): array
    {
        return Yaml::parseFile(base_path($file), Yaml::PARSE_CUSTOM_TAGS);
    }

    public function test_the_production_compose_file_passes_the_limits_to_every_app_container(): void
    {
        $services = $this->compose('docker-compose.prod.yml')['services'];

        foreach (['app', 'worker', 'scheduler'] as $service) {
            foreach (self::VARIABLES as $variable) {
                $this->assertSame('${'.$variable.':-}', $services[$service]['environment'][$variable] ?? null, "{$service}: {$variable}");
            }
        }
    }

    public function test_the_coolify_template_passes_the_limits_to_every_app_container(): void
    {
        $services = $this->compose('deploy/coolify/docker-compose.yml')['services'];

        foreach (['app', 'worker', 'scheduler'] as $service) {
            foreach (self::VARIABLES as $variable) {
                $this->assertContains($variable.'=${'.$variable.':-}', $services[$service]['environment'], "{$service}: {$variable}");
            }
        }
    }

    public function test_empty_overrides_keep_the_config_defaults(): void
    {
        $saved = [];
        foreach (self::VARIABLES as $variable) {
            $saved[$variable] = [$_SERVER[$variable] ?? null, $_ENV[$variable] ?? null, getenv($variable)];
            $_SERVER[$variable] = $_ENV[$variable] = '';
            putenv("{$variable}=");
        }

        try {
            $config = require config_path('imports.php');
        } finally {
            foreach ($saved as $variable => [$server, $env, $put]) {
                if ($server === null) {
                    unset($_SERVER[$variable]);
                } else {
                    $_SERVER[$variable] = $server;
                }
                if ($env === null) {
                    unset($_ENV[$variable]);
                } else {
                    $_ENV[$variable] = $env;
                }
                putenv($put === false ? $variable : "{$variable}={$put}");
            }
        }

        $this->assertSame(1000, $config['xlsx']['max_entries']);
        $this->assertSame(256 * 1024 * 1024, $config['xlsx']['max_uncompressed_bytes']);
        $this->assertSame(100.0, $config['xlsx']['max_compression_ratio']);
        $this->assertSame(1024 * 1024, $config['xlsx']['ratio_min_bytes']);
    }
}
