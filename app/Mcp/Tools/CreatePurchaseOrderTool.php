<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Mcp\Support\UntrustedText;
use App\Services\ExchangeRates\PurchaseOrderExchangeRates;
use App\Services\PurchaseOrderService;
use App\Support\VariantLineValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class CreatePurchaseOrderTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'create_purchase_order';

    protected string $description = 'Create a draft purchase order for a supplier. The PO is created in "draft" status and does NOT affect stock until it is later sent and received. Always confirm supplier and item list before invoking.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'supplier_id' => $schema->integer()->required()->description('Supplier id within the caller\'s organization.'),
            'order_date' => $schema->string()->required()->description('ISO date the PO is dated.'),
            'expected_date' => $schema->string()->description('ISO date items are expected.'),
            'currency' => $schema->string()->required()->description('ISO 4217 currency code.'),
            'shipping' => $schema->number()->description('Shipping cost (default 0).'),
            'tax' => $schema->number()->description('PO-level tax amount (default 0), for a PO whose lines have no tax_rate. Refused when any line has a tax_rate.'),
            'notes' => $schema->string()->description('Internal notes.'),
            'exchange_rate' => $schema->string()->description('Only for a currency other than the organization\'s, and only when the user gives one: the rate as a decimal string (1 PO currency = this many organization currency). Needs override_purchase_order_exchange_rate. Leave it out to use the organization\'s exchange rate provider.'),
            'items' => $schema->array()->required()->description('Line items: [{product_id, product_variant_id?, quantity, unit_cost, supplier_sku?, tax_rate?, tax?}]. product_variant_id is optional; when given it must be a variant of that product, and receiving the line credits the variant\'s stock. unit_cost is net (before tax). tax_rate is a percent (0-100); the server computes the line tax from it and ignores tax for that line. Without tax_rate, tax is a line tax amount kept as sent. No rate is taken from the product unless you send it.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // Agents copy tool output into write calls; never store the envelope.
        UntrustedText::stripArguments($request);

        $this->authorize(['create_purchase_orders']);

        $orgId = $this->organizationId();

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('organization_id', $orgId)],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'currency' => ['required', 'string', 'max:3'],
            'shipping' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'tax' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('organization_id', $orgId)],
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'items.*.supplier_sku' => ['nullable', 'string', 'max:255'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:100'],
            'items.*.tax' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            ...PurchaseOrderExchangeRates::rules(),
        ]);

        // The variant is optional here (as on the REST API), but a variant
        // that is sent must belong to the line's product.
        $variantErrors = VariantLineValidator::errors($validated['items'], $orgId, requireVariant: false);
        if ($variantErrors !== []) {
            throw ValidationException::withMessages(collect($variantErrors)
                ->mapWithKeys(fn (string $message, $index) => ["items.{$index}.product_variant_id" => $message])
                ->all());
        }

        // The same create as the web form, REST and GraphQL: line tax,
        // totals, PO numbering and the exchange rate snapshot.
        $po = app(PurchaseOrderService::class)
            ->create($orgId, $this->user(), $validated)
            ->load(['supplier', 'items']);

        return Response::json([
            'message' => 'Draft purchase order created.',
            'purchase_order' => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'status' => $po->status,
                'supplier' => $po->supplier?->only(['id', 'name']),
                'subtotal' => $po->subtotal,
                'tax' => $po->tax,
                'total' => $po->total,
                'currency' => $po->currency,
                'tax_breakdown' => $po->taxBreakdown(),
                'exchange_rate' => $po->exchangeRateSummary(),
                'item_count' => $po->items->count(),
            ],
        ]);
    }
}
