<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\SpreadsheetSafety;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * A single-sheet XLSX of a report's header row plus data rows.
 *
 * Formula-injection defence, twice over: every row goes through
 * SpreadsheetSafety::neutraliseRow (the same prefix the CSV exports apply),
 * and the value binder stores every string cell as an explicit STRING, so
 * PhpSpreadsheet never turns a value that starts with "=" into a stored
 * formula. Native ints and floats stay numeric so the sheet can be summed.
 */
final class ReportSheetExport extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithHeadings, WithTitle
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function __construct(
        private readonly array $headers,
        private readonly array $rows,
        private readonly string $title = 'Report',
    ) {}

    public function array(): array
    {
        return array_map(
            static fn (array $row): array => SpreadsheetSafety::neutraliseRow(array_values($row)),
            $this->rows
        );
    }

    public function headings(): array
    {
        return SpreadsheetSafety::neutraliseRow($this->headers);
    }

    public function title(): string
    {
        // Excel sheet titles: max 31 chars, none of : \ / ? * [ ].
        $clean = trim((string) preg_replace('/[:\\\\\/?*\[\]]/', ' ', $this->title));

        return mb_substr($clean !== '' ? $clean : 'Report', 0, 31);
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
