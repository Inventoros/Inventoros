<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Reports\ReportExporter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * One exporter renders every report (fixed and builder) as CSV, XLSX or PDF,
 * so the formula-injection defence lives in one place for all three.
 */
class ReportExporterTest extends TestCase
{
    private function exporter(): ReportExporter
    {
        return app(ReportExporter::class);
    }

    public function test_csv_neutralises_formula_cells_and_keeps_a_bom(): void
    {
        $rendered = $this->exporter()->render('csv', 'Dead Stock', ['Name', 'Qty'], [
            ['=HYPERLINK("https://evil")', 5],
            ['Plain', 3],
        ]);

        $this->assertSame('text/csv; charset=UTF-8', $rendered->mimeType);
        $this->assertStringEndsWith('.csv', $rendered->filename);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $rendered->content);
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"https://evil\"\")\",5", $rendered->content);
        $this->assertStringContainsString('Plain,3', $rendered->content);
    }

    public function test_csv_does_not_emit_a_backslash_escape(): void
    {
        // fputcsv's default "\" escape lets a value ending in a backslash
        // swallow the closing quote; exports pass escape: ''.
        $rendered = $this->exporter()->render('csv', 'X', ['A', 'B'], [['path\\', '=1+1']]);

        $lines = array_values(array_filter(explode("\n", substr($rendered->content, 3))));
        $this->assertSame('path\\,\'=1+1', trim($lines[1]));
    }

    public function test_xlsx_cells_are_neutralised_and_typed_as_strings(): void
    {
        $rendered = $this->exporter()->render('xlsx', 'Margins', ['Name', 'Revenue'], [
            ['=cmd|\' /C calc\'!A0', 12.5],
            ['Safe name', 7],
        ]);

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $rendered->mimeType);
        $this->assertStringEndsWith('.xlsx', $rendered->filename);

        $path = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
        file_put_contents($path, $rendered->content);
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        $this->assertSame('Name', $sheet->getCell('A1')->getValue());
        $this->assertSame("'=cmd|' /C calc'!A0", $sheet->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        // Numbers stay numeric so the sheet can still be summed.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
        $this->assertEquals(12.5, $sheet->getCell('B2')->getValue());
    }

    public function test_xlsx_never_stores_a_formula_even_for_a_bare_equals_value(): void
    {
        $rendered = $this->exporter()->render('xlsx', 'X', ['A'], [['=SUM(1,2)']]);

        $path = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
        file_put_contents($path, $rendered->content);
        $cell = IOFactory::load($path)->getActiveSheet()->getCell('A2');
        @unlink($path);

        $this->assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType());
    }

    public function test_pdf_renders_a_pdf_document(): void
    {
        $rendered = $this->exporter()->render('pdf', 'ABC Analysis', ['Product', 'Class'], [['<b>Widget</b>', 'A']], ['Period: 2026-01-01 to 2026-01-31']);

        $this->assertSame('application/pdf', $rendered->mimeType);
        $this->assertStringEndsWith('.pdf', $rendered->filename);
        $this->assertStringStartsWith('%PDF', $rendered->content);
    }

    public function test_unknown_format_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->exporter()->render('html', 'X', ['A'], []);
    }

    public function test_filename_is_slugged_and_dated(): void
    {
        $rendered = $this->exporter()->render('csv', 'Profit / Margin Report', ['A'], []);

        $this->assertSame('profit-margin-report_'.now()->format('Y-m-d').'.csv', $rendered->filename);
    }

    public function test_download_sets_attachment_headers(): void
    {
        $response = $this->exporter()->download('csv', 'Dead Stock', ['A'], [['x']]);

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('dead-stock_', $response->headers->get('Content-Disposition'));
    }
}
