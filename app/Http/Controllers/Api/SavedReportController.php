<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedReport;
use App\Services\ReportDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Dedoc\Scramble\Attributes\QueryParameter;

/**
 * @tags Saved Reports
 */
class SavedReportController extends Controller
{
    public function __construct(
        private readonly ReportDataService $reportDataService
    ) {}

    /**
     * List accessible reports (own + shared).
     */
    #[QueryParameter('per_page', description: 'Items per page (default: 15, max: 100)', type: 'integer')]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $reports = SavedReport::accessibleBy($user)
            ->with('creator:id,name')
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function (SavedReport $report) use ($user) {
                return [
                    'id' => $report->id,
                    'name' => $report->name,
                    'description' => $report->description,
                    'data_source' => $report->data_source,
                    'is_shared' => $report->is_shared,
                    'is_owner' => $report->created_by === $user->id,
                    'creator_name' => $report->creator?->name,
                    'columns_count' => count($report->columns),
                    'filters_count' => $report->filters ? count($report->filters) : 0,
                    'chart_type' => $report->chart_type,
                    'updated_at' => $report->updated_at->toISOString(),
                    'created_at' => $report->created_at->toISOString(),
                ];
            });

        return response()->json([
            'data' => $reports,
        ]);
    }

    /**
     * Save a new report configuration.
     *
     * @param Request $request The incoming HTTP request containing report config
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'data_source' => ['required', 'string', 'max:50'],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['string', 'max:100'],
            'filters' => ['nullable', 'array'],
            'filters.*.field' => ['required_with:filters', 'string', 'max:100'],
            'filters.*.operator' => ['required_with:filters', 'string', 'max:20'],
            'filters.*.value' => ['nullable', 'string', 'max:500'],
            'sort' => ['nullable', 'array'],
            'sort.field' => ['required_with:sort', 'string', 'max:100'],
            'sort.direction' => ['required_with:sort', 'string', 'in:asc,desc'],
            'chart_type' => ['nullable', 'string', 'in:bar,line,pie'],
            'chart_field' => ['nullable', 'string', 'max:100'],
            'is_shared' => ['nullable', 'boolean'],
        ]);

        // Validate the data source
        if (!$this->reportDataService->isValidDataSource($validated['data_source'])) {
            return response()->json([
                'message' => 'Invalid data source.',
                'error' => 'invalid_data_source',
            ], 422);
        }

        // Validate columns belong to the data source
        $validColumns = $this->reportDataService->getValidColumns($validated['data_source']);
        $invalidColumns = array_diff($validated['columns'], $validColumns);
        if (!empty($invalidColumns)) {
            return response()->json([
                'message' => 'Invalid columns: ' . implode(', ', $invalidColumns),
                'error' => 'invalid_columns',
            ], 422);
        }

        $report = SavedReport::create([
            'organization_id' => $user->organization_id,
            'created_by' => $user->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'data_source' => $validated['data_source'],
            'columns' => $validated['columns'],
            'filters' => $validated['filters'] ?? null,
            'sort' => $validated['sort'] ?? null,
            'chart_type' => $validated['chart_type'] ?? null,
            'chart_field' => $validated['chart_field'] ?? null,
            'is_shared' => $validated['is_shared'] ?? false,
        ]);

        return response()->json([
            'message' => 'Report created successfully',
            'data' => $report,
        ], 201);
    }

    /**
     * Execute and display a saved report.
     *
     * @param Request $request The incoming HTTP request
     * @param SavedReport $report The saved report to execute
     * @return JsonResponse
     */
    public function show(Request $request, SavedReport $report): JsonResponse
    {
        $user = $request->user();

        if ($report->organization_id !== $user->organization_id) {
            return response()->json([
                'message' => 'Report not found',
                'error' => 'not_found',
            ], 404);
        }

        if ($report->created_by !== $user->id && !$report->is_shared) {
            return response()->json([
                'message' => 'Report not found',
                'error' => 'not_found',
            ], 404);
        }

        try {
            $data = $this->reportDataService->executeReport(
                $report->organization_id,
                $report->data_source,
                $report->columns,
                $report->filters,
                $report->sort
            );
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to execute report query.',
                'error' => 'query_failed',
            ], 500);
        }

        $dataSources = $this->reportDataService->getAvailableDataSources();
        $sourceConfig = $dataSources[$report->data_source] ?? null;

        $columnLabels = [];
        if ($sourceConfig) {
            foreach ($report->columns as $col) {
                $columnLabels[$col] = $sourceConfig['columns'][$col]['label'] ?? $col;
            }
        }

        return response()->json([
            'data' => [
                'report' => [
                    'id' => $report->id,
                    'name' => $report->name,
                    'description' => $report->description,
                    'data_source' => $report->data_source,
                    'columns' => $report->columns,
                    'filters' => $report->filters,
                    'sort' => $report->sort,
                    'chart_type' => $report->chart_type,
                    'chart_field' => $report->chart_field,
                    'is_shared' => $report->is_shared,
                    'is_owner' => $report->created_by === $user->id,
                    'updated_at' => $report->updated_at->toISOString(),
                ],
                'rows' => $data->values(),
                'total' => $data->count(),
                'column_labels' => $columnLabels,
            ],
        ]);
    }

    /**
     * Update a saved report configuration.
     *
     * @param Request $request The incoming HTTP request
     * @param SavedReport $report The saved report to update
     * @return JsonResponse
     */
    public function update(Request $request, SavedReport $report): JsonResponse
    {
        $user = $request->user();

        if ($report->organization_id !== $user->organization_id || $report->created_by !== $user->id) {
            return response()->json([
                'message' => 'Report not found',
                'error' => 'not_found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'data_source' => ['sometimes', 'string', 'max:50'],
            'columns' => ['sometimes', 'array', 'min:1'],
            'columns.*' => ['string', 'max:100'],
            'filters' => ['nullable', 'array'],
            'filters.*.field' => ['required_with:filters', 'string', 'max:100'],
            'filters.*.operator' => ['required_with:filters', 'string', 'max:20'],
            'filters.*.value' => ['nullable', 'string', 'max:500'],
            'sort' => ['nullable', 'array'],
            'sort.field' => ['required_with:sort', 'string', 'max:100'],
            'sort.direction' => ['required_with:sort', 'string', 'in:asc,desc'],
            'chart_type' => ['nullable', 'string', 'in:bar,line,pie'],
            'chart_field' => ['nullable', 'string', 'max:100'],
            'is_shared' => ['nullable', 'boolean'],
        ]);

        $dataSource = $validated['data_source'] ?? $report->data_source;

        if (isset($validated['data_source']) && !$this->reportDataService->isValidDataSource($dataSource)) {
            return response()->json([
                'message' => 'Invalid data source.',
                'error' => 'invalid_data_source',
            ], 422);
        }

        if (isset($validated['columns'])) {
            $validColumns = $this->reportDataService->getValidColumns($dataSource);
            $invalidColumns = array_diff($validated['columns'], $validColumns);
            if (!empty($invalidColumns)) {
                return response()->json([
                    'message' => 'Invalid columns: ' . implode(', ', $invalidColumns),
                    'error' => 'invalid_columns',
                ], 422);
            }
        }

        $report->update(array_filter([
            'name' => $validated['name'] ?? null,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : null,
            'data_source' => $validated['data_source'] ?? null,
            'columns' => $validated['columns'] ?? null,
            'filters' => array_key_exists('filters', $validated) ? $validated['filters'] : null,
            'sort' => array_key_exists('sort', $validated) ? $validated['sort'] : null,
            'chart_type' => array_key_exists('chart_type', $validated) ? $validated['chart_type'] : null,
            'chart_field' => array_key_exists('chart_field', $validated) ? $validated['chart_field'] : null,
            'is_shared' => $validated['is_shared'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json([
            'message' => 'Report updated successfully',
            'data' => $report->fresh(),
        ]);
    }

    /**
     * Delete a saved report.
     *
     * @param Request $request The incoming HTTP request
     * @param SavedReport $report The saved report to delete
     * @return JsonResponse
     */
    public function destroy(Request $request, SavedReport $report): JsonResponse
    {
        $user = $request->user();

        if ($report->organization_id !== $user->organization_id || $report->created_by !== $user->id) {
            return response()->json([
                'message' => 'Report not found',
                'error' => 'not_found',
            ], 404);
        }

        $report->delete();

        return response()->json([
            'message' => 'Report deleted successfully',
        ]);
    }

    /**
     * Export a saved report as CSV.
     *
     * @param Request $request The incoming HTTP request
     * @param SavedReport $report The saved report to export
     * @return StreamedResponse|JsonResponse
     */
    public function export(Request $request, SavedReport $report): StreamedResponse|JsonResponse
    {
        $user = $request->user();

        if ($report->organization_id !== $user->organization_id) {
            return response()->json([
                'message' => 'Report not found',
                'error' => 'not_found',
            ], 404);
        }

        if ($report->created_by !== $user->id && !$report->is_shared) {
            return response()->json([
                'message' => 'Report not found',
                'error' => 'not_found',
            ], 404);
        }

        try {
            $data = $this->reportDataService->executeReport(
                $report->organization_id,
                $report->data_source,
                $report->columns,
                $report->filters,
                $report->sort
            );
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to execute report query.',
                'error' => 'query_failed',
            ], 500);
        }

        $dataSources = $this->reportDataService->getAvailableDataSources();
        $sourceConfig = $dataSources[$report->data_source] ?? [];

        // Build column headers
        $headers = [];
        foreach ($report->columns as $col) {
            $headers[] = $sourceConfig['columns'][$col]['label'] ?? $col;
        }

        $filename = str_replace(' ', '_', strtolower($report->name)) . '_' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($data, $report, $headers) {
            $handle = fopen('php://output', 'w');

            // Write UTF-8 BOM for Excel compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            // Header row
            fputcsv($handle, $headers);

            // Data rows
            foreach ($data as $row) {
                $csvRow = [];
                foreach ($report->columns as $col) {
                    $csvRow[] = $row->$col ?? '';
                }
                fputcsv($handle, $csvRow);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
