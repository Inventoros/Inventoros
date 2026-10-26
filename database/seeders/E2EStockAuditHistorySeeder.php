<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Inventory\Product;
use App\Models\Inventory\StockAudit;
use App\Models\User;
use App\Services\StockAuditService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class E2EStockAuditHistorySeeder extends Seeder
{
    public function run(): void
    {
        $id = (string) env('E2E_AUDIT_HISTORY_ID');
        if (! Str::isUuid($id)) {
            throw new \InvalidArgumentException('E2E_AUDIT_HISTORY_ID must be a fresh UUID.');
        }
        $actor = User::where('email', E2ETestSeeder::TEST_EMAIL)->firstOrFail();
        $product = Product::where('organization_id', $actor->organization_id)->where('sku', E2ETestSeeder::TEST_PRODUCT_SKU)->firstOrFail();
        $audit = StockAudit::create(['organization_id' => $actor->organization_id, 'audit_number' => 'E2E-HISTORY-'.$id,
            'name' => 'Count history browser fixture', 'audit_type' => 'spot', 'status' => 'in_progress', 'created_by' => $actor->id]);
        $item = $audit->items()->create(['product_id' => $product->id, 'system_quantity' => $product->stock, 'status' => 'pending']);
        app(StockAuditService::class)->recordCount($audit, $item, $actor, 3, 'First pass');
        $this->command->line('AUDIT_ID='.$audit->id);
    }
}
