<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Supplier;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspacePreferencesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');
        $this->organization = Organization::factory()->create(['currency' => 'USD']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->admin->forceFill(['role' => 'admin'])->save();
        Storage::fake('local');
    }

    public function test_saved_currency_reaches_every_affected_form_and_new_contacts(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('settings.organization.update.regional'), ['currency' => 'JPY', 'timezone' => 'Asia/Tokyo'])
            ->assertSessionHasNoErrors();

        foreach (['customers.create', 'suppliers.create', 'purchase-orders.create', 'warehouses.create'] as $route) {
            $this->actingAs($this->admin->fresh())->get(route($route))->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('regional.currency', 'JPY')
                    ->where('regional.currencies', fn ($codes) => collect($codes)->contains('JPY')));
        }

        foreach ([Customer::class, Supplier::class] as $model) {
            $record = $model::create(['name' => 'Default currency']);
            $this->assertSame('JPY', $record->fresh()->currency);
            $explicit = $model::create(['name' => 'Explicit currency', 'currency' => 'EUR']);
            $this->assertSame('EUR', $explicit->fresh()->currency);

            $this->organization->update(['currency' => 'CAD']);
            $this->assertSame('JPY', $record->fresh()->currency);
            $this->assertSame('EUR', $explicit->fresh()->currency);
            $this->organization->update(['currency' => 'JPY']);
        }
    }

    public function test_imported_contact_defaults_use_the_records_organization(): void
    {
        $other = Organization::factory()->create(['currency' => 'BRL']);
        $this->actingAs($this->admin);

        foreach ([Customer::class, Supplier::class] as $model) {
            $record = $model::create(['organization_id' => $other->id, 'name' => 'Imported', 'currency' => null]);
            $this->assertSame('BRL', $record->currency);
        }
    }

    public function test_logo_upload_is_shared_served_replaced_and_removed(): void
    {
        $this->actingAs($this->admin)->post(route('settings.organization.logo.store'), [
            'logo' => UploadedFile::fake()->image('company.png'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $organization = $this->organization->fresh();
        $firstPath = $organization->logo_path;
        Storage::disk('local')->assertExists($firstPath);
        $this->actingAs($this->admin->fresh())->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.organization.logo_url', $organization->logo_url));
        $this->get($organization->logo_url)->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->post(route('settings.organization.logo.store'), [
            'logo' => UploadedFile::fake()->image('replacement.png'),
        ])->assertSessionHasNoErrors();
        $replacement = $organization->fresh();
        $this->assertNotSame($organization->logo_url, $replacement->logo_url);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($replacement->logo_path);

        $this->delete(route('settings.organization.logo.destroy'))->assertSessionHasNoErrors();
        $this->assertNull($organization->fresh()->logo_url);
        Storage::disk('local')->assertMissing($replacement->logo_path);
        $this->get($replacement->logo_url)->assertNotFound();
    }

    public function test_logo_reads_and_writes_respect_tenant_and_permission_boundaries(): void
    {
        $this->actingAs($this->admin)->post(route('settings.organization.logo.store'), [
            'logo' => UploadedFile::fake()->image('company.png'),
        ])->assertSessionHasNoErrors();
        $organization = $this->organization->fresh();
        $member = User::factory()->create(['organization_id' => $organization->id]);
        $member->forceFill(['role' => 'member'])->save();

        $this->actingAs($member)->get($organization->logo_url)->assertOk();
        $this->post(route('settings.organization.logo.store'), [
            'logo' => UploadedFile::fake()->image('forbidden.png'),
        ])->assertForbidden();
        $this->delete(route('settings.organization.logo.destroy'))->assertForbidden();

        $other = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($other)->get($organization->logo_url)->assertNotFound();
        $this->assertSame($organization->logo_path, $organization->fresh()->logo_path);
    }

    public function test_logo_rejects_non_images_svg_oversized_files_and_dimensions(): void
    {
        foreach ([
            UploadedFile::fake()->create('not-an-image.png', 1, 'text/plain'),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->image('large.png')->size(2049),
            UploadedFile::fake()->image('wide.png', 2049, 1),
        ] as $file) {
            $this->actingAs($this->admin)->post(route('settings.organization.logo.store'), ['logo' => $file])
                ->assertSessionHasErrors('logo');
        }

        $this->assertNull($this->organization->fresh()->logo_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_permission_inspection_combines_base_and_custom_roles_for_the_target(): void
    {
        Role::create(['name' => 'Member', 'slug' => 'system-member', 'is_system' => true, 'permissions' => ['view_products']]);
        $custom = Role::create([
            'name' => 'Purchasing', 'slug' => 'purchasing', 'organization_id' => $this->organization->id,
            'permissions' => ['view_purchase_orders'],
        ]);
        $target = User::factory()->create(['organization_id' => $this->organization->id]);
        $target->forceFill(['role' => 'member'])->save();
        $target->roles()->attach($custom);

        $this->actingAs($this->admin)->get(route('users.show', $target))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('effectivePermissions', fn ($permissions) => collect($permissions)->sort()->values()->all() === ['view_products', 'view_purchase_orders'])
                ->has('permissions'));
        $this->actingAs($target)->get(route('users.show', $this->admin))->assertForbidden();

        $other = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($this->admin)->get(route('users.show', $other))->assertForbidden();
    }
}
