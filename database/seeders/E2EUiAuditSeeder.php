<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Inventory\CycleCountSchedule;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\WorkOrder;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Webhook;
use App\Services\StockAuditService;
use Illuminate\Database\Seeder;

/** Only invoked by the opt-in UI audit against its disposable database. */
final class E2EUiAuditSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(E2ELayoutSeeder::class);
        $user = User::where('email', E2ETestSeeder::TEST_EMAIL)->firstOrFail();
        $org = $user->organization_id;
        $product = Product::withoutGlobalScopes()->where('organization_id', $org)->firstOrFail();
        $locations = ProductLocation::withoutGlobalScopes()->where('organization_id', $org)->take(2)->get();
        $audit = StockAudit::create(['organization_id' => $org, 'audit_number' => 'AUDIT-UI-QA', 'name' => 'Quarterly count', 'status' => 'in_progress', 'audit_type' => 'spot', 'created_by' => $user->id]);
        $draftAudit = StockAudit::create(['organization_id' => $org, 'audit_number' => 'AUDIT-UI-DRAFT', 'name' => 'Draft count', 'status' => 'draft', 'audit_type' => 'spot', 'created_by' => $user->id]);
        $item = $audit->items()->create(['product_id' => $product->id, 'system_quantity' => $product->stock, 'status' => 'pending']);
        app(StockAuditService::class)->recordCount($audit, $item, $user, 3, 'First count');
        $cycle = CycleCountSchedule::create(['organization_id' => $org, 'name' => 'Weekly warehouse cycle', 'frequency' => 'weekly', 'scope_type' => 'all', 'products_per_run' => 20, 'created_by' => $user->id]);
        $adjustment = StockAdjustment::create(['organization_id' => $org, 'product_id' => $product->id, 'user_id' => $user->id, 'type' => 'adjustment', 'quantity_before' => 5, 'quantity_after' => 5, 'adjustment_quantity' => 0, 'reason' => 'Audit display fixture']);
        $transfer = StockTransfer::create(['organization_id' => $org, 'transfer_number' => 'TRANSFER-UI-QA', 'from_location_id' => $locations[0]->id, 'to_location_id' => $locations[1]->id, 'transferred_by' => $user->id, 'status' => 'pending']);
        $work = WorkOrder::create(['organization_id' => $org, 'product_id' => $product->id, 'created_by' => $user->id, 'work_order_number' => 'WORK-UI-QA', 'quantity' => 10, 'quantity_produced' => 0, 'status' => 'pending']);
        $webhook = Webhook::create(['organization_id' => $org, 'name' => 'Audit integration', 'url' => 'https://example.test/webhook', 'events' => ['order.created'], 'is_active' => false, 'created_by' => $user->id]);
        $layout = json_decode(file_get_contents(base_path('e2e/.auth/layout-fixtures.json')), true);
        $ids = [
            'product' => $product->id, 'customer' => Customer::withoutGlobalScopes()->where('organization_id', $org)->firstOrFail()->id,
            'supplier' => Supplier::withoutGlobalScopes()->where('organization_id', $org)->firstOrFail()->id,
            'warehouse' => Warehouse::withoutGlobalScopes()->where('organization_id', $org)->firstOrFail()->id,
            'user' => $user->id, 'role' => Role::where('slug', 'e2e-warehouse-staff')->firstOrFail()->id,
            'order' => $layout['order'], 'purchaseOrder' => $layout['purchase_order'], 'returnOrder' => $layout['return'], 'saved_report' => $layout['report'],
            'stockAudit' => $audit->id, 'item' => $item->id, 'stockAdjustment' => $adjustment->id, 'stockTransfer' => $transfer->id,
            'work_order' => $work->id, 'cycleCount' => $cycle->id, 'webhook' => $webhook->id,
        ];
        $map = json_decode(file_get_contents(base_path('docs/audits/ui-ux-application-map.json')), true);
        $routes = [];
        $excluded = [];
        foreach ($map['mapped_routes'] as $route) {
            if (str_starts_with($route['uri'], 'api/') || str_starts_with($route['uri'], 'portal/') || str_starts_with($route['uri'], 'install') || in_array($route['name'], ['login', 'register', 'password.request', 'password.reset', 'two-factor.challenge'], true)) {
                continue;
            }
            $uri = preg_replace_callback('/\{([^}:]+)(?::[^}]+)?\}/', fn ($match) => $ids[$match[1]] ?? $match[0], $route['uri']);
            if ($route['name'] === 'stock-audits.edit') $uri = 'stock-audits/'.$draftAudit->id.'/edit';
            if ($route['name'] === 'purchase-orders.edit') {
                $draftPo = \App\Models\Purchasing\PurchaseOrder::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'draft')->firstOrFail();
                $uri = 'purchase-orders/'.$draftPo->id.'/edit';
            }
            if (str_contains($uri, '{')) {
                $excluded[] = ['name' => $route['name'], 'reason' => 'Requires a state-specific fixture'];
                continue;
            }
            $routes[] = ['name' => $route['name'], 'url' => '/'.$uri, 'pages' => $route['pages']];
        }
        // Invokable controllers and conditional pages need explicit coverage.
        $routes[] = ['name' => 'reports.batch-expiry', 'url' => '/reports/batch-expiry', 'pages' => ['Reports/BatchExpiry']];
        $routes[] = ['name' => 'returns.create.with-order', 'url' => '/returns/create?order_id='.$layout['order'], 'pages' => ['Returns/Create']];
        file_put_contents(base_path('e2e/.auth/ui-audit.json'), json_encode(['routes' => $routes, 'excluded' => $excluded, 'layout' => $layout], JSON_PRETTY_PRINT));
    }
}
