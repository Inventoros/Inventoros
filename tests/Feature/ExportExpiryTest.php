<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\DataExport;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

/**
 * Exports past config('exports.retention_days') are refused on download and
 * removed (file and record) by the scheduled exports:prune command.
 */
class ExportExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $exporter;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        config(['exports.retention_days' => 7]);
        Storage::fake('local');

        $this->organization = $this->makeOrganization('Expiry Org', 'expiry@org.com');
        $this->exporter = $this->makeUser($this->organization, 'exporter@org.com');
    }

    private function makeOrganization(string $name, string $email): Organization
    {
        return Organization::create([
            'name' => $name,
            'email' => $email,
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
    }

    private function makeUser(Organization $organization, string $email): User
    {
        $user = User::create([
            'name' => 'Exporter',
            'email' => $email,
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'admin',
        ]);

        $role = Role::firstOrCreate(
            ['slug' => 'export-only'],
            ['name' => 'Export', 'is_system' => true, 'permissions' => ['export_data']],
        );
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    /**
     * Create an export whose timestamps are $ageDays in the past. A completed
     * export gets a file on the fake disk unless $withFile is false.
     */
    private function makeExport(
        string $status,
        int $ageDays,
        ?Organization $organization = null,
        ?User $user = null,
        bool $withFile = true,
    ): DataExport {
        $organization ??= $this->organization;
        $user ??= $this->exporter;
        $at = now()->subDays($ageDays);
        $path = 'exports/'.$organization->id.'/'.uniqid('', true).'.xlsx';

        if ($withFile && $status === 'completed') {
            Storage::disk('local')->put($path, 'fake-xlsx-bytes');
        }

        $export = new DataExport([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'type' => 'products',
            'filename' => 'products.xlsx',
            'disk' => 'local',
            'path' => $path,
            'filters' => [],
            'status' => $status,
            'row_count' => 5,
            'completed_at' => $status === 'completed' ? $at : null,
        ]);
        $export->created_at = $at;
        $export->updated_at = $at;
        $export->save();

        return $export;
    }

    private function exists(DataExport $export): bool
    {
        return DataExport::withoutGlobalScope(OrganizationScope::class)->whereKey($export->id)->exists();
    }

    // ---- Model ---------------------------------------------------------

    public function test_completed_export_expires_after_the_retention_window(): void
    {
        $recent = $this->makeExport('completed', 2);
        $old = $this->makeExport('completed', 8);

        $this->assertFalse($recent->isExpired());
        $this->assertTrue($recent->isDownloadable());
        $this->assertTrue($old->isExpired());
        $this->assertFalse($old->isDownloadable());
        $this->assertTrue($recent->expiresAt()->equalTo($recent->completed_at->copy()->addDays(7)));
    }

    public function test_zero_retention_disables_expiry(): void
    {
        config(['exports.retention_days' => 0]);
        $old = $this->makeExport('completed', 400);

        $this->assertFalse($old->isExpired());
        $this->assertNull($old->expiresAt());
    }

    // ---- Downloads -----------------------------------------------------

    public function test_download_of_an_expired_export_redirects_with_a_message(): void
    {
        $export = $this->makeExport('completed', 8);

        $this->actingAs($this->exporter)
            ->get(route('import-export.download', $export))
            ->assertRedirect(route('import-export.index'))
            ->assertSessionHas('error');
    }

    public function test_download_of_an_expired_export_returns_410_json(): void
    {
        $export = $this->makeExport('completed', 8);

        $this->actingAs($this->exporter)
            ->getJson(route('import-export.download', $export))
            ->assertStatus(410)
            ->assertJsonPath('error', 'export_expired');
    }

    public function test_download_of_a_recent_export_still_works(): void
    {
        $export = $this->makeExport('completed', 6);

        $this->actingAs($this->exporter)
            ->get(route('import-export.download', $export))
            ->assertOk()
            ->assertDownload('products.xlsx');
    }

    public function test_expired_export_of_another_user_still_404s(): void
    {
        $colleague = User::create([
            'name' => 'Colleague', 'email' => 'colleague@org.com', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'member',
        ]);
        $role = Role::create([
            'slug' => 'export-member', 'name' => 'Exporter', 'is_system' => false,
            'organization_id' => $this->organization->id, 'permissions' => ['export_data'],
        ]);
        $colleague->roles()->syncWithoutDetaching([$role->id]);

        $export = $this->makeExport('completed', 8);

        $this->actingAs($colleague)
            ->get(route('import-export.download', $export))
            ->assertNotFound();
    }

    public function test_index_marks_expired_exports(): void
    {
        $recent = $this->makeExport('completed', 1);
        $old = $this->makeExport('completed', 9);

        $this->actingAs($this->exporter)
            ->get(route('import-export.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('exports', function ($exports) use ($recent, $old) {
                    $byId = collect($exports)->keyBy('id');

                    return $byId[$recent->id]['expired'] === false
                        && $byId[$recent->id]['expires_at'] !== null
                        && $byId[$old->id]['expired'] === true;
                }));
    }

    // ---- Prune command -------------------------------------------------

    public function test_prune_removes_expired_file_and_record_and_keeps_recent(): void
    {
        $recent = $this->makeExport('completed', 3);
        $old = $this->makeExport('completed', 10);

        $this->artisan('exports:prune')
            ->expectsOutputToContain('Pruned 1')
            ->assertExitCode(0);

        $this->assertTrue($this->exists($recent));
        Storage::disk('local')->assertExists($recent->path);

        $this->assertFalse($this->exists($old));
        Storage::disk('local')->assertMissing($old->path);
    }

    public function test_prune_tolerates_a_missing_file(): void
    {
        $old = $this->makeExport('completed', 10, withFile: false);

        $this->artisan('exports:prune')->assertExitCode(0);

        $this->assertFalse($this->exists($old));
    }

    public function test_prune_leaves_in_progress_exports_alone(): void
    {
        $pending = $this->makeExport('pending', 0);
        $processing = $this->makeExport('processing', 1);

        $this->artisan('exports:prune')->assertExitCode(0);

        $this->assertTrue($this->exists($pending));
        $this->assertTrue($this->exists($processing));
    }

    public function test_prune_removes_abandoned_in_progress_and_old_failed_exports(): void
    {
        $abandoned = $this->makeExport('processing', 10);
        $oldFailed = $this->makeExport('failed', 10);
        $recentFailed = $this->makeExport('failed', 1);

        $this->artisan('exports:prune')->assertExitCode(0);

        $this->assertFalse($this->exists($abandoned));
        $this->assertFalse($this->exists($oldFailed));
        $this->assertTrue($this->exists($recentFailed));
    }

    public function test_prune_covers_every_organization(): void
    {
        $otherOrg = $this->makeOrganization('Other Org', 'other@org.com');
        $otherUser = $this->makeUser($otherOrg, 'other@user.com');

        $mineOld = $this->makeExport('completed', 10);
        $theirsOld = $this->makeExport('completed', 10, $otherOrg, $otherUser);
        $theirsRecent = $this->makeExport('completed', 1, $otherOrg, $otherUser);

        // Even when run with a user bound (e.g. from a web-triggered
        // Artisan::call), the prune must not be limited to one tenant.
        $this->actingAs($this->exporter);
        $this->artisan('exports:prune')->assertExitCode(0);

        $this->assertFalse($this->exists($mineOld));
        $this->assertFalse($this->exists($theirsOld));
        $this->assertTrue($this->exists($theirsRecent));
        Storage::disk('local')->assertExists($theirsRecent->path);
    }

    public function test_prune_is_capped_per_run(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeExport('completed', 10);
        }

        $this->artisan('exports:prune', ['--limit' => 3])
            ->expectsOutputToContain('Pruned 3')
            ->assertExitCode(0);

        $this->assertSame(2, DataExport::withoutGlobalScope(OrganizationScope::class)->count());
    }

    public function test_prune_keeps_the_record_when_the_file_cannot_be_deleted_and_continues(): void
    {
        $broken = $this->makeExport('completed', 10);
        $broken->update(['disk' => 'broken']);
        $fine = $this->makeExport('completed', 10);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('delete')->andThrow(new \RuntimeException('storage offline'));
        Storage::set('broken', $disk);

        $this->artisan('exports:prune')
            ->expectsOutputToContain('Pruned 1')
            ->assertExitCode(0);

        // Kept so the next run retries the file; the healthy one is gone.
        $this->assertTrue($this->exists($broken));
        $this->assertFalse($this->exists($fine));
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $old = $this->makeExport('completed', 10);

        $this->artisan('exports:prune', ['--dry-run' => true])
            ->expectsOutputToContain('Would prune 1')
            ->assertExitCode(0);

        $this->assertTrue($this->exists($old));
        Storage::disk('local')->assertExists($old->path);
    }

    public function test_prune_does_nothing_when_retention_is_disabled(): void
    {
        config(['exports.retention_days' => 0]);
        $old = $this->makeExport('completed', 400);

        $this->artisan('exports:prune')->assertExitCode(0);

        $this->assertTrue($this->exists($old));
    }

    public function test_prune_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('exports:prune')
            ->assertExitCode(0);
    }
}
