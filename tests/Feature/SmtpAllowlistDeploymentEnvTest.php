<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The production Compose file and the Coolify template list each container's
 * environment explicitly, so a setting missing from them never reaches the
 * app, worker or scheduler even when it is set in .env.
 */
final class SmtpAllowlistDeploymentEnvTest extends TestCase
{
    private const VARIABLE = 'INVENTOROS_SMTP_ALLOWED_HOSTS';

    /** @return array<string, mixed> */
    private function compose(string $file): array
    {
        return Yaml::parseFile(base_path($file), Yaml::PARSE_CUSTOM_TAGS);
    }

    public function test_the_production_compose_file_passes_the_smtp_allowlist_to_every_app_container(): void
    {
        $services = $this->compose('docker-compose.prod.yml')['services'];

        foreach (['app', 'worker', 'scheduler'] as $service) {
            $this->assertSame('${'.self::VARIABLE.':-}', $services[$service]['environment'][self::VARIABLE] ?? null, $service);
        }
    }

    public function test_the_coolify_template_passes_the_smtp_allowlist_to_every_app_container(): void
    {
        $services = $this->compose('deploy/coolify/docker-compose.yml')['services'];

        foreach (['app', 'worker', 'scheduler'] as $service) {
            $this->assertContains(self::VARIABLE.'=${'.self::VARIABLE.':-}', $services[$service]['environment'], $service);
        }
    }
}
