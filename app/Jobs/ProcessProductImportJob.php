<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Imports\ProductsImport;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Processes a large product import off the web request and notifies the user.
 *
 * Dispatched by ImportExportController when the uploaded file exceeds the
 * synchronous size limit. Running the import here keeps a big file from timing
 * out the HTTP request; the user is notified with the resulting stats (or a
 * failure notice) when it completes. The stored upload is removed afterwards.
 */
final class ProcessProductImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $organizationId,
        public int $userId,
        public string $disk,
        public string $path,
    ) {}

    public function handle(): void
    {
        try {
            $import = new ProductsImport($this->organizationId);
            Excel::import($import, $this->path, $this->disk);

            NotificationService::createImportCompleteNotification(
                $this->organizationId,
                $this->userId,
                $import->getStats(),
            );
        } finally {
            Storage::disk($this->disk)->delete($this->path);
        }
    }

    public function failed(Throwable $exception): void
    {
        Storage::disk($this->disk)->delete($this->path);

        NotificationService::createImportFailedNotification(
            $this->organizationId,
            $this->userId,
        );
    }
}
