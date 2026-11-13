<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Run after ScreenshotSeeder, only for the README screenshots.
 *
 * The screenshots are the first thing people see, so the records
 * E2ETestSeeder creates get the names of a demo company instead of
 * "E2E Test ...". Sign in afterwards with SCREENSHOT_EMAIL (the screenshots
 * workflow sets E2E_TEST_EMAIL to it). The E2E suite never runs this, so its
 * fixtures keep their names.
 */
class ScreenshotBrandingSeeder extends Seeder
{
    public const DEMO_COMPANY = 'Northwind Supply Co.';

    public const SCREENSHOT_EMAIL = 'alex@northwind-supply.example';

    public function run(): void
    {
        $organization = Organization::where('name', E2ETestSeeder::TEST_ORG_NAME)->firstOrFail();
        $orgId = $organization->id;

        $organization->forceFill(['name' => self::DEMO_COMPANY])->save();
        User::where('email', E2ETestSeeder::TEST_EMAIL)->firstOrFail()
            ->forceFill(['name' => 'Alex Morgan', 'email' => self::SCREENSHOT_EMAIL])->save();
        User::where('email', E2ETestSeeder::STAFF_EMAIL)->update(['name' => 'Sam Rivera']);

        Product::where('organization_id', $orgId)->where('sku', E2ETestSeeder::TEST_PRODUCT_SKU)
            ->update([
                'name' => 'Thermal Shipping Labels 4x6',
                'sku' => 'PKG-LBL-46',
                'category_id' => ProductCategory::where('organization_id', $orgId)->where('name', 'Packaging')->value('id'),
            ]);
        Supplier::where('organization_id', $orgId)->where('code', 'E2E-SUP')
            ->update(['name' => 'Harbor Packaging Co.', 'code' => 'HARBOR']);
        Order::where('organization_id', $orgId)->where('order_number', E2ETestSeeder::STAFF_ORDER_NUMBER)
            ->update(['order_number' => 'ORD-50011', 'customer_name' => 'Ridgeway Outfitters']);
        PurchaseOrder::where('organization_id', $orgId)->where('po_number', E2ETestSeeder::STAFF_PO_NUMBER)
            ->update(['po_number' => 'PO-60007']);

        $this->command?->info('Screenshot data renamed to '.self::DEMO_COMPANY.'.');
    }
}
