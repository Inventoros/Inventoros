<?php

declare(strict_types=1);

namespace App\Imports;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderService;
use App\Support\SpreadsheetSafety;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Imports sales orders (e.g. when migrating from another system).
 *
 * File format: one row per order LINE. Rows sharing an external_reference
 * form one order; they need not be adjacent. Order-level columns (order_date,
 * status, customer_name, customer_email, order_tax, order_shipping, currency,
 * shipped_at, delivered_at, notes) are read from the first non-blank value
 * among the order's rows, so they may be repeated on every line or given only
 * once. Line columns: product_sku and/or variant_sku, quantity, unit_price
 * (blank = the variant's or product's current price), line_tax. The
 * line-items order export uses the same column names, so its file can be
 * imported elsewhere once external_reference is filled in.
 *
 * Processing:
 *  1. Every row is validated and every SKU resolved BEFORE any order is
 *     created. Problems are reported per row.
 *  2. Each order is all-or-nothing: if any of its rows is invalid, or
 *     creation fails (e.g. insufficient stock), none of its lines are kept
 *     and nothing it touched (customer, stock) is committed.
 *  3. An external_reference already present on one of this organization's
 *     orders is skipped with a warning, so re-importing a file never
 *     duplicates orders.
 *  4. Orders are created through OrderService, so stock is decremented, bins
 *     consumed, serials allocated and variant stock used exactly like an
 *     order entered by hand; insufficient stock fails that order.
 *
 * Historical mode records the orders and their lines WITHOUT touching
 * inventory (no availability check, no decrement, no ledger rows, no serial
 * allocation). Use it for past orders whose goods left long ago, where
 * current stock already reflects them. Cancelled orders never move stock in
 * either mode.
 *
 * Customers are matched to an existing customer of this organization by
 * email (case-insensitive); otherwise a customer record is created (named by
 * customer_name, or the email when no name is given). Orders with neither a
 * name nor an email are recorded without a customer record.
 */
final class OrdersImport implements ToCollection, WithHeadingRow
{
    private const ORDER_FIELDS = [
        'order_date', 'status', 'customer_name', 'customer_email', 'order_tax',
        'order_shipping', 'currency', 'shipped_at', 'delivered_at', 'notes',
    ];

    private int $organizationId;

    private int $imported = 0;

    private int $skipped = 0;

    private int $failed = 0;

    /** @var array<int, array{row: int, errors: array<int, string>}> */
    private array $errors = [];

    /** @var array<int, array{row: int, warnings: array<int, string>}> */
    private array $warnings = [];

    /** @var array<string, Product|null> */
    private array $productsBySku = [];

    /** @var array<string, ProductVariant|null> */
    private array $variantsBySku = [];

    /** @var array<string, int> Customers created or matched in this run, by lowercased email or name. */
    private array $customerIds = [];

    public function __construct(
        private readonly User $importer,
        private readonly bool $historical = false,
    ) {
        $this->organizationId = (int) $importer->organization_id;
    }

    /**
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        /** @var array<string, array<int, array{row: int, data: array<string, mixed>}>> $groups */
        $groups = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // header row + 0-based index
            $data = $this->normalise($row->toArray());

            if ($this->isBlank($data)) {
                continue;
            }

            $reference = $data['external_reference'] ?? null;
            if ($reference === null) {
                $this->error($rowNumber, 'The external reference field is required.');

                continue;
            }

            $groups[$reference][] = ['row' => $rowNumber, 'data' => $data];
        }

        $existing = $this->existingReferences(array_map('strval', array_keys($groups)));

        // Phase 1: validate every order before creating any.
        $valid = [];
        foreach ($groups as $reference => $lines) {
            $reference = (string) $reference;

            if (isset($existing[$reference])) {
                $this->skipped++;
                $this->warn($lines[0]['row'], "Order '{$reference}' already exists; skipped.");

                continue;
            }

            $order = $this->validateOrder($reference, $lines);
            if ($order === null) {
                $this->failed++;

                continue;
            }

            $valid[] = $order;
        }

        // Phase 2: create each valid order in its own transaction.
        foreach ($valid as $order) {
            $this->createOrder($order);
        }
    }

    /**
     * @return array{imported: int, skipped: int, failed: int, errors: array<int, mixed>, warnings: array<int, mixed>}
     */
    public function getStats(): array
    {
        usort($this->errors, fn ($a, $b) => $a['row'] <=> $b['row']);
        usort($this->warnings, fn ($a, $b) => $a['row'] <=> $b['row']);

        return [
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Validate one order's rows and resolve its SKUs.
     *
     * @param  array<int, array{row: int, data: array<string, mixed>}>  $lines
     * @return array<string, mixed>|null The order payload, or null when invalid.
     */
    private function validateOrder(string $reference, array $lines): ?array
    {
        $header = [];
        foreach (self::ORDER_FIELDS as $field) {
            foreach ($lines as $line) {
                if (($line['data'][$field] ?? null) !== null) {
                    $header[$field] = $line['data'][$field];

                    break;
                }
            }
        }

        $ok = true;
        $items = [];

        foreach ($lines as $index => $line) {
            // Order-level rules are checked on the first row with the resolved
            // header, so a missing order_date is reported once.
            $data = $index === 0 ? array_merge($line['data'], $header) : $line['data'];
            $messages = $this->validateRow($data, $index === 0);

            if ($messages === []) {
                [$item, $message] = $this->resolveLine($data);
                if ($message !== null) {
                    $messages[] = $message;
                } else {
                    $items[] = $item;
                }
            }

            if ($messages !== []) {
                $ok = false;
                foreach ($messages as $message) {
                    $this->error($line['row'], $message);
                }
            }
        }

        if (! $ok) {
            return null;
        }

        return [
            'reference' => $reference,
            'row' => $lines[0]['row'],
            'header' => $header,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function validateRow(array $data, bool $withOrderRules): array
    {
        $rules = [
            'product_sku' => 'nullable|string|max:255|required_without:variant_sku',
            'variant_sku' => 'nullable|string|max:255',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            'line_tax' => 'nullable|numeric|min:0',
        ];

        if ($withOrderRules) {
            $rules += [
                'external_reference' => 'required|string|max:255',
                'order_date' => 'required|date',
                'status' => ['nullable', Rule::in(OrderStatus::values())],
                'customer_name' => 'nullable|string|max:255',
                'customer_email' => 'nullable|email|max:255',
                'order_tax' => 'nullable|numeric|min:0',
                'order_shipping' => 'nullable|numeric|min:0',
                'currency' => ['nullable', 'string', Rule::in(array_keys(config('currencies.supported', [])))],
                'shipped_at' => 'nullable|date',
                'delivered_at' => 'nullable|date',
                'notes' => 'nullable|string|max:65535',
            ];
        }

        return Validator::make($data, $rules, [
            'product_sku.required_without' => 'Each line needs a product_sku or a variant_sku.',
        ])->errors()->all();
    }

    /**
     * Resolve a line's SKUs to this organization's product (and variant).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function resolveLine(array $data): array
    {
        $productSku = $data['product_sku'] ?? null;
        $variantSku = $data['variant_sku'] ?? null;
        $variant = null;

        if ($variantSku !== null) {
            $variant = $this->variant($variantSku);
            if ($variant === null) {
                return [null, "Unknown variant SKU '{$variantSku}'."];
            }
            $product = $variant->product()->withoutGlobalScope(OrganizationScope::class)->first();
            if ($product === null || $product->organization_id !== $this->organizationId) {
                return [null, "Unknown variant SKU '{$variantSku}'."];
            }
            if ($productSku !== null && $product->sku !== $productSku) {
                return [null, "Variant SKU '{$variantSku}' does not belong to product SKU '{$productSku}'."];
            }
        } else {
            $product = $this->product((string) $productSku);

            if ($product === null) {
                // A variant SKU in the product_sku column is accepted too.
                $variant = $this->variant((string) $productSku);
                $product = $variant?->product()->withoutGlobalScope(OrganizationScope::class)->first();

                if ($product === null || $product->organization_id !== $this->organizationId) {
                    return [null, "Unknown SKU '{$productSku}'."];
                }
            } elseif ($product->has_variants) {
                return [null, "Product '{$productSku}' is sold by variant; add a variant_sku."];
            }
        }

        $item = [
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'quantity' => (int) $data['quantity'],
        ];
        if (($data['unit_price'] ?? null) !== null) {
            $item['unit_price'] = round((float) $data['unit_price'], 2);
        }
        if (($data['line_tax'] ?? null) !== null) {
            $item['tax'] = round((float) $data['line_tax'], 2);
        }

        return [$item, null];
    }

    /**
     * Create one validated order (and its customer, if new) atomically.
     *
     * @param  array<string, mixed>  $order
     */
    private function createOrder(array $order): void
    {
        $header = $order['header'];
        $status = $header['status'] ?? OrderStatus::PENDING->value;
        $adjustStock = ! $this->historical && $status !== OrderStatus::CANCELLED->value;

        try {
            DB::transaction(function () use ($order, $header, $status, $adjustStock) {
                // Re-check under the transaction: another import may have
                // created this reference since phase 1.
                if ($this->existingReferences([$order['reference']]) !== []) {
                    $this->skipped++;
                    $this->warn($order['row'], "Order '{$order['reference']}' already exists; skipped.");

                    return;
                }

                [$customerId, $customerName, $customerEmail] = $this->resolveCustomer(
                    $header['customer_name'] ?? null,
                    $header['customer_email'] ?? null,
                );

                app(OrderService::class)->create([
                    'external_reference' => $order['reference'],
                    'customer_id' => $customerId,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'status' => $status,
                    'order_date' => $header['order_date'],
                    'shipped_at' => $header['shipped_at'] ?? null,
                    'delivered_at' => $header['delivered_at'] ?? null,
                    'tax' => $header['order_tax'] ?? 0,
                    'shipping' => $header['order_shipping'] ?? 0,
                    'currency' => $header['currency'] ?? $this->organizationCurrency(),
                    'notes' => $header['notes'] ?? null,
                    'warehouse_id' => $this->defaultWarehouseId(),
                    'items' => $order['items'],
                ], $this->importer, 'import', $adjustStock);

                $this->imported++;
            });
        } catch (Throwable $e) {
            $this->failed++;
            // The per-run customer cache may point at a customer that the
            // rollback just removed.
            $this->customerIds = [];

            $message = $e instanceof \Illuminate\Database\QueryException
                ? 'The order could not be saved due to a database error.'
                : $e->getMessage();

            if ($e instanceof \Illuminate\Database\QueryException) {
                Log::error('Order import row failed with database error', [
                    'organization_id' => $this->organizationId,
                    'external_reference' => $order['reference'],
                    'error' => $e->getMessage(),
                ]);
            }

            $this->error($order['row'], "Order '{$order['reference']}' was not imported: {$message}");
        }
    }

    /**
     * Match the customer by email, else create one. Returns the customer id
     * and the name/email to snapshot on the order.
     *
     * @return array{0: int|null, 1: string, 2: string|null}
     */
    private function resolveCustomer(?string $name, ?string $email): array
    {
        if ($name === null && $email === null) {
            return [null, 'Imported customer', null];
        }

        $key = $email !== null ? 'e:'.mb_strtolower($email) : 'n:'.mb_strtolower($name);
        $customers = Customer::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $this->organizationId);

        $customer = null;
        if (isset($this->customerIds[$key])) {
            $customer = (clone $customers)->find($this->customerIds[$key]);
        }
        if ($customer === null && $email !== null) {
            $customer = (clone $customers)->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
        }
        if ($customer === null && $email === null) {
            $customer = (clone $customers)->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $name)])->whereNull('email')->first();
        }

        if ($customer === null) {
            $customer = Customer::create([
                'organization_id' => $this->organizationId,
                'name' => mb_substr((string) SpreadsheetSafety::sanitiseImport($name ?? $email), 0, 255),
                'email' => $email,
                'is_active' => true,
            ]);
        }

        $this->customerIds[$key] = $customer->id;

        return [$customer->id, $name ?? $customer->name, $email ?? $customer->email];
    }

    private function product(string $sku): ?Product
    {
        if (! array_key_exists($sku, $this->productsBySku)) {
            $this->productsBySku[$sku] = Product::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $this->organizationId)
                ->where('sku', $sku)
                ->first();
        }

        return $this->productsBySku[$sku];
    }

    private function variant(string $sku): ?ProductVariant
    {
        if (! array_key_exists($sku, $this->variantsBySku)) {
            $this->variantsBySku[$sku] = ProductVariant::query()
                ->where('organization_id', $this->organizationId)
                ->where('sku', $sku)
                ->first();
        }

        return $this->variantsBySku[$sku];
    }

    private function organizationCurrency(): string
    {
        return $this->importer->organization?->currency
            ?? config('currencies.default', 'USD');
    }

    private function defaultWarehouseId(): ?int
    {
        $id = Warehouse::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $this->organizationId)
            ->where('is_default', true)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The given references that already exist on this organization's orders,
     * including soft-deleted ones (they still hold the unique reference).
     *
     * @param  array<int, string>  $references
     * @return array<string, true>
     */
    private function existingReferences(array $references): array
    {
        $found = [];

        foreach (array_chunk($references, 500) as $chunk) {
            Order::withoutGlobalScopes()
                ->where('organization_id', $this->organizationId)
                ->whereIn('external_reference', $chunk)
                ->pluck('external_reference')
                ->each(function ($reference) use (&$found) {
                    $found[(string) $reference] = true;
                });
        }

        return $found;
    }

    /**
     * Trim cells, turn blanks into null, stringify identifiers, convert
     * spreadsheet serial dates, and strip formula triggers from free text.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalise(array $row): array
    {
        $data = [];

        foreach ($row as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '' || $value === null) {
                $value = null;
            } elseif (is_float($value) || is_int($value)) {
                if (in_array($key, ['external_reference', 'product_sku', 'variant_sku'], true)) {
                    $value = (string) $value;
                }
            }

            $data[(string) $key] = $value;
        }

        foreach (['order_date', 'shipped_at', 'delivered_at'] as $key) {
            $data[$key] = $this->parseDate($data[$key] ?? null);
        }

        foreach (['customer_name', 'notes'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = SpreadsheetSafety::sanitiseImport((string) $data[$key]);
            }
        }

        if (isset($data['status']) && is_string($data['status'])) {
            $data['status'] = strtolower($data['status']);
        }
        if (isset($data['currency']) && is_string($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        return $data;
    }

    /**
     * Accept ISO-ish date strings and spreadsheet serial numbers. Anything
     * unparseable is passed through for the `date` rule to reject.
     */
    private function parseDate(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            try {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->format('Y-m-d H:i:s');
            } catch (Throwable) {
                return (string) $value;
            }
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isBlank(array $data): bool
    {
        foreach ($data as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }

    private function error(int $row, string $message): void
    {
        foreach ($this->errors as $i => $entry) {
            if ($entry['row'] === $row) {
                $this->errors[$i]['errors'][] = $message;

                return;
            }
        }

        $this->errors[] = ['row' => $row, 'errors' => [$message]];
    }

    private function warn(int $row, string $message): void
    {
        foreach ($this->warnings as $i => $entry) {
            if ($entry['row'] === $row) {
                $this->warnings[$i]['warnings'][] = $message;

                return;
            }
        }

        $this->warnings[] = ['row' => $row, 'warnings' => [$message]];
    }
}
