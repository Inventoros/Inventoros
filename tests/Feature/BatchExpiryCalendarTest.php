<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Resources\ProductBatchResource;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\User;
use App\Support\OrganizationCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BatchExpiryCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_today_stays_valid_until_the_organization_day_ends_in_model_api_and_page_props(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 01:00:00', 'UTC'));
        $organization = Organization::create([
            'name' => 'Toronto', 'email' => 'toronto@expiry.test', 'timezone' => 'America/Toronto',
        ]);
        $product = Product::create([
            'organization_id' => $organization->id, 'name' => 'Milk', 'sku' => 'MILK', 'price' => 5,
        ]);
        $user = new User(['organization_id' => $organization->id]);
        $user->setRelation('organization', $organization);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        foreach (['2026-10-23' => true, '2026-10-24' => false, '2026-10-25' => false, '' => false] as $expiry => $expired) {
            $batch = ProductBatch::create([
                'organization_id' => $organization->id, 'product_id' => $product->id,
                'batch_number' => 'LOT-'.$expiry, 'quantity' => 2, 'expiry_date' => $expiry ?: null,
            ]);
            $this->assertSame($expired, $batch->isExpired());
            $this->assertSame($expired, (new ProductBatchResource($batch))->toArray($request)['is_expired']);
        }
        $this->assertSame('2026-10-24', HandleInertiaRequests::regional($organization)['today']);

        Carbon::setTestNow(Carbon::parse('2026-10-25 04:00:00', 'UTC'));
        $this->assertTrue(ProductBatch::where('batch_number', 'LOT-2026-10-24')->firstOrFail()->isExpired());
        $this->assertSame('2026-10-25', OrganizationCalendar::today($organization)->toDateString());
    }

    public function test_legacy_missing_or_invalid_timezones_fall_back_to_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 01:00:00', 'UTC'));
        foreach ([null, '', 'Not/AZone'] as $timezone) {
            $this->assertSame('2026-10-25', OrganizationCalendar::today(new Organization(['timezone' => $timezone]))->toDateString());
        }
        $this->assertSame('2026-10-25', OrganizationCalendar::today(null)->toDateString());
    }
}
