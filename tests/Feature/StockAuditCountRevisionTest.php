<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InvalidStateException;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\User;
use App\Services\StockAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

final class StockAuditCountRevisionTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private User $admin;

    private StockAudit $audit;

    private StockAuditItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->markInstalled();
        $org = $this->makeOrganization();
        $this->admin = $this->makeAdmin($org);
        $this->actingAs($this->admin);
        $product = $this->makeProduct($org, ['stock' => 20]);
        $service = app(StockAuditService::class);
        $this->audit = $service->create($org->id, $this->admin, [
            'name' => 'Warehouse count', 'audit_type' => 'spot', 'product_ids' => [$product->id],
        ]);
        $service->start($this->audit, $this->admin);
        $this->item = $this->audit->items()->sole();
    }

    public static function surfaces(): array
    {
        return ['web' => [false], 'REST' => [true]];
    }

    private function endpoint(bool $api): string
    {
        if ($api) {
            Sanctum::actingAs($this->admin);
        }

        return $api
            ? "/api/v1/stock-audits/{$this->audit->id}/items/{$this->item->id}/count"
            : route('stock-audits.items.count', ['stockAudit' => $this->audit, 'item' => $this->item]);
    }

    #[DataProvider('surfaces')]
    public function test_a_delayed_count_cannot_overwrite_another_count(bool $api): void
    {
        $revision = $this->item->count_revision;
        $url = $this->endpoint($api);
        $this->postJson($url, ['counted_quantity' => 18, 'notes' => 'Shelf checked', 'expected_revision' => $revision])->assertOk();

        $response = $this->postJson($url, ['counted_quantity' => 14, 'notes' => 'Delayed draft', 'expected_revision' => $revision])
            ->assertConflict()->assertJsonPath('error', 'stale_count');
        if (! $api) {
            $response->assertJsonPath('item.counted_quantity', 18);
            $this->assertNotSame($revision, $response->json('item.count_revision'));
        }

        $this->assertSame(18, $this->item->fresh()->counted_quantity);
        $this->assertSame('Shelf checked', $this->item->fresh()->notes);
        $this->assertDatabaseCount('stock_adjustments', 0);
        $this->assertSame(20, $this->item->product->stock);
    }

    #[DataProvider('surfaces')]
    public function test_reviewing_the_latest_revision_allows_an_explicit_recount(bool $api): void
    {
        $service = app(StockAuditService::class);
        $service->recordCount($this->audit, $this->item, $this->admin, 18, 'First count');
        $latest = $this->item->fresh()->count_revision;
        $this->postJson($this->endpoint($api), [
            'counted_quantity' => 17, 'notes' => 'Reviewed recount', 'expected_revision' => $latest,
        ])->assertOk();
        $this->assertSame(17, $this->item->fresh()->counted_quantity);
        $this->assertSame('Reviewed recount', $this->item->fresh()->notes);
    }

    public function test_note_only_edits_in_the_same_second_invalidate_the_revision(): void
    {
        $this->freezeTime();
        $service = app(StockAuditService::class);
        $service->recordCount($this->audit, $this->item, $this->admin, 18, 'First note');
        $revision = $this->item->fresh()->count_revision;
        $service->recordCount($this->audit, $this->item, $this->admin, 18, 'Corrected note');
        $this->assertNotSame($revision, $this->item->fresh()->count_revision);
        $this->postJson($this->endpoint(false), [
            'counted_quantity' => 18, 'notes' => 'First note', 'expected_revision' => $revision,
        ])->assertConflict();
        $this->assertSame('Corrected note', $this->item->fresh()->notes);
    }

    public function test_the_service_checks_current_evidence_instead_of_a_stale_model(): void
    {
        $stale = $this->item->fresh();
        $service = app(StockAuditService::class);
        $service->recordCount($this->audit, $this->item, $this->admin, 19);
        try {
            $service->recordCount($this->audit, $stale, $this->admin, 15, expectedRevision: $stale->count_revision);
            $this->fail('A delayed draft must not overwrite the current evidence.');
        } catch (InvalidStateException $e) {
            $this->assertSame('stale_count', $e->errorCode);
        }
        $this->assertSame(19, $this->item->fresh()->counted_quantity);
    }

    #[DataProvider('surfaces')]
    public function test_invalid_revisions_do_not_disable_the_guard(bool $api): void
    {
        $url = $this->endpoint($api);
        foreach ([null, '', 'old', [], str_repeat('0', 65)] as $revision) {
            $this->postJson($url, ['counted_quantity' => 10, 'expected_revision' => $revision])
                ->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        }
        $this->assertNull($this->item->fresh()->counted_quantity);
    }

    #[DataProvider('surfaces')]
    public function test_a_matching_revision_cannot_reopen_a_completed_audit(bool $api): void
    {
        $service = app(StockAuditService::class);
        $service->recordCount($this->audit, $this->item, $this->admin, 20);
        $service->complete($this->audit, $this->admin);
        $this->postJson($this->endpoint($api), [
            'counted_quantity' => 1, 'expected_revision' => $this->item->fresh()->count_revision,
        ])->assertUnprocessable();
        $this->assertSame(20, $this->item->fresh()->counted_quantity);
    }

    public function test_rest_exposes_a_revision_that_round_trips_through_the_guard(): void
    {
        Sanctum::actingAs($this->admin);
        $data = $this->getJson("/api/v1/stock-audits/{$this->audit->id}")->assertOk()->json('data.items.0');
        $this->assertSame($this->item->count_revision, $data['count_revision']);
        $this->postJson($this->endpoint(true), ['counted_quantity' => 0, 'expected_revision' => $data['count_revision']])
            ->assertOk()->assertJsonPath('data.counted_quantity', 0);
    }

    public function test_a_foreign_user_cannot_read_the_current_count_through_a_conflict(): void
    {
        $other = $this->makeAdmin($this->makeOrganization('Other'));
        $this->actingAs($other)->postJson($this->endpoint(false), [
            'counted_quantity' => 1, 'expected_revision' => str_repeat('0', 64),
        ])->assertForbidden()->assertJsonMissingPath('item');
    }
}
