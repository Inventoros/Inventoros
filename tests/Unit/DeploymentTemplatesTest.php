<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Deployment templates pin the release they install. They must move with
 * VERSION, or a fresh deploy would quietly install an older Inventoros.
 */
class DeploymentTemplatesTest extends TestCase
{
    private function root(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }

    public function test_coolify_template_defaults_to_the_current_version(): void
    {
        $version = trim((string) file_get_contents($this->root('VERSION')));
        $template = (string) file_get_contents($this->root('deploy/coolify/docker-compose.yml'));

        preg_match_all('#ghcr\.io/inventoros/inventoros:\$\{INVENTOROS_VERSION:-([^}]+)\}#', $template, $matches);

        $this->assertCount(3, $matches[1], 'app, worker and scheduler each pin the image.');
        $this->assertSame([$version, $version, $version], $matches[1]);
    }

    public function test_coolify_template_generates_its_own_secrets(): void
    {
        $template = (string) file_get_contents($this->root('deploy/coolify/docker-compose.yml'));

        $this->assertSame(3, substr_count($template, 'APP_KEY=base64:${SERVICE_REALBASE64_32_APPKEY}'));
        $this->assertSame(3, substr_count($template, 'DB_PASSWORD=${SERVICE_PASSWORD_64_POSTGRES}'));
        $this->assertSame(1, substr_count($template, 'RUN_MIGRATIONS=true'), 'Only the app service migrates.');
    }

    public function test_installer_is_executable_bash_with_strict_mode(): void
    {
        $script = (string) file_get_contents($this->root('scripts/install.sh'));

        $this->assertStringStartsWith("#!/usr/bin/env bash\n", $script);
        $this->assertStringContainsString('set -Eeuo pipefail', $script);
        $this->assertStringNotContainsString("\r", $script, 'CRLF line endings break bash.');
    }
}
