<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\SavedReport;
use App\Models\User;
use App\Services\ReportDataService;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Runs a saved (builder) report as a given user and renders it in one export
 * format. Shared by the download route and by scheduled delivery, so both
 * enforce the same per-source permission check through
 * ReportDataService::executeReport for the user the report runs as.
 */
class SavedReportRenderer
{
    public function __construct(
        private readonly ReportDataService $reportData,
        private readonly ReportExporter $exporter,
    ) {}

    /**
     * @throws AuthorizationException When $user may not read the report's data source.
     */
    public function render(SavedReport $report, User $user, string $format): RenderedReport
    {
        $data = $this->reportData->executeReport(
            $user,
            $report->organization_id,
            $report->data_source,
            $report->columns,
            $report->filters,
            $report->sort
        );

        $source = $this->reportData->getAvailableDataSources()[$report->data_source] ?? ['label' => $report->data_source, 'columns' => []];

        $headers = [];
        foreach ($report->columns as $column) {
            $headers[] = $source['columns'][$column]['label'] ?? $column;
        }

        $rows = [];
        foreach ($data as $row) {
            $cells = [];
            foreach ($report->columns as $column) {
                $cells[] = $this->cell($row->$column ?? null, $source['columns'][$column]['type'] ?? 'string');
            }
            $rows[] = $cells;
        }

        return $this->exporter->render($format, $report->name, $headers, $rows, [
            'Data source: '.($source['label'] ?? $report->data_source).'. '.count($rows).' rows.',
        ]);
    }

    /**
     * Database drivers return numeric columns as strings; hand them to the
     * exporter as real numbers so XLSX cells stay summable. Everything else is
     * text and gets the exporter's formula neutralisation.
     */
    private function cell(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return '';
        }

        if (in_array($type, ['number', 'currency'], true) && is_numeric($value)) {
            return str_contains((string) $value, '.') || $type === 'currency' ? (float) $value : (int) $value;
        }

        return is_scalar($value) ? $value : (string) json_encode($value);
    }
}
