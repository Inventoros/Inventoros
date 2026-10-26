<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAudit;
use App\Models\User;
use App\Services\StockAuditService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class E2EStockAuditHistorySeeder extends Seeder
{
    public function run(): void
    {
        $id = (string) env('E2E_AUDIT_HISTORY_ID');
        if (! Str::isUuid($id)) {
            throw new \InvalidArgumentException('E2E_AUDIT_HISTORY_ID must be a fresh UUID.');
        }
        $audit = DB::transaction(function () use ($id): StockAudit {
            $organization = Organization::create(['name' => 'Count history '.$id, 'email' => $id.'@history.test',
                'currency' => 'USD', 'timezone' => 'UTC']);
            $actor = User::create(['name' => 'Count administrator', 'email' => $id.'@history.test',
                'password' => Hash::make('E2EHistoryPassword123!'), 'role' => 'admin',
                'organization_id' => $organization->id, 'email_verified_at' => now()]);
            $product = Product::create(['organization_id' => $organization->id, 'sku' => 'HISTORY-'.$id,
                'name' => 'History fixture product', 'stock' => 5, 'price' => 10, 'currency' => 'USD',
                'min_stock' => 0, 'is_active' => true]);
            $audit = StockAudit::create(['organization_id' => $actor->organization_id, 'audit_number' => 'E2E-HISTORY-'.$id,
                'name' => 'Count history browser fixture', 'audit_type' => 'spot', 'status' => 'in_progress', 'created_by' => $actor->id]);
            $item = $audit->items()->create(['product_id' => $product->id, 'system_quantity' => $product->stock, 'status' => 'pending']);
            app(StockAuditService::class)->recordCount($audit, $item, $actor, 3, 'First pass');

            return $audit;
        }, 3);
        $this->command->line('AUDIT_ID='.$audit->id);
    }
}
