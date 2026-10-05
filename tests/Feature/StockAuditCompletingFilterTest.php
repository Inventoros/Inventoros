<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InvalidStateException;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\User;
use App\Services\StockAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * The stock_audit_completing filter lets a plugin veto (or annotate) the
 * completion of a stock audit: return true to allow it, or a reason string
 * (false for a generic one) to refuse it. A refused audit stays in progress
 * and books nothing, and every surface reports the plugin's reason.
 */
final class StockAuditCompletingFilterTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private User $admin;

    private Product $product;

    private StockAudit $audit;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();
        $org = $this->makeOrganization('Veto Co');
        $this->admin = $this->makeAdmin($org);
        $this->product = $this->makeProduct($org, ['stock' => 10]);

        $this->audit = StockAudit::create([
            'organization_id' => $org->id, 'audit_number' => 'AUD-1', 'name' => 'Spot',
            'audit_type' => 'spot', 'status' => 'in_progress', 'created_by' => $this->admin->id, 'started_at' => now(),
        ]);
        StockAuditItem::create([
            'stock_audit_id' => $this->audit->id, 'product_id' => $this->product->id,
            'system_quantity' => 10, 'counted_quantity' => 7, 'discrepancy' => -3, 'status' => 'counted',
        ]);
    }

    private function assertNothingBooked(): void
    {
        $this->assertSame('in_progress', $this->audit->fresh()->status);
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_the_filter_receives_the_audit_the_actor_and_the_uncounted_flag_and_true_allows_completion(): void
    {
        $seen = null;
        add_filter('stock_audit_completing', function ($allowed, $audit, $actor, $allowUncounted) use (&$seen) {
            $seen = [$allowed, $audit->id, $actor->id, $allowUncounted];

            return $allowed;
        });

        app(StockAuditService::class)->complete($this->audit, $this->admin);

        $this->assertSame([true, $this->audit->id, $this->admin->id, false], $seen);
        $this->assertSame('completed', $this->audit->fresh()->status);
        $this->assertSame(7, $this->product->fresh()->stock);
    }

    public function test_a_reason_string_vetoes_completion_with_that_reason(): void
    {
        add_filter('stock_audit_completing', fn () => 'Two lines are waiting for a supervisor recount.');

        try {
            app(StockAuditService::class)->complete($this->audit, $this->admin);
            $this->fail('Completion should have been vetoed.');
        } catch (InvalidStateException $e) {
            $this->assertSame('Two lines are waiting for a supervisor recount.', $e->getMessage());
            $this->assertSame('completion_vetoed', $e->errorCode);
        }

        $this->assertNothingBooked();
    }

    public function test_false_vetoes_completion_with_a_generic_reason(): void
    {
        add_filter('stock_audit_completing', fn () => false);

        $this->expectException(InvalidStateException::class);
        $this->expectExceptionMessage('An installed plugin is not allowing this audit to be completed yet.');

        try {
            app(StockAuditService::class)->complete($this->audit, $this->admin);
        } finally {
            $this->assertNothingBooked();
        }
    }

    public function test_the_web_page_shows_the_veto_reason(): void
    {
        add_filter('stock_audit_completing', fn () => 'Waiting for a supervisor recount.');

        $this->actingAs($this->admin)
            ->from(route('stock-audits.show', $this->audit))
            ->post(route('stock-audits.complete', $this->audit))
            ->assertRedirect(route('stock-audits.show', $this->audit))
            ->assertSessionHas('error', 'Waiting for a supervisor recount.');

        $this->assertNothingBooked();
    }

    public function test_the_api_answers_422_with_the_veto_reason(): void
    {
        add_filter('stock_audit_completing', fn () => 'Waiting for a supervisor recount.');
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/stock-audits/{$this->audit->id}/complete")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Waiting for a supervisor recount.')
            ->assertJsonPath('error', 'completion_vetoed');

        $this->assertNothingBooked();
    }
}
