<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Auth\Organization;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\User;
use App\Services\ReportDataService;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\SavedReportRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for the custom report builder.
 *
 * Handles creating, saving, editing, executing, and exporting
 * custom reports built from configurable data sources.
 */
class ReportBuilderController extends Controller
{
    public function __construct(
        private readonly ReportDataService $reportDataService,
        private readonly SavedReportRenderer $renderer,
    ) {}

    /**
     * List saved reports (own + shared).
     */
    public function index(Request $request): Response
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

        $dataSources = $this->reportDataService->getAvailableDataSources();

        return Inertia::render('Reports/Builder/Index', [
            'reports' => $reports,
            'dataSources' => $dataSources,
        ]);
    }

    /**
     * Show the report builder form.
     */
    public function create(Request $request): Response
    {
        $dataSources = $this->reportDataService->getAvailableDataSources();

        return Inertia::render('Reports/Builder/Create', [
            'dataSources' => $dataSources,
        ]);
    }

    /**
     * Save a new report configuration.
     *
     * @return RedirectResponse
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'data_source' => 'required|string|max:50',
            'columns' => 'required|array|min:1',
            'columns.*' => 'string|max:100',
            'filters' => 'nullable|array',
            'filters.*.field' => 'required_with:filters|string|max:100',
            'filters.*.operator' => 'required_with:filters|string|max:20',
            'filters.*.value' => 'nullable|string|max:500',
            'sort' => 'nullable|array',
            'sort.field' => 'required_with:sort|string|max:100',
            'sort.direction' => 'required_with:sort|string|in:asc,desc',
            'chart_type' => 'nullable|string|in:bar,line,pie',
            'chart_field' => 'nullable|string|max:100',
            'is_shared' => 'boolean',
        ]);

        // Validate the data source
        if (! $this->reportDataService->isValidDataSource($validated['data_source'])) {
            return back()->withErrors(['data_source' => 'Invalid data source.']);
        }

        // Validate columns belong to the data source
        $validColumns = $this->reportDataService->getValidColumns($validated['data_source']);
        $invalidColumns = array_diff($validated['columns'], $validColumns);
        if (! empty($invalidColumns)) {
            return back()->withErrors(['columns' => 'Invalid columns: '.implode(', ', $invalidColumns)]);
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

        return redirect()
            ->route('reports.builder.show', $report)
            ->with('success', 'Report created successfully.');
    }

    /**
     * Execute and display a saved report.
     */
    public function show(Request $request, SavedReport $savedReport): Response
    {
        $user = $request->user();

        // Authorization: must be in the same org and either owner or report is shared
        if ($savedReport->organization_id !== $user->organization_id) {
            abort(403);
        }
        if ($savedReport->created_by !== $user->id && ! $savedReport->is_shared) {
            abort(403);
        }

        $data = $this->reportDataService->executeReport(
            $user,
            $savedReport->organization_id,
            $savedReport->data_source,
            $savedReport->columns,
            $savedReport->filters,
            $savedReport->sort
        );

        $dataSources = $this->reportDataService->getAvailableDataSources();
        $sourceConfig = $dataSources[$savedReport->data_source] ?? null;

        // Build column labels for the view
        $columnLabels = [];
        if ($sourceConfig) {
            foreach ($savedReport->columns as $col) {
                $columnLabels[$col] = $sourceConfig['columns'][$col]['label'] ?? $col;
            }
        }

        return Inertia::render('Reports/Builder/Show', [
            'report' => [
                'id' => $savedReport->id,
                'name' => $savedReport->name,
                'description' => $savedReport->description,
                'data_source' => $savedReport->data_source,
                'columns' => $savedReport->columns,
                'filters' => $savedReport->filters,
                'sort' => $savedReport->sort,
                'chart_type' => $savedReport->chart_type,
                'chart_field' => $savedReport->chart_field,
                'is_shared' => $savedReport->is_shared,
                'is_owner' => $savedReport->created_by === $user->id,
                'updated_at' => $savedReport->updated_at->toISOString(),
            ],
            'data' => $data,
            'columnLabels' => $columnLabels,
            'dataSources' => $dataSources,
        ] + $this->scheduleProps($savedReport, $user));
    }

    /**
     * Delivery schedules and the pickable recipients, for the report's owner
     * only (they are the only one who can manage them). Other viewers get
     * empty lists, so organization member emails never reach them here.
     *
     * @return array<string, mixed>
     */
    private function scheduleProps(SavedReport $savedReport, User $user): array
    {
        $isOwner = $savedReport->created_by === $user->id;

        $timezone = (string) (Organization::query()->withoutGlobalScopes()->whereKey($savedReport->organization_id)->value('timezone') ?: 'UTC');

        return [
            'schedules' => ! $isOwner ? [] : $savedReport->schedules()
                ->orderBy('id')
                ->get()
                ->map(fn (ReportSchedule $s) => [
                    'id' => $s->id,
                    'frequency' => $s->frequency,
                    'day_of_week' => $s->day_of_week,
                    'day_of_month' => $s->day_of_month,
                    'time_of_day' => $s->time_of_day,
                    'format' => $s->format,
                    'recipients' => $s->recipients,
                    'is_active' => $s->is_active,
                    'next_run_at' => $s->next_run_at?->toISOString(),
                    'last_run_at' => $s->last_run_at?->toISOString(),
                    'last_status' => $s->last_status,
                ])
                ->all(),
            'recipientOptions' => ! $isOwner ? [] : User::query()
                ->where('organization_id', $savedReport->organization_id)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                ->all(),
            'scheduleOptions' => [
                'timezone' => $timezone,
                'frequencies' => ReportSchedule::FREQUENCIES,
                'formats' => ReportExporter::FORMATS,
            ],
        ];
    }

    /**
     * Show edit form for a saved report.
     */
    public function edit(Request $request, SavedReport $savedReport): Response
    {
        $user = $request->user();

        // Only the creator can edit
        if ($savedReport->organization_id !== $user->organization_id || $savedReport->created_by !== $user->id) {
            abort(403);
        }

        $dataSources = $this->reportDataService->getAvailableDataSources();

        return Inertia::render('Reports/Builder/Edit', [
            'report' => [
                'id' => $savedReport->id,
                'name' => $savedReport->name,
                'description' => $savedReport->description,
                'data_source' => $savedReport->data_source,
                'columns' => $savedReport->columns,
                'filters' => $savedReport->filters,
                'sort' => $savedReport->sort,
                'chart_type' => $savedReport->chart_type,
                'chart_field' => $savedReport->chart_field,
                'is_shared' => $savedReport->is_shared,
            ],
            'dataSources' => $dataSources,
        ]);
    }

    /**
     * Update a saved report configuration.
     *
     * @return RedirectResponse
     */
    public function update(Request $request, SavedReport $savedReport)
    {
        $user = $request->user();

        // Only the creator can update
        if ($savedReport->organization_id !== $user->organization_id || $savedReport->created_by !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'data_source' => 'required|string|max:50',
            'columns' => 'required|array|min:1',
            'columns.*' => 'string|max:100',
            'filters' => 'nullable|array',
            'filters.*.field' => 'required_with:filters|string|max:100',
            'filters.*.operator' => 'required_with:filters|string|max:20',
            'filters.*.value' => 'nullable|string|max:500',
            'sort' => 'nullable|array',
            'sort.field' => 'required_with:sort|string|max:100',
            'sort.direction' => 'required_with:sort|string|in:asc,desc',
            'chart_type' => 'nullable|string|in:bar,line,pie',
            'chart_field' => 'nullable|string|max:100',
            'is_shared' => 'boolean',
        ]);

        if (! $this->reportDataService->isValidDataSource($validated['data_source'])) {
            return back()->withErrors(['data_source' => 'Invalid data source.']);
        }

        $validColumns = $this->reportDataService->getValidColumns($validated['data_source']);
        $invalidColumns = array_diff($validated['columns'], $validColumns);
        if (! empty($invalidColumns)) {
            return back()->withErrors(['columns' => 'Invalid columns: '.implode(', ', $invalidColumns)]);
        }

        $savedReport->update([
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

        return redirect()
            ->route('reports.builder.show', $savedReport)
            ->with('success', 'Report updated successfully.');
    }

    /**
     * Delete a saved report.
     *
     * @return RedirectResponse
     */
    public function destroy(Request $request, SavedReport $savedReport)
    {
        $user = $request->user();

        // Only the creator can delete
        if ($savedReport->organization_id !== $user->organization_id || $savedReport->created_by !== $user->id) {
            abort(403);
        }

        $savedReport->delete();

        return redirect()
            ->route('reports.builder.index')
            ->with('success', 'Report deleted successfully.');
    }

    /**
     * Export a saved report as CSV (default), XLSX or PDF (?format=).
     *
     * The rows are produced for the downloading user, so the per-source view
     * permission is re-checked for each viewer of a shared report.
     */
    public function export(Request $request, SavedReport $savedReport): StreamedResponse
    {
        $user = $request->user();

        // Authorization
        if ($savedReport->organization_id !== $user->organization_id) {
            abort(403);
        }
        if ($savedReport->created_by !== $user->id && ! $savedReport->is_shared) {
            abort(403);
        }

        $format = (string) $request->query('format', 'csv');
        if (! ReportExporter::isValidFormat($format)) {
            abort(422, 'Unsupported export format.');
        }

        $rendered = $this->renderer->render($savedReport, $user, $format);

        // Keep the original download name shape for saved reports.
        $filename = str_replace(' ', '_', strtolower($savedReport->name)).'_'.now()->format('Y-m-d').'.'.$format;

        return response()->streamDownload(function () use ($rendered): void {
            echo $rendered->content;
        }, $filename, [
            'Content-Type' => $rendered->mimeType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Preview report data without saving (live preview in builder).
     */
    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'data_source' => 'required|string|max:50',
            'columns' => 'required|array|min:1',
            'columns.*' => 'string|max:100',
            'filters' => 'nullable|array',
            'filters.*.field' => 'required_with:filters|string|max:100',
            'filters.*.operator' => 'required_with:filters|string|max:20',
            'filters.*.value' => 'nullable|string|max:500',
            'sort' => 'nullable|array',
            'sort.field' => 'required_with:sort|string|max:100',
            'sort.direction' => 'required_with:sort|string|in:asc,desc',
        ]);

        if (! $this->reportDataService->isValidDataSource($validated['data_source'])) {
            return response()->json(['error' => 'Invalid data source.'], 422);
        }

        $validColumns = $this->reportDataService->getValidColumns($validated['data_source']);
        $requestedColumns = array_intersect($validated['columns'], $validColumns);

        if (empty($requestedColumns)) {
            return response()->json(['error' => 'No valid columns selected.'], 422);
        }

        try {
            $data = $this->reportDataService->executeReport(
                $user,
                $user->organization_id,
                $validated['data_source'],
                $requestedColumns,
                $validated['filters'] ?? null,
                $validated['sort'] ?? null
            );

            // Build column labels
            $dataSources = $this->reportDataService->getAvailableDataSources();
            $sourceConfig = $dataSources[$validated['data_source']];
            $columnLabels = [];
            foreach ($requestedColumns as $col) {
                $columnLabels[$col] = $sourceConfig['columns'][$col]['label'] ?? $col;
            }

            return response()->json([
                'data' => $data->take(100)->values(), // Limit preview to 100 rows
                'total' => $data->count(),
                'columnLabels' => $columnLabels,
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to execute report query.'], 500);
        }
    }
}
