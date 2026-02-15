<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use Illuminate\Support\Str;

/**
 * Service for generating product SKUs based on configurable patterns.
 *
 * Supports various pattern variables including category codes,
 * product names, dates, random strings, and sequential numbers.
 */
final class SKUGeneratorService
{
    public const CATEGORY_CODE_LENGTH = 3;
    public const CATEGORY_ID_PAD_LENGTH = 2;
    public const RANDOM_LENGTH = 6;
    public const SEQUENTIAL_PAD_LENGTH = 6;
    public const SEQUENTIAL_DATA_LENGTH = 12;
    /**
     * Generate SKU based on pattern.
     *
     * Available patterns:
     * - {category} - Category code (first 3 letters uppercase)
     * - {category_id} - Category ID padded to 2 digits
     * - {name} - Product name (first 3 letters uppercase)
     * - {random} - Random alphanumeric (6 chars)
     * - {number} - Sequential number (6 digits)
     * - {date} - Current date (YYMMDD)
     * - {year} - Current year (YYYY)
     * - {month} - Current month (MM)
     * - {timestamp} - Unix timestamp
     *
     * @param string $pattern The SKU pattern with placeholders
     * @param int $organizationId The organization ID for uniqueness checks
     * @param string|null $productName Optional product name for {name} placeholder
     * @param int|null $categoryId Optional category ID for {category} placeholder
     * @return string The generated SKU
     */
    public function generate(
        string $pattern,
        int $organizationId,
        ?string $productName = null,
        ?int $categoryId = null
    ): string {
        $sku = $pattern;

        // Replace category placeholder
        if (str_contains($sku, '{category}')) {
            if ($categoryId) {
                $category = ProductCategory::find($categoryId);
                $categoryCode = $category ? strtoupper(substr($category->name, 0, 3)) : 'UNC';
            } else {
                $categoryCode = 'UNC';
            }
            $sku = str_replace('{category}', $categoryCode, $sku);
        }

        // Replace category ID placeholder
        if (str_contains($sku, '{category_id}')) {
            $catId = $categoryId ? str_pad($categoryId, 2, '0', STR_PAD_LEFT) : '00';
            $sku = str_replace('{category_id}', $catId, $sku);
        }

        // Replace product name placeholder
        if (str_contains($sku, '{name}')) {
            $nameCode = $productName 
                ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $productName), 0, 3))
                : 'PRD';
            $sku = str_replace('{name}', $nameCode, $sku);
        }

        // Replace random placeholder
        if (str_contains($sku, '{random}')) {
            $random = strtoupper(Str::random(6));
            $sku = str_replace('{random}', $random, $sku);
        }

        // Replace sequential number placeholder
        if (str_contains($sku, '{number}')) {
            $lastProduct = Product::where('organization_id', $organizationId)
                ->orderBy('id', 'desc')
                ->first();
            $nextNumber = $lastProduct ? ($lastProduct->id + 1) : 1;
            $number = str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
            $sku = str_replace('{number}', $number, $sku);
        }

        // Replace date placeholders
        if (str_contains($sku, '{date}')) {
            $date = date('ymd');
            $sku = str_replace('{date}', $date, $sku);
        }

        if (str_contains($sku, '{year}')) {
            $year = date('Y');
            $sku = str_replace('{year}', $year, $sku);
        }

        if (str_contains($sku, '{month}')) {
            $month = date('m');
            $sku = str_replace('{month}', $month, $sku);
        }

        // Replace timestamp placeholder
        if (str_contains($sku, '{timestamp}')) {
            $timestamp = time();
            $sku = str_replace('{timestamp}', (string)$timestamp, $sku);
        }

        return $sku;
    }

    /**
     * Check if SKU is unique within organization.
     *
     * @param string $sku The SKU to check
     * @param int $organizationId The organization ID to check within
     * @param int|null $excludeProductId Optional product ID to exclude from check
     * @return bool True if SKU is unique
     */
    public function isUnique(string $sku, int $organizationId, ?int $excludeProductId = null): bool
    {
        $query = Product::where('organization_id', $organizationId)
            ->where('sku', $sku);

        if ($excludeProductId) {
            $query->where('id', '!=', $excludeProductId);
        }

        return !$query->exists();
    }

    /**
     * Generate unique SKU (adds suffix if duplicate).
     *
     * If the generated SKU already exists, appends a numeric suffix.
     *
     * @param string $pattern The SKU pattern with placeholders
     * @param int $organizationId The organization ID for uniqueness checks
     * @param string|null $productName Optional product name for {name} placeholder
     * @param int|null $categoryId Optional category ID for {category} placeholder
     * @param int|null $excludeProductId Optional product ID to exclude from check
     * @return string A unique SKU
     */
    public function generateUnique(
        string $pattern,
        int $organizationId,
        ?string $productName = null,
        ?int $categoryId = null,
        ?int $excludeProductId = null
    ): string {
        $baseSku = $this->generate($pattern, $organizationId, $productName, $categoryId);

        if ($this->isUnique($baseSku, $organizationId, $excludeProductId)) {
            return $baseSku;
        }

        // If not unique, add suffix
        $suffix = 1;
        do {
            $sku = $baseSku . '-' . $suffix;
            $suffix++;
        } while (!$this->isUnique($sku, $organizationId, $excludeProductId));

        return $sku;
    }

    /**
     * Get available pattern variables.
     *
     * @return array<int, array{key: string, description: string, example: string}> List of pattern variables
     */
    public static function getAvailablePatterns(): array
    {
        return [
            [
                'key' => '{category}',
                'description' => 'Category code (first 3 letters)',
                'example' => 'ELE (for Electronics)',
            ],
            [
                'key' => '{category_id}',
                'description' => 'Category ID (2 digits)',
                'example' => '01',
            ],
            [
                'key' => '{name}',
                'description' => 'Product name (first 3 letters)',
                'example' => 'LAP (for Laptop)',
            ],
            [
                'key' => '{random}',
                'description' => 'Random alphanumeric (6 chars)',
                'example' => 'A7K9M2',
            ],
            [
                'key' => '{number}',
                'description' => 'Sequential number (6 digits)',
                'example' => '000123',
            ],
            [
                'key' => '{date}',
                'description' => 'Current date (YYMMDD)',
                'example' => '251013',
            ],
            [
                'key' => '{year}',
                'description' => 'Current year (YYYY)',
                'example' => '2025',
            ],
            [
                'key' => '{month}',
                'description' => 'Current month (MM)',
                'example' => '10',
            ],
            [
                'key' => '{timestamp}',
                'description' => 'Unix timestamp',
                'example' => '1729036800',
            ],
        ];
    }

    /**
     * Get preset patterns.
     *
     * @return array<int, array{name: string, pattern: string, example: string}> List of preset patterns
     */
    public static function getPresetPatterns(): array
    {
        return [
            [
                'name' => 'Sequential Only',
                'pattern' => '{number}',
                'example' => '000123',
            ],
            [
                'name' => 'Category + Number',
                'pattern' => '{category}-{number}',
                'example' => 'ELE-000123',
            ],
            [
                'name' => 'Category + Date + Random',
                'pattern' => '{category}-{date}-{random}',
                'example' => 'ELE-251013-A7K9M2',
            ],
            [
                'name' => 'Name + Number',
                'pattern' => '{name}-{number}',
                'example' => 'LAP-000123',
            ],
            [
                'name' => 'Year + Category + Number',
                'pattern' => '{year}-{category}-{number}',
                'example' => '2025-ELE-000123',
            ],
            [
                'name' => 'Category ID + Number',
                'pattern' => '{category_id}{number}',
                'example' => '01000123',
            ],
        ];
    }
}
