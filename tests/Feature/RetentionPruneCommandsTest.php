<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetentionPruneCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Pr', 'email' => 'pr@test.com']);
        $this->admin = User::create([
            'name' => 'A', 'email' => 'a@test.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
    }

    public function test_activity_logs_prune_removes_rows_older_than_window(): void
    {
        // Three rows: 400 days old (prune), 100 days old (keep), today (keep).
        $old = $this->createLog('old', now()->subDays(400));
        $mid = $this->createLog('mid', now()->subDays(100));
        $recent = $this->createLog('today', now());

        $this->artisan('activity-logs:prune')
            ->expectsOutputToContain('Pruned 1')
            ->assertExitCode(0);

        $this->assertNull(ActivityLog::find($old->id));
        $this->assertNotNull(ActivityLog::find($mid->id));
        $this->assertNotNull(ActivityLog::find($recent->id));
    }

    public function test_activity_logs_prune_honours_older_than_flag(): void
    {
        $this->createLog('x', now()->subDays(45));

        $this->artisan('activity-logs:prune', ['--older-than' => 30])
            ->expectsOutputToContain('Pruned 1')
            ->assertExitCode(0);

        $this->assertSame(0, ActivityLog::count());
    }

    public function test_activity_logs_prune_dry_run_does_not_delete(): void
    {
        $this->createLog('x', now()->subDays(400));

        $this->artisan('activity-logs:prune', ['--dry-run' => true])
            ->expectsOutputToContain('Would delete 1')
            ->assertExitCode(0);

        $this->assertSame(1, ActivityLog::count());
    }

    protected function createLog(string $description, \Illuminate\Support\Carbon $at): ActivityLog
    {
        $log = new ActivityLog([
            'organization_id' => $this->org->id,
            'user_id' => $this->admin->id,
            'action' => 'created',
            'subject_type' => User::class,
            'subject_id' => $this->admin->id,
            'description' => $description,
        ]);
        $log->created_at = $at;
        $log->updated_at = $at;
        $log->save();
        return $log;
    }

    protected function createDelivery(int $webhookId, string $status, \Illuminate\Support\Carbon $at): WebhookDelivery
    {
        $delivery = new WebhookDelivery([
            'webhook_id' => $webhookId,
            'organization_id' => $this->org->id,
            'event' => 'e',
            'payload' => [],
            'status' => $status,
            'attempts' => 1,
        ]);
        $delivery->created_at = $at;
        $delivery->save();
        return $delivery;
    }

    public function test_webhook_deliveries_prune_preserves_failed_by_default(): void
    {
        $webhook = Webhook::create([
            'organization_id' => $this->org->id,
            'name' => 'WH', 'url' => 'https://example.com/h',
            'secret' => 's', 'events' => ['x'], 'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $oldOk = $this->createDelivery($webhook->id, WebhookDelivery::STATUS_SUCCESS, now()->subDays(60));
        $oldFail = $this->createDelivery($webhook->id, WebhookDelivery::STATUS_FAILED, now()->subDays(60));
        $recent = $this->createDelivery($webhook->id, WebhookDelivery::STATUS_SUCCESS, now()->subDays(5));

        $this->artisan('webhooks:prune')
            ->expectsOutputToContain('Pruned 1')
            ->assertExitCode(0);

        $this->assertNull(WebhookDelivery::find($oldOk->id));
        $this->assertNotNull(WebhookDelivery::find($oldFail->id), 'failed delivery must survive default prune');
        $this->assertNotNull(WebhookDelivery::find($recent->id));
    }

    public function test_webhook_deliveries_prune_include_failed_removes_failed_too(): void
    {
        $webhook = Webhook::create([
            'organization_id' => $this->org->id,
            'name' => 'WH', 'url' => 'https://example.com/h',
            'secret' => 's', 'events' => ['x'], 'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $oldFail = $this->createDelivery($webhook->id, WebhookDelivery::STATUS_FAILED, now()->subDays(60));

        $this->artisan('webhooks:prune', ['--include-failed' => true])
            ->expectsOutputToContain('Pruned 1')
            ->assertExitCode(0);

        $this->assertNull(WebhookDelivery::find($oldFail->id));
    }
}
