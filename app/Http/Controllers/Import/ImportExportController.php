<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Exports\ExportFactory;
use App\Http\Controllers\Controller;
use App\Imports\ProductsImport;
use App\Jobs\GenerateDataExportJob;
use App\Jobs\ProcessProductImportJob;
use App\Models\DataExport;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for handling data import and export.
 *
 * Manages importing and exporting products, orders, and users
 * via Excel/CSV files.
 */
class ImportExportController extends Controller
{
    /**
     * Display the import/export page.
     *
     * @param  Request  $request  The incoming HTTP request
     */
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $categories = ProductCategory::forOrganization($organizationId)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        $locations = ProductLocation::forOrganization($organizationId)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        $exports = DataExport::where('user_id', $request->user()->id)
            ->latest()
            ->limit(15)
            ->get(['id', 'type', 'filename', 'status', 'row_count', 'completed_at', 'created_at']);

        return Inertia::render('ImportExport/Index', [
            'categories' => $categories,
            'locations' => $locations,
            'exports' => $exports,
        ]);
    }

    /**
     * Export products to Excel file.
     *
     * @param  Request  $request  The incoming HTTP request containing export filters
     * @return BinaryFileResponse
     */
    public function exportProducts(Request $request)
    {
        $filters = $request->only(['category_id', 'location_id', 'status', 'low_stock']);

        return $this->streamOrQueueExport($request, 'products', $filters);
    }

    /**
     * Download product import template.
     *
     * @param  Request  $request  The incoming HTTP request
     * @return \Illuminate\Http\Response
     */
    public function downloadTemplate(Request $request)
    {
        $headers = [
            'name',
            'sku',
            'barcode',
            'description',
            'category',
            'location',
            'price',
            'currency',
            'purchase_price',
            'stock',
            'min_stock',
            'status',
            'notes',
        ];

        $filename = 'product_import_template.csv';

        $callback = function () use ($headers) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $headers);

            // Add example row
            fputcsv($file, [
                'Example Product',
                'SKU-001',
                '1234567890',
                'This is an example product description',
                'Electronics',
                'Warehouse A',
                '99.99',
                'USD',
                '50.00',
                '100',
                '10',
                'active',
                'Example notes',
            ]);

            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Import products from Excel/CSV file.
     *
     * @param  Request  $request  The incoming HTTP request containing the import file
     * @return RedirectResponse
     */
    public function importProducts(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|mimetypes:text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel|max:10240', // 10MB max
        ]);

        try {
            $organizationId = $request->user()->organization_id;
            $file = $request->file('file');

            // Large uploads are processed off-request: store the file, queue the
            // import, and notify the user with stats when it finishes.
            if ($file->getSize() > config('imports.sync_max_kb') * 1024) {
                $disk = config('imports.disk');
                $path = $file->store('imports/'.$organizationId, $disk);

                ProcessProductImportJob::dispatch($organizationId, $request->user()->id, $disk, $path);

                return redirect()->route('import-export.index')
                    ->with('success', "Your import is being processed. You'll be notified when it's complete.");
            }

            $import = new ProductsImport($organizationId);
            Excel::import($import, $file);

            $stats = $import->getStats();

            if (count($stats['errors']) > 0) {
                return redirect()->route('import-export.index')
                    ->with('warning', [
                        'message' => 'Import completed with some errors',
                        'stats' => $stats,
                    ]);
            }

            return redirect()->route('import-export.index')
                ->with('success', 'Products imported successfully! Created: '.$stats['imported'].', Updated: '.$stats['updated']);
        } catch (\Exception $e) {
            Log::error('Product import failed', [
                'user_id' => $request->user()->id,
                'organization_id' => $request->user()->organization_id,
                'file' => $request->file('file')?->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('import-export.index')
                ->with('error', 'Import failed: '.$e->getMessage());
        }
    }

    /**
     * Export orders to Excel file.
     *
     * @param  Request  $request  The incoming HTTP request containing export filters
     * @return BinaryFileResponse
     */
    public function exportOrders(Request $request)
    {
        $filters = $request->only(['status', 'date_from', 'date_to', 'customer_id']);

        return $this->streamOrQueueExport($request, 'orders', $filters);
    }

    /**
     * Export users to Excel file.
     *
     * @param  Request  $request  The incoming HTTP request containing export filters
     * @return BinaryFileResponse
     */
    public function exportUsers(Request $request)
    {
        $filters = $request->only(['role_id', 'is_active']);

        return $this->streamOrQueueExport($request, 'users', $filters);
    }

    /**
     * Stream an export synchronously when small, or queue it when large.
     *
     * Exports at or below the configured row limit are downloaded inline (the
     * historical behaviour). Larger ones are recorded, dispatched to the queue,
     * and the user is redirected back with a notice — they download the file
     * from the list on this page once the job notifies them it is ready.
     *
     * @param  array<string, mixed>  $filters
     * @return BinaryFileResponse|RedirectResponse
     */
    private function streamOrQueueExport(Request $request, string $type, array $filters)
    {
        $organizationId = $request->user()->organization_id;
        $export = ExportFactory::make($type, $organizationId, $filters);

        $rowCount = $export->query()->count();
        $filename = $type.'_'.now()->format('Y-m-d_His').'.xlsx';

        if ($rowCount <= config('exports.sync_row_limit')) {
            return Excel::download($export, $filename);
        }

        $disk = config('exports.disk');

        $record = DataExport::create([
            'organization_id' => $organizationId,
            'user_id' => $request->user()->id,
            'type' => $type,
            'filename' => $filename,
            'disk' => $disk,
            'path' => 'exports/'.$organizationId.'/'.Str::uuid().'.xlsx',
            'filters' => $filters,
            'status' => 'pending',
            'row_count' => $rowCount,
        ]);

        GenerateDataExportJob::dispatch($record->id);

        return redirect()->route('import-export.index')
            ->with('success', "Your {$type} export ({$rowCount} rows) is being prepared. You'll be notified when it's ready to download.");
    }

    /**
     * Download a previously generated, queued export file.
     *
     * @return StreamedResponse
     */
    public function download(Request $request, DataExport $dataExport)
    {
        // Route-model binding is org-scoped by the global scope, so a
        // cross-tenant id already 404s; guard the file state explicitly.
        abort_unless($dataExport->isDownloadable(), 404);

        return Storage::disk($dataExport->disk)->download($dataExport->path, $dataExport->filename);
    }
}
