<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Defaults in the shipped Docker files that each caused a real support issue
 * (#261): queue/scheduler containers reported unhealthy, the image health
 * check probed a fixed port, every form answered 419 over plain http, and the
 * app built http:// asset URLs behind a TLS-terminating reverse proxy.
 */
final class DockerDeploymentDefaultsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function compose(string $file): array
    {
        return Yaml::parseFile(base_path($file), Yaml::PARSE_CUSTOM_TAGS);
    }

    public function test_dev_worker_and_scheduler_do_not_inherit_the_web_server_health_check(): void
    {
        $services = $this->compose('docker-compose.yml')['services'];

        foreach (['worker', 'scheduler'] as $service) {
            $this->assertTrue(
                $services[$service]['healthcheck']['disable'] ?? false,
                "{$service} runs no web server; the FrankenPHP base image's health check would mark it unhealthy.",
            );
        }
    }

    public function test_prod_worker_and_scheduler_do_not_inherit_the_web_server_health_check(): void
    {
        $services = $this->compose('docker-compose.prod.yml')['services'];

        foreach (['worker', 'scheduler'] as $service) {
            $this->assertTrue($services[$service]['healthcheck']['disable'] ?? false, $service);
        }
    }

    public function test_the_image_health_check_follows_server_name(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertMatchesRegularExpression('/HEALTHCHECK[^\n]*\\\\\n\s+CMD \["inventoros-healthcheck"\]/', $dockerfile);
        $this->assertStringContainsString('docker/healthcheck.sh /usr/local/bin/inventoros-healthcheck', $dockerfile);
        $this->assertStringContainsString('SERVER_NAME', file_get_contents(base_path('docker/healthcheck.sh')));
    }

    public function test_prod_secure_cookie_default_follows_the_request_scheme(): void
    {
        $env = $this->compose('docker-compose.prod.yml')['services']['app']['environment'];

        // "null" lets the cookie be Secure exactly when the request is https.
        $this->assertSame('${SESSION_SECURE_COOKIE:-null}', $env['SESSION_SECURE_COOKIE']);
    }

    public function test_both_stacks_trust_proxies_on_private_networks_by_default(): void
    {
        foreach (['docker-compose.yml', 'docker-compose.prod.yml'] as $file) {
            $env = $this->compose($file)['services']['app']['environment'];

            $this->assertSame('${TRUSTED_PROXIES:-private_ranges}', $env['TRUSTED_PROXIES'], $file);
        }

        $this->assertStringContainsString('TRUSTED_PROXIES=private_ranges', file_get_contents(base_path('Dockerfile')));
    }

    public function test_prod_app_url_defaults_to_the_published_port(): void
    {
        $env = $this->compose('docker-compose.prod.yml')['services']['app']['environment'];

        $this->assertSame('${APP_URL:-http://localhost:${APP_PORT:-8080}}', $env['APP_URL']);
        $this->assertSame(['${APP_PORT:-8080}:8080'], $this->compose('docker-compose.prod.yml')['services']['app']['ports']);
    }
}
