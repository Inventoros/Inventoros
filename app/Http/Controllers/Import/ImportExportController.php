<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Enums\OrderStatus;
use App\Exports\ExportFactory;
use App\Http\Controllers\Controller;
use App\Imports\OrdersImport;
use App\Imports\ProductsImport;
use App\Imports\UsersImport;
use App\Jobs\GenerateDataExportJob;
use App\Jobs\ProcessOrderImportJob;
use App\Jobs\ProcessProductImportJob;
use App\Models\DataExport;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\User;
use App\Support\ProductCurrencyColumns;
use App\Support\SpreadsheetReaderType;
use Illuminate\Database\QueryException;
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
     * Upload rule shared by every import endpoint.
     */
    private const IMPORT_FILE_RULE = 'required|file|extensions:csv,txt,xlsx,xls|mimes:csv,txt,xlsx,xls|mimetypes:text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel|max:10240'; // 10MB max

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
            // The per-currency price columns the product CSV carries.
            'currencyColumns' => array_map(
                fn (string $code) => 'price_'.$code,
                ProductCurrencyColumns::currenciesFor($organizationId),
            ),
            'orderStatuses' => OrderStatus::values(),
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
            'supplier_code',
            'supplier_name',
            'supplier_sku',
            'supplier_cost',
        ];

        // One price column per additional currency the organization uses.
        $currencies = ProductCurrencyColumns::currenciesFor($request->user()->organization_id);
        foreach ($currencies as $code) {
            $headers[] = 'price_'.$code;
        }

        $filename = 'product_import_template.csv';

        $callback = function () use ($headers, $currencies) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $headers, escape: '');

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
                'SUP-001',
                'Example Supplier',
                'EX-SUP-001',
                '45.00',
                ...array_fill(0, count($currencies), ''),
            ], escape: '');

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
            'file' => self::IMPORT_FILE_RULE,
        ]);

        try {
            $organizationId = $request->user()->organization_id;
            $file = $request->file('file');
            $readerType = SpreadsheetReaderType::forPath((string) $file->getRealPath());

            // Large uploads are processed off-request: store the file, queue the
            // import, and notify the user with stats when it finishes.
            if ($file->getSize() > config('imports.sync_max_kb') * 1024) {
                $disk = config('imports.disk');
                $path = $file->store('imports/'.$organizationId, $disk);

                ProcessProductImportJob::dispatch($organizationId, $request->user()->id, $disk, $path, $readerType);

                return redirect()->route('import-export.index')
                    ->with('success', "Your import is being processed. You'll be notified when it's complete.");
            }

            $import = new ProductsImport($organizationId, $request->user());
            Excel::import($import, $file, null, $readerType);

            $stats = $import->getStats();
            $this->announceImport('products', $request->user(), 'completed', $stats);

            return $this->redirectWithImportResult(
                'products',
                $stats,
                'Products imported successfully! Created: '.$stats['imported'].', Updated: '.$stats['updated'],
            );
        } catch (QueryException $e) {
            // Raw SQL is not for the banner; the error handler logs it.
            throw $e;
        } catch (\Exception $e) {
            $this->announceImport('products', $request->user(), 'failed');
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
     * Download the user import template. There is deliberately no password
     * column: imported users set their own password via an emailed link.
     */
    public function downloadUserTemplate(): StreamedResponse
    {
        return response()->stream(function () {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['name', 'email', 'role', 'roles'], escape: '');
            fputcsv($file, ['Example Member', 'member@example.com', 'member', 'Picker; Packer'], escape: '');
            fputcsv($file, ['Example Manager', 'manager@example.com', 'manager', ''], escape: '');
            fclose($file);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="user_import_template.csv"',
        ]);
    }

    /**
     * Import users into the current organization.
     *
     * `send_invites` (default on) emails each new user a set-password link;
     * off leaves the invitation pending (they use "Forgot your password?").
     * Role assignment goes through the same escalation guard as the user form.
     */
    public function importUsers(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => self::IMPORT_FILE_RULE,
            'send_invites' => 'nullable|boolean',
        ]);

        $user = $request->user();

        try {
            $import = new UsersImport($user, $request->boolean('send_invites', true));
            Excel::import($import, $request->file('file'), null, SpreadsheetReaderType::forPath((string) $request->file('file')->getRealPath()));
            $stats = $import->getStats();
            $this->announceImport('users', $user, 'completed', $stats);

            return $this->redirectWithImportResult(
                'users',
                $stats,
                'Users imported successfully! Created: '.$stats['imported'],
            );
        } catch (QueryException $e) {
            // Raw SQL is not for the banner; the error handler logs it.
            throw $e;
        } catch (\Exception $e) {
            $this->announceImport('users', $user, 'failed');
            Log::error('User import failed', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('import-export.index')
                ->with('error', 'Import failed: '.$e->getMessage());
        }
    }

    /**
     * Download the order import template.
     *
     * One row per order line; rows sharing an external_reference are one
     * order. See OrdersImport for the full format.
     */
    public function downloadOrderTemplate(): StreamedResponse
    {
        $headers = [
            'external_reference', 'order_date', 'status', 'customer_name', 'customer_email',
            'product_sku', 'variant_sku', 'quantity', 'unit_price', 'line_tax', 'unit_cost',
            'order_tax', 'order_shipping', 'currency', 'shipped_at', 'delivered_at', 'notes',
        ];

        $examples = [
            ['SHOP-1001', '2026-01-15', 'delivered', 'Example Customer', 'customer@example.com', 'SKU-001', '', '2', '19.99', '', '8.50', '4.00', '5.00', 'USD', '2026-01-16', '2026-01-18', 'Imported from old shop'],
            ['SHOP-1001', '', '', '', '', '', 'SKU-002-L', '1', '29.99', '', '', '', '', '', '', '', ''],
            ['SHOP-1002', '2026-01-16', 'pending', 'Another Customer', '', 'SKU-001', '', '1', '', '', '', '', '', '', '', '', ''],
        ];

        return response()->stream(function () use ($headers, $examples) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $headers, escape: '');
            foreach ($examples as $row) {
                fputcsv($file, $row, escape: '');
            }
            fclose($file);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="order_import_template.csv"',
        ]);
    }

    /**
     * Import orders from a CSV/Excel file (one row per order line).
     *
     * `historical` records the orders without adjusting stock and never fires
     * order.created webhooks. `notify_integrations` (default on) controls the
     * order.created webhooks / plugin hooks for a stock-adjusting import.
     * Large files are queued like the product import.
     */
    public function importOrders(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => self::IMPORT_FILE_RULE,
            'historical' => 'nullable|boolean',
            'notify_integrations' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $historical = $request->boolean('historical');
        $notifyIntegrations = $request->boolean('notify_integrations', true);
        $file = $request->file('file');

        try {
            $readerType = SpreadsheetReaderType::forPath((string) $file->getRealPath());

            if ($file->getSize() > config('imports.sync_max_kb') * 1024) {
                $disk = config('imports.disk');
                $path = $file->store('imports/'.$user->organization_id, $disk);

                ProcessOrderImportJob::dispatch($user->organization_id, $user->id, $disk, $path, $historical, $notifyIntegrations, $readerType);

                return redirect()->route('import-export.index')
                    ->with('success', "Your order import is being processed. You'll be notified when it's complete.");
            }

            $import = (new OrdersImport($user, $historical, $notifyIntegrations))->importFile($file, null, $readerType);
            $stats = $import->getStats();
            $this->announceImport('orders', $user, 'completed', $stats);

            return $this->redirectWithImportResult(
                'orders',
                $stats,
                'Orders imported successfully! Created: '.$stats['imported'].', Skipped (already imported): '.$stats['skipped'],
            );
        } catch (QueryException $e) {
            // Raw SQL is not for the banner; the error handler logs it.
            throw $e;
        } catch (\Exception $e) {
            $this->announceImport('orders', $user, 'failed');
            Log::error('Order import failed', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'file' => $file?->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('import-export.index')
                ->with('error', 'Import failed: '.$e->getMessage());
        }
    }

    /**
     * Tell plugins that an import run inside the request finished (queued
     * imports announce themselves from their job).
     *
     * @param  array<string, mixed>  $stats
     */
    private function announceImport(string $type, ?User $user, string $status, array $stats = []): void
    {
        if ($user === null) {
            return;
        }

        do_action('import_finished', $type, (int) $user->organization_id, $user, [
            'status' => $status, 'queued' => false, 'stats' => $stats,
        ]);
    }

    /**
     * Redirect back to the import page with an import's outcome.
     *
     * A clean import flashes a plain success string (rendered globally). An
     * import with row errors OR warnings flashes a structured payload instead,
     * which the Import/Export page renders row by row: warnings such as
     * duplicate-SKU skips are not failures, but the user still needs to see
     * them rather than have them silently dropped.
     *
     * @param  array<string, mixed>  $stats
     */
    private function redirectWithImportResult(string $type, array $stats, string $successMessage): RedirectResponse
    {
        $errors = count($stats['errors'] ?? []);
        $warnings = count($stats['warnings'] ?? []);

        $redirect = redirect()->route('import-export.index');

        if ($errors === 0 && $warnings === 0) {
            return $redirect->with('success', $successMessage);
        }

        return $redirect->with('warning', [
            'type' => $type,
            'message' => $errors > 0 ? 'Import completed with some errors' : 'Import completed with warnings',
            'stats' => $stats,
        ]);
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

        // mode=lines exports one row per order line (see OrderLinesExport);
        // the default stays one row per order.
        $type = $request->query('mode') === 'lines' ? 'order_lines' : 'orders';

        return $this->streamOrQueueExport($request, $type, $filters);
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

        // A user export needs view_users to create (see the route), so the
        // stored file needs it to download too.
        abort_if(
            $dataExport->type === 'users' && ! $request->user()->hasPermission('view_users'),
            403,
        );

        // An export belongs to whoever requested it (it may hold data they
        // filtered for themselves); only they or an admin may fetch it. 404
        // rather than 403 so ids of colleagues' exports are not confirmed.
        $user = $request->user();
        abort_unless((int) $dataExport->user_id === (int) $user->id || $user->isAdmin(), 404);

        return Storage::disk($dataExport->disk)->download($dataExport->path, $dataExport->filename);
    }
}
