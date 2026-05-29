<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exports\ExportFactory;
use App\Models\DataExport;
use App\Models\Scopes\OrganizationScope;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Generates a large export file off the web request and notifies the user.
 *
 * Dispatched by ImportExportController when an export exceeds the synchronous
 * row limit. The work — querying and writing the spreadsheet — runs here on the
 * queue instead of in the request lifecycle, so an arbitrarily large export can
 * no longer time out the HTTP request or exhaust request memory.
 */
final class GenerateDataExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $exportId) {}

    public function handle(): void
    {
        // No authenticated user on the queue, so the organization scope is a
        // no-op here; opt out explicitly to make the cross-context intent clear.
        $export = DataExport::withoutGlobalScope(OrganizationScope::class)->findOrFail($this->exportId);

        $export->update(['status' => 'processing']);

        $writer = ExportFactory::make($export->type, $export->organization_id, $export->filters ?? []);

        Excel::store($writer, $export->path, $export->disk);

        $export->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        NotificationService::createExportReadyNotification($export);
    }

    public function failed(Throwable $exception): void
    {
        $export = DataExport::withoutGlobalScope(OrganizationScope::class)->find($this->exportId);

        if ($export === null) {
            return;
        }

        $export->update([
            'status' => 'failed',
            'error' => Str::limit($exception->getMessage(), 500),
        ]);

        NotificationService::createExportFailedNotification($export);
    }
}
