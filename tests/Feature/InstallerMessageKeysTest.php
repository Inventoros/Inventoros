<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The installer's JSON answers carry an i18n key (and the raw error, if any)
 * next to the English message, so the wizard shows them in the chosen
 * language. Every key must exist in en.json.
 */
final class InstallerMessageKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_already_installed_answers_carry_a_key(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $this->postJson('/install/admin', [])
            ->assertForbidden()
            ->assertJson(['message_key' => 'install.server.alreadyInstalled']);
    }

    public function test_a_failed_connection_test_carries_a_key_and_the_error(): void
    {
        $response = $this->postJson('/install/database/test', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'nope',
            'username' => 'nobody',
            'password' => 'x',
        ]);

        $response->assertStatus(422)->assertJson(['message_key' => 'install.server.connectionFailed']);
        $this->assertNotEmpty($response->json('error'));
    }

    public function test_every_key_the_controller_returns_exists_in_en_json(): void
    {
        $source = (string) File::get(app_path('Http/Controllers/Install/InstallerController.php'));
        preg_match_all("/answer\\(\\s*(?:true|false),\\s*'(install\\.[A-Za-z.]+)'/", $source, $matches);
        $keys = array_unique($matches[1]);

        $this->assertNotEmpty($keys);

        $en = json_decode((string) File::get(resource_path('js/i18n/locales/en.json')), true);
        foreach ($keys as $key) {
            $this->assertIsString(data_get($en, $key), "{$key} is missing from en.json");
        }
    }
}
