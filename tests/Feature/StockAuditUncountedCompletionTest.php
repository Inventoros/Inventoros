<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Completing an audit with lines nobody counted needs an explicit
 * confirmation (allow_uncounted). Uncounted lines never adjust stock.
 */
final class StockAuditUncountedCompletionTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private StockAudit $audit;

    private StockAuditItem $counted;

    private StockAuditItem $uncounted;

    private \App\Models\User $admin;

    private \App\Models\Inventory\Product $a;

    private \App\Models\Inventory\Product $b;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $org = $this->makeOrganization('Audit Co');
        $this->admin = $this->makeAdmin($org);
        $this->a = $this->makeProduct($org, ['stock' => 10]);
        $this->b = $this->makeProduct($org, ['stock' => 20]);

        $this->audit = StockAudit::create([
            'organization_id' => $org->id, 'audit_number' => 'AUD-1', 'name' => 'Spot',
            'audit_type' => 'spot', 'status' => 'in_progress', 'created_by' => $this->admin->id, 'started_at' => now(),
        ]);
        $this->counted = StockAuditItem::create([
            'stock_audit_id' => $this->audit->id, 'product_id' => $this->a->id,
            'system_quantity' => 10, 'counted_quantity' => 8, 'discrepancy' => -2, 'status' => 'counted',
        ]);
        $this->uncounted = StockAuditItem::create([
            'stock_audit_id' => $this->audit->id, 'product_id' => $this->b->id,
            'system_quantity' => 20, 'status' => 'pending',
        ]);
    }

    public function test_web_completion_with_uncounted_lines_needs_confirmation(): void
    {
        $this->actingAs($this->admin)
            ->post(route('stock-audits.complete', $this->audit))
            ->assertSessionHas('error');

        $this->assertSame('in_progress', $this->audit->fresh()->status);
        $this->assertSame(10, $this->a->fresh()->stock);

        $this->actingAs($this->admin)
            ->post(route('stock-audits.complete', $this->audit), ['allow_uncounted' => true])
            ->assertSessionHas('success');

        $this->assertSame('completed', $this->audit->fresh()->status);
        $this->assertSame(8, $this->a->fresh()->stock);
        // The uncounted line changed nothing.
        $this->assertSame(20, $this->b->fresh()->stock);
        $this->assertNull($this->uncounted->fresh()->counted_quantity);
    }

    public function test_api_completion_with_uncounted_lines_needs_the_flag(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/stock-audits/{$this->audit->id}/complete")
            ->assertStatus(422)
            ->assertJsonPath('error', 'uncounted_items');

        $this->postJson("/api/v1/stock-audits/{$this->audit->id}/complete", ['allow_uncounted' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(20, $this->b->fresh()->stock);
    }

    public function test_a_fully_counted_audit_completes_without_the_flag(): void
    {
        $this->uncounted->update(['counted_quantity' => 20, 'discrepancy' => 0, 'status' => 'counted']);

        $this->actingAs($this->admin)
            ->post(route('stock-audits.complete', $this->audit))
            ->assertSessionHas('success');

        $this->assertSame('completed', $this->audit->fresh()->status);
    }
}
