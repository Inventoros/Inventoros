<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Exports\ReportSheetExport;
use App\Support\SpreadsheetSafety;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\DefaultValueBinder;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders any tabular report (fixed reports and saved builder reports) as
 * CSV, XLSX or PDF. Used by the download routes and by scheduled report
 * emails, so an attachment is byte-for-byte what a download would give.
 *
 * Callers pass native ints/floats for numeric cells: strings are treated as
 * text and neutralised against formula injection in CSV and XLSX.
 */
class ReportExporter
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    private const MIME = [
        'csv' => 'text/csv; charset=UTF-8',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf' => 'application/pdf',
    ];

    public static function isValidFormat(?string $format): bool
    {
        return $format !== null && in_array($format, self::FORMATS, true);
    }

    /**
     * The format a report page was asked to export in (?export=csv|xlsx|pdf),
     * or null to render the page.
     */
    public static function requestedFormat(Request $request): ?string
    {
        $format = $request->query('export');

        return is_string($format) && self::isValidFormat($format) ? $format : null;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $notes  Context lines printed above the PDF table (period, caveats).
     */
    public function render(string $format, string $title, array $headers, iterable $rows, array $notes = []): RenderedReport
    {
        if (! self::isValidFormat($format)) {
            throw new \InvalidArgumentException("Unsupported report export format: {$format}");
        }

        $rows = $this->normaliseRows($rows);

        $content = match ($format) {
            'csv' => $this->csv($headers, $rows),
            'xlsx' => $this->xlsx($title, $headers, $rows),
            'pdf' => $this->pdf($title, $headers, $rows, $notes),
        };

        return new RenderedReport($content, self::MIME[$format], $this->filename($title, $format));
    }

    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $notes
     */
    public function download(string $format, string $title, array $headers, iterable $rows, array $notes = []): Response
    {
        $rendered = $this->render($format, $title, $headers, $rows, $notes);

        return response($rendered->content, 200, [
            'Content-Type' => $rendered->mimeType,
            'Content-Disposition' => 'attachment; filename="'.$rendered->filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function filename(string $title, string $format): string
    {
        $slug = Str::slug($title) ?: 'report';

        return $slug.'_'.now()->format('Y-m-d').'.'.$format;
    }

    /**
     * @param  iterable<int, mixed>  $rows
     * @return array<int, array<int, mixed>>
     */
    private function normaliseRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = array_values(is_array($row) ? $row : (array) $row);
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        // UTF-8 BOM so Excel opens accented names correctly.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, SpreadsheetSafety::neutraliseRow($headers), escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, SpreadsheetSafety::neutraliseRow($row), escape: '');
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * The sheet installs its string-typing value binder on PhpSpreadsheet's
     * process-wide Cell binder; restore the configured default afterwards so
     * it never leaks into a later import or export in the same worker.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function xlsx(string $title, array $headers, array $rows): string
    {
        try {
            return Excel::raw(new ReportSheetExport($headers, $rows, $title), ExcelWriter::XLSX);
        } finally {
            $binder = config('excel.value_binder.default', DefaultValueBinder::class);
            Cell::setValueBinder(app($binder));
        }
    }

    /**
     * A PDF of tens of thousands of rows is neither readable nor cheap to
     * render, so the PDF is capped at reports.pdf_max_rows and says so; CSV and
     * XLSX carry the full (reports.max_rows bounded) set.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $notes
     */
    private function pdf(string $title, array $headers, array $rows, array $notes): string
    {
        $cap = max(1, (int) config('reports.pdf_max_rows', 1000));
        $truncated = count($rows) > $cap;

        return Pdf::loadView('pdf.report', [
            'title' => $title,
            'headers' => $headers,
            'rows' => array_slice($rows, 0, $cap),
            'notes' => $notes,
            'truncatedAt' => $truncated ? $cap : null,
            'totalRows' => count($rows),
            'generatedDate' => now()->format('F j, Y H:i'),
        ])->setPaper('a4', count($headers) > 5 ? 'landscape' : 'portrait')->output();
    }
}
