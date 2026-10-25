<?php

declare(strict_types=1);

namespace Inventoros\Plugins\TaxBilling;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Order\Order;
use App\Services\SettingsService;
use App\Services\WarehouseAccessService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class Controller extends BaseController
{
    public function save(Request $request): RedirectResponse
    {
        $profile = Profile::validate($request->all());
        SettingsService::set(Profile::KEY, json_encode($profile, JSON_THROW_ON_ERROR));

        return back()->with('success', 'Tax billing profile saved.');
    }

    public static function order(Request $request): Order
    {
        $id = (string) $request->route('orderId');
        abort_unless(ctype_digit($id), 404);
        $order = Order::where('organization_id', $request->user()->organization_id)->whereKey($id)->firstOrFail();
        app(WarehouseAccessService::class)->authorizeWarehouse($request->user(), $order->warehouse_id);

        return $order;
    }

    public function edit(Request $request): InertiaResponse
    {
        $order = self::order($request)->load('items', 'customer');
        $snapshot = $order->metadata[Invoices::SNAPSHOT] ?? null;

        return Inertia::render('Plugin::tax-billing/Invoice', [
            'order' => $order->only(['id', 'order_number', 'currency', 'subtotal', 'tax', 'shipping', 'discount_amount', 'total']),
            'items' => $order->items->map(fn ($item) => $item->only(['id', 'product_name', 'sku', 'quantity', 'unit_price']))->values(),
            'supplyCountry' => preg_match('/^[A-Z]{2}$/', $order->customer?->billing_country ?? '') ? $order->customer->billing_country : Profile::get((int) $order->organization_id)['country'],
            'profile' => Profile::get((int) $order->organization_id),
            'regionCode' => $order->customer?->metadata['tax_billing']['region_code'] ?? '',
            'snapshot' => $snapshot,
            'canIssue' => $request->user()->hasPermission('tax-billing.issue'),
            'issueUrl' => route('plg.tax-billing.issue', ['orderId' => $order->id]),
            'downloadUrl' => route('plg.tax-billing.download', ['orderId' => $order->id]),
        ]);
    }

    public function issue(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'confirmed' => ['accepted'], 'region_code' => ['nullable', 'string', 'max:80'],
            'supply_country' => ['required', 'regex:/^[A-Z]{2}$/'],
            'items' => ['required', 'array'], 'items.*.hsn_code' => ['nullable', 'string', 'max:20'],
            'items.*.unit' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
        ]);
        $order = self::order($request);
        app(Invoices::class)->issue($order, $input, (int) $request->user()->id);

        return back()->with('success', 'Tax invoice issued. Its details are retained for future downloads.');
    }

    public function download(Request $request): Response
    {
        $order = self::order($request);
        $snapshot = $order->metadata[Invoices::SNAPSHOT] ?? null;
        abort_unless(is_array($snapshot), 404);

        return Pdf::loadView('tax-billing::invoice', ['invoice' => $snapshot])->download($snapshot['number'].'.pdf');
    }
}
