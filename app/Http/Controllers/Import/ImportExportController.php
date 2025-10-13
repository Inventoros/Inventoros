<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Exports\ProductsExport;
use App\Imports\ProductsImport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class ImportExportController extends Controller
{
    /**
     * Display the import/export page
     */
    public function index(Request $request): Response
    {
        return Inertia::render('ImportExport/Index');
    }

    /**
     * Export products to CSV
     */
    public function exportProducts(Request $request)
    {
        $organizationId = $request->user()->organization_id;

        $filename = 'products_' . now()->format('Y-m-d_His') . '.xlsx';

        return Excel::download(
            new ProductsExport($organizationId),
            $filename
        );
    }

    /**
     * Download product import template
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
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Import products from CSV
     */
    public function importProducts(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240', // 10MB max
        ]);

        try {
            $organizationId = $request->user()->organization_id;

            $import = new ProductsImport($organizationId);
            Excel::import($import, $request->file('file'));

            $stats = $import->getStats();

            if (count($stats['errors']) > 0) {
                return redirect()->route('import-export.index')
                    ->with('warning', [
                        'message' => 'Import completed with some errors',
                        'stats' => $stats,
                    ]);
            }

            return redirect()->route('import-export.index')
                ->with('success', 'Products imported successfully! Created: ' . $stats['imported'] . ', Updated: ' . $stats['updated']);
        } catch (\Exception $e) {
            return redirect()->route('import-export.index')
                ->with('error', 'Import failed: ' . $e->getMessage());
        }
    }
}
