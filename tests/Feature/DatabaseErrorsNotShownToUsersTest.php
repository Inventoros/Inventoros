<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ApprovalException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\DocumentEmailException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Exceptions\InvalidStateException;
use App\Exceptions\ShippingException;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Controllers used to catch every RuntimeException and flash its message.
 * QueryException is a RuntimeException, so a database failure put raw SQL
 * (table names, values, file paths) in a banner. Only business rule
 * refusals are shown to the user now; database errors reach the normal
 * error handler, which logs them and shows the generic error page.
 */
class DatabaseErrorsNotShownToUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private PurchaseOrder $purchaseOrder;

    private PurchaseOrderItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // Production rendering: the error page never carries the exception.
        config(['app.debug' => false]);
        SystemSetting::set('installed', true, 'boolean');

        $organization = Organization::create([
            'name' => 'Acme',
            'email' => 'acme@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
        ]);
        $this->admin->forceFill(['role' => 'admin'])->save();

        $supplier = Supplier::create([
            'organization_id' => $organization->id,
            'name' => 'Supplier',
            'email' => 'supplier@example.com',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'sku' => 'SKU-1',
            'name' => 'Widget',
            'price' => 10,
            'purchase_price' => 5,
            'currency' => 'USD',
            'stock' => 0,
            'min_stock' => 0,
        ]);

        $this->purchaseOrder = PurchaseOrder::create([
            'organization_id' => $organization->id,
            'supplier_id' => $supplier->id,
            'created_by' => $this->admin->id,
            'po_number' => 'PO-1',
            'status' => PurchaseOrder::STATUS_SENT,
            'order_date' => now()->toDateString(),
            'subtotal' => 50, 'tax' => 0, 'total' => 50, 'currency' => 'USD',
        ]);

        $this->item = $this->purchaseOrder->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity_ordered' => 10,
            'quantity_received' => 0,
            'unit_cost' => 5, 'subtotal' => 50, 'tax' => 0, 'total' => 50,
        ]);
    }

    private function databaseError(): QueryException
    {
        return new QueryException(
            'sqlite',
            'update "purchase_orders" set "status" = ? where "id" = ?',
            ['received', 1],
            new \PDOException('SQLSTATE[HY000]: General error: 14 unable to open database file'),
        );
    }

    /**
     * Make the receive flow hit a database error when it books the line.
     */
    private function failItemWrites(): void
    {
        PurchaseOrderItem::updating(function (): void {
            throw $this->databaseError();
        });
    }

    private function receivePayload(): array
    {
        return ['items' => [['id' => $this->item->id, 'quantity_to_receive' => 1]]];
    }

    public function test_a_database_error_while_receiving_is_not_flashed_to_the_user(): void
    {
        $this->failItemWrites();

        $response = $this->actingAs($this->admin)
            ->post(route('purchase-orders.process-receiving', $this->purchaseOrder), $this->receivePayload());

        $response->assertStatus(500);
        $response->assertSessionMissing('error');
        $this->assertStringNotContainsString('purchase_orders', (string) $response->getContent());
    }

    public function test_a_business_rule_refusal_while_receiving_is_still_flashed(): void
    {
        // A received PO refuses further receiving with InvalidStateException.
        $this->purchaseOrder->forceFill(['status' => PurchaseOrder::STATUS_RECEIVED])->save();

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.process-receiving', $this->purchaseOrder), $this->receivePayload())
            ->assertRedirect(route('purchase-orders.show', $this->purchaseOrder))
            ->assertSessionHas('error', 'This purchase order cannot receive items.');
    }

    public function test_a_database_error_while_receiving_over_the_api_is_not_a_422_with_the_sql(): void
    {
        $this->failItemWrites();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/v1/purchase-orders/{$this->purchaseOrder->id}/receive", $this->receivePayload());

        $response->assertStatus(500);
        $this->assertStringNotContainsString('cannot_receive', (string) $response->getContent());
        $this->assertStringNotContainsString('purchase_orders', (string) $response->getContent());
    }

    public function test_every_domain_refusal_is_a_business_rule_exception(): void
    {
        foreach ([
            InvalidStateException::class,
            InsufficientStockException::class,
            InvalidOrderItemException::class,
            ApprovalException::class,
            ShippingException::class,
            DocumentEmailException::class,
        ] as $class) {
            $this->assertTrue(is_subclass_of($class, BusinessRuleException::class), "{$class} must extend BusinessRuleException.");
        }

        $this->assertFalse(is_subclass_of(QueryException::class, BusinessRuleException::class));
    }

    /**
     * Web, REST and GraphQL code must not catch RuntimeException (or wider)
     * to show its message: that also catches database errors. Catch
     * BusinessRuleException, or a narrower domain exception, instead.
     */
    public function test_request_handlers_do_not_catch_runtime_exception(): void
    {
        $allowed = [
            // Catches its own sentinel for a wrong recovery code and shows a fixed message.
            'app/Http/Controllers/Auth/TwoFactorController.php',
        ];

        $offenders = [];
        foreach (['app/Http', 'app/GraphQL', 'app/Mcp'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                if (in_array($relative, $allowed, true)) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (preg_match('/catch \(\\\\?RuntimeException\b/', $source)) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertSame([], $offenders, 'These files catch RuntimeException, which also swallows database errors.');
    }
}
