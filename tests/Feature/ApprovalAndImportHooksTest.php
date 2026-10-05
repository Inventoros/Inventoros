<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessOrderImportJob;
use App\Jobs\ProcessProductImportJob;
use App\Models\Inventory\StockAdjustmentRequest;
use App\Models\Inventory\StockTransfer;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Services\ApprovalService;
use App\Services\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\Approvals\ApprovalFixtures;
use Tests\TestCase;

/**
 * approval_requested, approval_decided and import_finished: the hooks a
 * notification plugin needs to announce requests waiting for a decision,
 * the decisions, and imports that finished, whichever surface did it.
 */
final class ApprovalAndImportHooksTest extends TestCase
{
    use ApprovalFixtures, RefreshDatabase;

    /** @var array<int, array<int, mixed>> */
    private array $requested = [];

    /** @var array<int, array<int, mixed>> */
    private array $decided = [];

    /** @var array<int, array<int, mixed>> */
    private array $imports = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovalWorld();

        add_action('approval_requested', function (...$args): void {
            $this->requested[] = $args;
        });
        add_action('approval_decided', function (...$args): void {
            $this->decided[] = $args;
        });
        add_action('import_finished', function (...$args): void {
            $this->imports[] = $args;
        });
    }

    public function test_a_held_stock_adjustment_announces_the_request_once_with_its_description(): void
    {
        $this->enableApprovals(['stock_adjustments_enabled' => true]);

        $request = app(ApprovalService::class)
            ->requestStockAdjustment($this->requester, $this->product, null, -5, 'damage', 'Dropped');

        $this->assertCount(1, $this->requested);
        [$approval, $subject, $user] = $this->requested[0];
        $this->assertSame(ApprovalService::STOCK_ADJUSTMENT, $approval['type']);
        $this->assertSame($request->id, $approval['id']);
        $this->assertSame('pending', $approval['status']);
        $this->assertNotEmpty($approval['url']);
        $this->assertInstanceOf(StockAdjustmentRequest::class, $subject);
        $this->assertTrue($user->is($this->requester));
    }

    public function test_a_purchase_order_submission_and_its_decision_are_announced(): void
    {
        $this->enableApprovals(['purchase_orders_enabled' => true, 'purchase_orders_threshold' => null]);
        $po = $this->draftPo(500);
        $approvals = app(ApprovalService::class);

        $approvals->submitPurchaseOrder($po, $this->requester);
        $this->assertCount(1, $this->requested);
        $this->assertSame(ApprovalService::PURCHASE_ORDER, $this->requested[0][0]['type']);
        $this->assertInstanceOf(PurchaseOrder::class, $this->requested[0][1]);

        $approvals->approve($this->approver, ApprovalService::PURCHASE_ORDER, $po->id, 'Fine');

        $this->assertCount(1, $this->decided);
        [$approval, $subject, $decision, $user, $notes] = $this->decided[0];
        $this->assertSame(ApprovalService::PURCHASE_ORDER, $approval['type']);
        $this->assertSame('approved', $approval['status']);
        $this->assertSame($po->id, $subject->id);
        $this->assertSame('approved', $decision);
        $this->assertTrue($user->is($this->approver));
        $this->assertSame('Fine', $notes);
    }

    public function test_a_rejected_transfer_is_announced_as_rejected(): void
    {
        $this->enableApprovals(['stock_transfers_enabled' => true]);

        $transfer = app(StockTransferService::class)->create($this->org->id, $this->requester, [
            'from_location_id' => $this->locationA->id,
            'to_location_id' => $this->locationB->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 3]],
        ]);

        $this->assertCount(1, $this->requested);
        $this->assertSame(ApprovalService::STOCK_TRANSFER, $this->requested[0][0]['type']);
        $this->assertInstanceOf(StockTransfer::class, $this->requested[0][1]);

        app(ApprovalService::class)->reject($this->approver, ApprovalService::STOCK_TRANSFER, $transfer->id, 'Not now');

        $this->assertCount(1, $this->decided);
        $this->assertSame('rejected', $this->decided[0][2]);
        $this->assertSame('Not now', $this->decided[0][4]);
    }

    public function test_nothing_is_announced_when_the_request_rolls_back(): void
    {
        $this->enableApprovals(['stock_adjustments_enabled' => true]);

        try {
            DB::transaction(function (): void {
                app(ApprovalService::class)
                    ->requestStockAdjustment($this->requester, $this->product, null, -5, 'damage');

                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->requested);
    }

    public function test_a_queued_product_import_announces_completion_and_failure(): void
    {
        Notification::fake();
        Storage::fake('local');
        Storage::disk('local')->put('imports/p.csv', "sku,name,price,stock\nIMP-1,Widget,5,10\n");

        (new ProcessProductImportJob($this->org->id, $this->admin->id, 'local', 'imports/p.csv'))->handle();

        $this->assertCount(1, $this->imports);
        [$type, $organizationId, $user, $result] = $this->imports[0];
        $this->assertSame('products', $type);
        $this->assertSame($this->org->id, $organizationId);
        $this->assertTrue($user->is($this->admin));
        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['queued']);
        $this->assertSame(1, $result['stats']['imported']);

        (new ProcessOrderImportJob($this->org->id, $this->admin->id, 'local', 'imports/missing.csv', false))
            ->failed(new RuntimeException('boom'));

        $this->assertCount(2, $this->imports);
        $this->assertSame('orders', $this->imports[1][0]);
        $this->assertSame('failed', $this->imports[1][3]['status']);
    }

    public function test_a_small_import_from_the_web_page_announces_completion(): void
    {
        $role = Role::firstOrCreate(['slug' => 'import-only'], ['name' => 'Import', 'is_system' => true, 'permissions' => ['import_data']]);
        $this->admin->roles()->syncWithoutDetaching([$role->id]);

        $file = UploadedFile::fake()->createWithContent('products.csv', "sku,name,price,stock\nWEB-1,Widget,5,10\n");

        $this->actingAs($this->admin)->post(route('import-export.import-products'), ['file' => $file]);

        $this->assertCount(1, $this->imports);
        $this->assertSame('products', $this->imports[0][0]);
        $this->assertSame('completed', $this->imports[0][3]['status']);
        $this->assertFalse($this->imports[0][3]['queued']);
    }
}
