<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportExporter;
use App\Support\OrganizationCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BatchExpiryReportController extends Controller
{
    public function __invoke(Request $request, ReportExporter $exporter): Response|StreamedResponse
    {
        $validated = $request->validate([
            'window' => ['sometimes', Rule::in(['7', '30', '90', 'expired', 'all'])],
            'search' => ['nullable', 'string', 'max:200'],
            'sort_by' => ['sometimes', Rule::in(['expiry_date', 'quantity', 'product_name', 'batch_number'])],
            'sort_dir' => ['sometimes', Rule::in(['asc', 'desc'])],
            'export' => ['sometimes', Rule::in(['csv'])],
        ]);
        $filters = [
            'window' => (string) ($validated['window'] ?? '30'),
            'search' => trim($validated['search'] ?? ''),
            'sort_by' => $validated['sort_by'] ?? 'expiry_date',
            'sort_dir' => $validated['sort_dir'] ?? 'asc',
        ];
        $today = OrganizationCalendar::today($request->user()->organization);
        $asOf = $today->toDateString();

        // Batches have no warehouse/variant ledger. Each positive dated lot
        // appears once, including lots whose product has since been archived.
        $query = DB::table('product_batches as b')
            ->join('products as p', function ($join): void {
                $join->on('p.id', '=', 'b.product_id')->on('p.organization_id', '=', 'b.organization_id');
            })
            ->where('b.organization_id', $request->user()->organization_id)
            ->where('b.quantity', '>', 0)
            ->whereNotNull('b.expiry_date');

        if ($filters['window'] === 'expired') {
            $query->where('b.expiry_date', '<', $asOf);
        } elseif ($filters['window'] !== 'all') {
            // Exclusive next-day bound also includes SQLite date casts stored
            // at midnight, while keeping the expiry index usable on all DBs.
            $query->where('b.expiry_date', '<', $today->addDays((int) $filters['window'] + 1)->toDateString());
        }
        if ($filters['search'] !== '') {
            $query->where(function ($search) use ($filters): void {
                $value = '%'.$filters['search'].'%';
                $search->where('b.batch_number', 'like', $value)
                    ->orWhere('p.name', 'like', $value)->orWhere('p.sku', 'like', $value);
            });
        }

        $totals = (clone $query)->selectRaw(
            'COUNT(*) as batches, COALESCE(SUM(b.quantity), 0) as units, '
            .'COALESCE(SUM(CASE WHEN b.expiry_date < ? THEN b.quantity ELSE 0 END), 0) as expired_units',
            [$asOf],
        )->first();
        $summary = [
            'batches' => (int) $totals->batches,
            'units' => (int) $totals->units,
            'expired_units' => (int) $totals->expired_units,
            'not_expired_units' => (int) $totals->units - (int) $totals->expired_units,
        ];
        $sort = [
            'expiry_date' => 'b.expiry_date', 'quantity' => 'b.quantity',
            'product_name' => 'p.name', 'batch_number' => 'b.batch_number',
        ][$filters['sort_by']];
        $query->select('b.id', 'b.product_id', 'b.batch_number', 'b.quantity', 'b.expiry_date', 'p.name as product_name', 'p.sku', 'p.deleted_at')
            ->orderBy($sort, $filters['sort_dir'])->orderBy('b.id');
        $row = static fn (object $batch): array => [
            'id' => (int) $batch->id,
            'product_id' => (int) $batch->product_id,
            'product_name' => $batch->product_name,
            'sku' => $batch->sku,
            'batch_number' => $batch->batch_number,
            'quantity' => (int) $batch->quantity,
            'expiry_date' => substr($batch->expiry_date, 0, 10),
            'status' => substr($batch->expiry_date, 0, 10) < $asOf ? 'expired' : (substr($batch->expiry_date, 0, 10) === $asOf ? 'today' : 'upcoming'),
            'archived' => $batch->deleted_at !== null,
        ];
        $limit = max(1, (int) config('reports.max_rows', 10000));

        if (isset($validated['export'])) {
            $rows = $query->limit($limit)->get()->map($row)->map(fn (array $batch): array => [
                $batch['product_name'], $batch['sku'], $batch['batch_number'], $batch['quantity'],
                $batch['expiry_date'], $batch['status'], $batch['archived'] ? 'yes' : 'no', $asOf,
            ])->all();

            $response = $exporter->download('csv', 'Batch Expiry', [
                'Product', 'SKU', 'Batch', 'Remaining units', 'Expiry date', 'Status', 'Archived product', 'As of (organization day)',
            ], $rows);
            $response->headers->set('X-Report-Row-Limit', (string) $limit);
            $response->headers->set('X-Report-Truncated', $summary['batches'] > $limit ? 'true' : 'false');

            return $response;
        }

        return Inertia::render('Reports/BatchExpiry', [
            'batches' => $query->paginate(25)->withQueryString()->through($row),
            'summary' => $summary,
            'filters' => $filters,
            'asOf' => $asOf,
            'exportLimit' => $limit,
        ]);
    }
}
