<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Reversible product text escaping, explicitly identified in exported rows. */
final class ProductSpreadsheetText
{
    public const COLUMN = 'inventoros_text_encoding';

    public const HEADING = 'Inventoros Text Encoding';

    public const ENCODING = 'apostrophe-v1';

    private const TEXT_COLUMNS = [
        'name', 'sku', 'barcode', 'description', 'category', 'location',
        'currency', 'status', 'notes', 'created_at', 'supplier_code',
        'supplier_name', 'supplier_sku',
    ];

    public static function encodeRow(array $values, array $headings): array
    {
        foreach ($headings as $index => $heading) {
            $value = $values[$index];
            $text = in_array(Str::slug($heading, '_'), self::TEXT_COLUMNS, true);
            // Escape literal apostrophes too, so '@SKU and @SKU remain
            // distinct. Numeric-looking identifiers must remain text cells.
            $values[$index] = $text && is_string($value) && (str_starts_with($value, "'") || is_numeric($value))
                ? "'".$value : SpreadsheetSafety::neutralise($value);
        }

        return $values;
    }

    public static function decodeRow(Collection $row): void
    {
        $encoding = $row->get(self::COLUMN);
        if ($encoding === null || $encoding === '') {
            return;
        }
        if ($encoding !== self::ENCODING) {
            throw new InvalidArgumentException('Unsupported product text encoding. Export the products again before importing this file.');
        }
        foreach (self::TEXT_COLUMNS as $column) {
            $value = $row->get($column);
            if (! is_string($value) || strlen($value) < 2 || $value[0] !== "'") {
                continue;
            }
            $plain = substr($value, 1);
            if ($plain[0] === "'" || is_numeric($plain) || SpreadsheetSafety::neutralise($plain) !== $plain) {
                $row->put($column, $plain);
            }
        }
    }

    public static function isEncoded(Collection|array $row): bool
    {
        return ($row[self::COLUMN] ?? null) === self::ENCODING;
    }
}
