<?php

// Presentation fixture only. Run after ScreenshotSeeder and
// ScreenshotBrandingSeeder in the disposable dashboard-design.sqlite database.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! app()->environment(['local', 'testing']) || config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'dashboard-design.sqlite') {
    throw new RuntimeException('This fixture requires the disposable dashboard-design.sqlite database.');
}

$org = App\Models\Auth\Organization::where('name', 'Northwind Supply Co.')->sole();
$locations = App\Models\Inventory\ProductLocation::withoutGlobalScopes()->where('organization_id', $org->id)->pluck('id', 'code');

foreach ([
    'SKU-10001' => [0, 20, 'WH-A'],
    'SKU-10010' => [125, 500, 'RD-1'],
    'SKU-10014' => [3, 10, 'WH-B'],
] as $sku => [$stock, $minimum, $location]) {
    App\Models\Inventory\Product::withoutGlobalScopes()
        ->where('organization_id', $org->id)->where('sku', $sku)->sole()
        ->forceFill(['stock' => $stock, 'min_stock' => $minimum, 'location_id' => $locations[$location]])->saveQuietly();
}

// Export raw fixture records so expected totals can be calculated independently
// of the dashboard queries and selectors.
$records = [
    'products' => Illuminate\Support\Facades\DB::table('products')->where('organization_id', $org->id)
        ->whereNull('deleted_at')->get(['id', 'sku', 'name', 'stock', 'min_stock', 'price', 'currency', 'is_active', 'category_id']),
    'orders' => Illuminate\Support\Facades\DB::table('orders')->where('organization_id', $org->id)
        ->whereNull('deleted_at')->get(['id', 'status', 'total', 'currency', 'order_date']),
];
file_put_contents(__DIR__.'/fixture-records.json', json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "Three synthetic shortage records prepared.\n";
