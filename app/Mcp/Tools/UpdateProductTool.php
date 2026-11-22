<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\BarcodeType;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Mcp\Support\UntrustedText;
use App\Models\Inventory\Product;
use App\Rules\AccessibleProductLocation;
use App\Rules\BarcodeMatchesType;
use App\Services\ProductService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * Edit a product's catalog fields (#462). Only the fields sent are changed,
 * like a REST PATCH. On-hand stock is not a product field: it moves only
 * through the ledger, so `adjust_stock` is the tool for it. Variants,
 * options, images and supplier links stay in the web UI and REST API.
 */
#[IsDestructive]
#[IsIdempotent]
class UpdateProductTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'update_product';

    protected string $description = 'Change fields of an existing product (prices, tax rate, names, reorder levels, barcode, category, location, active flag). Only the fields you pass change. Stock cannot be set here: use adjust_stock. Confirm the changes with the user before invoking, and for bulk edits show the list of products and new values first.';

    /** The editable fields; anything else is rejected. */
    private const FIELDS = [
        'sku', 'name', 'description', 'price', 'selling_price', 'purchase_price', 'tax_rate', 'currency',
        'min_stock', 'max_stock', 'reorder_point', 'reorder_quantity', 'barcode', 'barcode_type', 'notes',
        'category_id', 'location_id', 'is_active', 'allow_backorder', 'release_date',
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Product id (from list_products, search_products or lookup_barcode).'),
            'sku' => $schema->string()->description('New SKU; must stay unique within the organization.'),
            'name' => $schema->string()->description('Product display name.'),
            'description' => $schema->string()->description('Long description. Null clears it.'),
            'price' => $schema->number()->description('Display/list price.'),
            'selling_price' => $schema->number()->description('Sell price.'),
            'purchase_price' => $schema->number()->description('Default purchase (buy) cost.'),
            'tax_rate' => $schema->number()->description('Tax rate in percent the product is sold at (0-100). Prices stay net (tax exclusive). Null clears it.'),
            'currency' => $schema->string()->description('ISO 4217 currency of the prices.'),
            'min_stock' => $schema->integer()->description('Reorder warning threshold.'),
            'max_stock' => $schema->integer()->description('Maximum desired stock.'),
            'reorder_point' => $schema->integer()->description('Stock level that triggers reordering.'),
            'reorder_quantity' => $schema->integer()->description('Units to reorder.'),
            'barcode' => $schema->string()->description('Barcode value; must match barcode_type when one is set.'),
            'barcode_type' => $schema->string()->enum(array_column(BarcodeType::cases(), 'value'))->description('Barcode symbology.'),
            'notes' => $schema->string()->description('Internal notes. Null clears them.'),
            'category_id' => $schema->integer()->description('Category id (list_categories). Null clears it.'),
            'location_id' => $schema->integer()->description('Default location id (list_locations). Null clears it.'),
            'is_active' => $schema->boolean()->description('Whether the product is active.'),
            'allow_backorder' => $schema->boolean()->description('Allow selling more than is on hand (when backorders are enabled).'),
            'release_date' => $schema->string()->description('Preorder release date (YYYY-MM-DD). Null clears it.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // Agents copy tool output into write calls; never store the envelope.
        UntrustedText::stripArguments($request);

        $this->authorize(['edit_products']);

        $orgId = $this->organizationId();

        $request->validate(['id' => ['required', 'integer']]);

        try {
            $product = Product::query()->forOrganization($orgId)->findOrFail((int) $request->get('id'));
        } catch (ModelNotFoundException) {
            return Response::error('Product not found in this organization.');
        }

        $unknown = array_diff(array_keys($request->all()), ['id', ...self::FIELDS]);
        if (in_array('stock', $unknown, true)) {
            return Response::error(ProductService::STOCK_EDIT_MESSAGE.' Use adjust_stock to change on-hand units.');
        }
        if ($unknown !== []) {
            return Response::error('These fields cannot be changed with update_product: '.implode(', ', $unknown).'. Variants, options, images and supplier links are edited in the web UI or the REST API.');
        }

        // As the REST edit: a barcode is checked against the type sent with
        // it, or else the product's current type.
        $barcodeType = array_key_exists('barcode_type', $request->all()) ? $request->get('barcode_type') : $product->barcode_type;

        $validated = $request->validate([
            'sku' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('products', 'sku')->where('organization_id', $orgId)->ignore($product->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'purchase_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:100'],
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'min_stock' => ['sometimes', 'required', 'integer', 'min:0'],
            'max_stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'reorder_point' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'reorder_quantity' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:255', new BarcodeMatchesType(is_string($barcodeType) ? $barcodeType : null)],
            'barcode_type' => ['sometimes', 'nullable', Rule::enum(BarcodeType::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_categories', 'id')->where('organization_id', $orgId)],
            'location_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_locations', 'id')->where('organization_id', $orgId), new AccessibleProductLocation($this->user(), $product)],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'allow_backorder' => ['sometimes', 'required', 'boolean'],
            'release_date' => ['sometimes', 'nullable', 'date'],
        ]);

        if ($validated === []) {
            return Response::error('Pass at least one field to change.');
        }

        if (isset($validated['currency'])) {
            $validated['currency'] = strtoupper($validated['currency']);
        }

        // Through the shared ProductService, like the web and REST edits: it
        // checks location access and announces the edit to plugins/webhooks.
        $product = app(ProductService::class)->update($product, $validated);

        $changed = array_values(array_diff(array_keys($product->getChanges()), ['updated_at']));

        return Response::json([
            'message' => $changed === [] ? 'No changes: the product already had these values.' : 'Product updated.',
            'changed' => $changed,
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'price' => $product->price,
                'selling_price' => $product->selling_price,
                'purchase_price' => $product->purchase_price,
                'tax_rate' => $product->tax_rate,
                'currency' => $product->currency,
                'min_stock' => $product->min_stock,
                'stock' => $product->stock,
                'is_active' => (bool) $product->is_active,
            ],
        ]);
    }
}
