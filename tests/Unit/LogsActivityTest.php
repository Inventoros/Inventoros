<?php

namespace Tests\Unit;

use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogsActivityTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Org',
            'email' => 'test@org.com',
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_creating_model_logs_activity(): void
    {
        $this->actingAs($this->user);

        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Product',
            'sku' => 'TST-001',
            'price' => 10.00,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'action' => 'created',
            'subject_type' => Product::class,
        ]);
    }

    public function test_updating_model_logs_activity_with_changes(): void
    {
        $this->actingAs($this->user);

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Original Name',
            'sku' => 'TST-002',
            'price' => 10.00,
        ]);

        $product->update(['name' => 'Updated Name']);

        $log = ActivityLog::where('action', 'updated')
            ->where('subject_type', Product::class)
            ->where('subject_id', $product->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Updated Name', $log->properties['new']['name']);
        $this->assertSame('Original Name', $log->properties['old']['name']);
    }

    public function test_deleting_model_logs_activity(): void
    {
        $this->actingAs($this->user);

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'To Delete',
            'sku' => 'TST-003',
            'price' => 10.00,
        ]);

        $product->delete();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => 'deleted',
            'subject_type' => Product::class,
            'subject_id' => $product->id,
        ]);
    }

    public function test_no_activity_logged_when_unauthenticated(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'No Auth Product',
            'sku' => 'TST-004',
            'price' => 10.00,
        ]);

        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'created',
            'subject_type' => Product::class,
        ]);
    }

    public function test_activity_log_contains_description(): void
    {
        $this->actingAs($this->user);

        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Product',
            'sku' => 'TST-005',
            'price' => 10.00,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'created',
            'description' => 'Created Product',
        ]);
    }

    public function test_update_without_real_changes_does_not_log(): void
    {
        $this->actingAs($this->user);

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Same Name',
            'sku' => 'TST-006',
            'price' => 10.00,
        ]);

        // Only timestamp changes should be filtered out
        $initialCount = ActivityLog::where('action', 'updated')->count();

        $product->update(['name' => 'Same Name']);

        $this->assertSame($initialCount, ActivityLog::where('action', 'updated')->count());
    }

    public function test_activities_relationship_returns_logs(): void
    {
        $this->actingAs($this->user);

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Original',
            'sku' => 'TST-007',
            'price' => 10.00,
        ]);

        $product->update(['name' => 'Changed']);

        $activities = $product->activities;

        $this->assertGreaterThanOrEqual(1, $activities->count());
    }

    public function test_manual_log_activity(): void
    {
        $this->actingAs($this->user);

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Manual Log Product',
            'sku' => 'TST-008',
            'price' => 10.00,
        ]);

        $product->logActivity('exported', 'Exported product data', ['format' => 'csv']);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'exported',
            'description' => 'Exported product data',
            'subject_type' => Product::class,
            'subject_id' => $product->id,
        ]);
    }

    public function test_activity_log_scoped_to_organization(): void
    {
        $this->actingAs($this->user);

        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Org Product',
            'sku' => 'TST-009',
            'price' => 10.00,
        ]);

        $logs = ActivityLog::forOrganization($this->organization->id)->get();

        $this->assertGreaterThan(0, $logs->count());
        foreach ($logs as $log) {
            $this->assertSame($this->organization->id, $log->organization_id);
        }
    }
}
