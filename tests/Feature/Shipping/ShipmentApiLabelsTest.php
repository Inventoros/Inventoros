<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\ShipmentStatus;
use App\Models\Shipping\Shipment;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REST label flow: create an EasyPost shipment, quote rates, buy a label,
 * void it. Same ShipmentService as the order page; EasyPost is faked.
 */
class ShipmentApiLabelsTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    private const RATE = 'rate_3f1b2c4d5e6f4a7b8c9d0e1f2a3b4c5d';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();
        $this->configureEasyPost();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    private function pending(): Shipment
    {
        return app(ShipmentService::class)->create($this->makeRateableOrder(), ['carrier' => 'easypost'], $this->admin);
    }

    private function fakeEasyPost(string $refund = 'shipment_refund_submitted'): void
    {
        Http::fake([
            'api.easypost.com/v2/shipments' => Http::response($this->easyPostFixture('shipment_with_rates'), 201),
            'api.easypost.com/v2/shipments/*/buy' => Http::response($this->easyPostFixture('shipment_bought')),
            'api.easypost.com/v2/shipments/*/refund' => Http::response($this->easyPostFixture($refund)),
            'easypost-files.s3.us-west-2.amazonaws.com/*' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);
    }

    public function test_rest_can_create_a_carrier_shipment_quote_buy_and_void(): void
    {
        $this->fakeEasyPost();
        Sanctum::actingAs($this->admin, ['*']);
        $order = $this->makeRateableOrder();

        $id = $this->postJson("/api/v1/orders/{$order->id}/shipments", ['carrier' => 'easypost', 'weight_oz' => 16])
            ->assertCreated()
            ->assertJsonPath('data.carrier', 'easypost')
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        $this->postJson("/api/v1/shipments/{$id}/rates")
            ->assertOk()
            ->assertJsonPath('data.0.id', self::RATE)
            ->assertJsonPath('data.0.carrier', 'USPS')
            ->assertJsonPath('data.0.amount', '7.58')
            ->assertJsonMissingPath('data.0.carrier_shipment_id');

        $this->postJson("/api/v1/shipments/{$id}/buy-label", ['rate_id' => self::RATE])
            ->assertOk()
            ->assertJsonPath('data.status', 'label_created')
            ->assertJsonPath('data.tracking_number', '9400100208271109836487')
            ->assertJsonPath('data.has_label', true)
            ->assertJsonMissingPath('data.carrier_response');

        $this->postJson("/api/v1/shipments/{$id}/void-label")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_buying_an_unquoted_rate_is_a_422_without_calling_easypost(): void
    {
        $shipment = $this->pending();
        Http::fake();
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/v1/shipments/{$shipment->id}/buy-label", ['rate_id' => 'rate_nope'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'shipping_error');

        $this->postJson("/api/v1/shipments/{$shipment->id}/buy-label", [])->assertStatus(422)->assertJsonValidationErrors('rate_id');

        Http::assertNothingSent();
    }

    public function test_carrier_errors_are_422_with_the_readable_message(): void
    {
        $shipment = $this->pending();
        Http::fake(['api.easypost.com/v2/shipments' => Http::response($this->easyPostFixture('error_address'), 422)]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/v1/shipments/{$shipment->id}/rates")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unable to verify address. Address not found.')
            ->assertJsonPath('error', 'shipping_error');
    }

    public function test_rates_for_a_manual_shipment_are_refused(): void
    {
        $shipment = app(ShipmentService::class)->create($this->makeOrder(), ['carrier' => 'manual'], $this->admin);
        Http::fake();
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/v1/shipments/{$shipment->id}/rates")->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_refused_void_leaves_the_label(): void
    {
        $this->fakeEasyPost('shipment_refund_rejected');
        $shipment = $this->pending();
        $service = app(ShipmentService::class);
        $service->fetchRates($shipment);
        $service->buyLabel($shipment->fresh(), self::RATE);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/v1/shipments/{$shipment->id}/void-label")->assertStatus(422);

        $this->assertSame(ShipmentStatus::LABEL_CREATED, $shipment->fresh()->status);
    }

    public function test_void_label_is_refused_once_shipped(): void
    {
        $shipment = app(ShipmentService::class)->create($this->makeOrder(), ['carrier' => 'manual'], $this->admin);
        app(ShipmentService::class)->markShipped($shipment, $this->admin);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/v1/shipments/{$shipment->id}/void-label")->assertStatus(422);
        $this->assertSame(ShipmentStatus::SHIPPED, $shipment->fresh()->status);
    }

    public function test_label_endpoints_need_create_shipments_on_role_and_token(): void
    {
        $shipment = $this->pending();
        Http::fake();

        Sanctum::actingAs($this->memberWith(['view_orders', 'view_shipments']), ['*']);
        foreach (['rates', 'buy-label', 'void-label'] as $action) {
            $this->postJson("/api/v1/shipments/{$shipment->id}/{$action}", ['rate_id' => self::RATE])->assertForbidden();
        }

        $token = $this->admin->createToken('ro', ['view_shipments'])->plainTextToken;
        foreach (['rates', 'buy-label', 'void-label'] as $action) {
            $this->withToken($token)->postJson("/api/v1/shipments/{$shipment->id}/{$action}", ['rate_id' => self::RATE])->assertForbidden();
        }

        Http::assertNothingSent();
    }

    public function test_label_endpoints_hide_other_organizations_shipments(): void
    {
        $shipment = $this->pending();
        $other = \App\Models\Auth\Organization::create(['name' => 'O', 'email' => 'o@o.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $stranger = \App\Models\User::create([
            'name' => 'S', 'email' => 's@o.test', 'password' => bcrypt('x'), 'organization_id' => $other->id, 'role' => 'admin',
        ]);
        Http::fake();
        Sanctum::actingAs($stranger, ['*']);

        foreach (['rates', 'buy-label', 'void-label'] as $action) {
            $this->postJson("/api/v1/shipments/{$shipment->id}/{$action}", ['rate_id' => self::RATE])->assertNotFound();
        }

        Http::assertNothingSent();
    }
}
