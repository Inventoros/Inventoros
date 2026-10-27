<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class E2EVariantReplenishmentSeeder extends Seeder
{
    public function run(): void
    {
        $id = (string) env('E2E_REPLENISHMENT_ID');
        if (! Str::isUuid($id)) {
            throw new \InvalidArgumentException('E2E_REPLENISHMENT_ID must be a fresh UUID.');
        }
        $fixture = DB::transaction(function () use ($id): array {
            $org = Organization::create(['name' => 'Replenishment '.$id, 'email' => $id.'@replenishment.test', 'currency' => 'USD', 'timezone' => 'UTC']);
            $actor = User::create(['name' => 'Replenishment administrator', 'email' => $id.'@replenishment.test', 'password' => Hash::make('E2EReplenishment123!'),
                'organization_id' => $org->id, 'role' => 'admin', 'email_verified_at' => now()]);
            $warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Replenishment warehouse', 'code' => 'E2E-REPLENISH', 'is_active' => true]);
            $location = ProductLocation::create(['organization_id' => $org->id, 'warehouse_id' => $warehouse->id, 'name' => 'Receiving shelf', 'code' => 'RECEIVE', 'is_active' => true]);
            $product = Product::create(['organization_id' => $org->id, 'name' => 'Replenishment shirt', 'sku' => 'RP-'.$id, 'price' => 10,
                'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'has_variants' => true, 'is_active' => true]);
            $variant = ProductVariant::create(['organization_id' => $org->id, 'product_id' => $product->id, 'sku' => 'RP-RED-'.$id,
                'title' => 'Red medium', 'option_values' => [], 'stock' => 0, 'is_active' => true]);
            StockAdjustment::adjustVariant($variant, 2, 'manual', 'Browser fixture stock', actor: $actor, locationId: $location->id);
            $supplier = Supplier::create(['organization_id' => $org->id, 'name' => 'Replenishment supplier', 'currency' => 'USD', 'is_active' => true]);

            return ['variant' => $variant->id, 'warehouse' => $warehouse->id, 'location' => $location->id, 'supplier' => $supplier->id];
        });
        $this->command->line('REPLENISHMENT_FIXTURE='.json_encode($fixture));
    }
}
