<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class PluginControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => ['view_plugins', 'manage_plugins'],
            ]
        );

        $memberRole = Role::firstOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'is_system' => true,
                'permissions' => [],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);
    }

    public function test_admin_can_view_plugins_list(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('plugins.index'));

        $response->assertStatus(200);
    }

    public function test_member_cannot_view_plugins(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('plugins.index'));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_view_plugins(): void
    {
        $response = $this->get(route('plugins.index'));

        $response->assertRedirect(route('login'));
    }

    // ==================== UPLOAD TESTS (P0-4) ====================

    /**
     * Track plugin directories created during a test so we can scrub them
     * after the case completes. RefreshDatabase doesn't touch the
     * filesystem.
     *
     * @var string[]
     */
    protected array $createdPluginDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPluginDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }
        $this->createdPluginDirs = [];

        parent::tearDown();
    }

    protected function makePluginZip(array $entries): UploadedFile
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'inv-plugin-') . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return new UploadedFile($zipPath, 'plugin.zip', 'application/zip', null, true);
    }

    public function test_upload_route_rejects_when_uploads_disabled_by_default(): void
    {
        config(['plugins.upload_enabled' => false]);

        $file = $this->makePluginZip([
            'sample-plugin/plugin.json' => json_encode([
                'name' => 'Sample', 'version' => '1.0.0', 'main_file' => 'Plugin.php',
            ]),
            'sample-plugin/Plugin.php' => "<?php\n",
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('plugins.upload'), ['plugin' => $file]);

        $response->assertRedirect(route('plugins.index'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('disabled', session('error'));
        $this->assertFalse(File::isDirectory(base_path('plugins/sample-plugin')));
    }

    public function test_upload_with_feature_enabled_succeeds_for_valid_zip(): void
    {
        config(['plugins.upload_enabled' => true]);

        $this->createdPluginDirs[] = base_path('plugins/p4-happy-path');

        $file = $this->makePluginZip([
            'p4-happy-path/plugin.json' => json_encode([
                'name' => 'Happy', 'version' => '1.0.0', 'main_file' => 'Plugin.php',
            ]),
            'p4-happy-path/Plugin.php' => "<?php\n// noop\n",
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('plugins.upload'), ['plugin' => $file]);

        $response->assertRedirect(route('plugins.index'));
        $response->assertSessionHas('success');
        $this->assertTrue(File::isFile(base_path('plugins/p4-happy-path/plugin.json')));
    }

    public function test_upload_rejects_zipslip_parent_directory_entry(): void
    {
        config(['plugins.upload_enabled' => true]);

        $file = $this->makePluginZip([
            'evil/plugin.json' => json_encode(['name' => 'Evil', 'version' => '1', 'main_file' => 'P.php']),
            'evil/../../../etc/passwd-poc' => 'pwned',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('plugins.upload'), ['plugin' => $file]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('unsafe path entry', session('error'));
        $this->assertFalse(File::isDirectory(base_path('plugins/evil')));
        $this->assertFalse(File::exists(base_path('../etc/passwd-poc')));
    }

    public function test_upload_rejects_absolute_path_entry(): void
    {
        config(['plugins.upload_enabled' => true]);

        $file = $this->makePluginZip([
            'good/plugin.json' => json_encode(['name' => 'Good', 'version' => '1', 'main_file' => 'P.php']),
            '/absolute/leak.txt' => 'leak',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('plugins.upload'), ['plugin' => $file]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('absolute path entry', session('error'));
        $this->assertFalse(File::isDirectory(base_path('plugins/good')));
    }

    public function test_upload_rejects_zip_exceeding_entry_count_limit(): void
    {
        config([
            'plugins.upload_enabled' => true,
            'plugins.max_entry_count' => 5,
        ]);

        $entries = [
            'too-big/plugin.json' => json_encode(['name' => 'TB', 'version' => '1', 'main_file' => 'P.php']),
        ];
        for ($i = 0; $i < 10; $i++) {
            $entries["too-big/extra-{$i}.txt"] = 'x';
        }
        $file = $this->makePluginZip($entries);

        $response = $this->actingAs($this->admin)
            ->post(route('plugins.upload'), ['plugin' => $file]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('entry count limit', session('error'));
        $this->assertFalse(File::isDirectory(base_path('plugins/too-big')));
    }
}
