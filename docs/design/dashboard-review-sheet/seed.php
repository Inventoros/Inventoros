<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing']) || config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'dashboard-design.sqlite') {
    throw new RuntimeException('This fixture requires the disposable dashboard-design.sqlite database.');
}

$org = App\Models\Auth\Organization::where('name', 'Northwind Supply Co.')->sole();
$stand = App\Models\Inventory\Product::withoutGlobalScopes()->where('organization_id', $org->id)->where('sku', 'SKU-10014')->sole();
$supplier = App\Models\Inventory\Supplier::withoutGlobalScopes()->where('organization_id', $org->id)->where('code', 'SUP-1001')->sole();
$stand->forceFill(['reorder_point' => 8, 'reorder_quantity' => 12])->saveQuietly();
$stand->suppliers()->sync([$supplier->id => ['is_primary' => true, 'minimum_order_quantity' => 20, 'cost_price' => 28, 'supplier_sku' => 'ALU-STAND-01']]);

$keyboard = App\Models\Inventory\Product::withoutGlobalScopes()->where('organization_id', $org->id)->where('sku', 'SKU-10001')->sole();
$keyboard->forceFill(['reorder_point' => 20, 'reorder_quantity' => 30])->saveQuietly();
$keyboard->suppliers()->detach();
echo "Review fixture: stand shortfall 7, supplier-constrained suggestion 20; keyboard has no supplier.\n";
