<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Imports\ProductsImport;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Organizations\ActiveOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * Spread retries out so a transiently-unavailable DB or mail backend has
     * time to recover. ProductsImport upserts by SKU, so re-running a
     * partially-completed import is safe.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(
        public int $organizationId,
        public int $userId,
        public string $disk,
        public string $path,
        // Chosen from the detected upload content by the controller; null for
        // jobs queued before this was recorded (the reader then follows the name).
        public ?string $readerType = null,
    ) {}

    public function handle(): void
    {
        // Working in the import's organization (not necessarily their home
        // one), and only while they are still a member of it.
        $importer = app(ActiveOrganization::class)->userIn($this->userId, $this->organizationId)
            ?? throw (new ModelNotFoundException)->setModel(User::class, [$this->userId]);
        $import = new ProductsImport($this->organizationId, $importer);
        Excel::import($import, $this->path, $this->disk, $this->readerType);

        NotificationService::createImportCompleteNotification(
            $this->organizationId,
            $this->userId,
            $import->getStats(),
        );

        do_action('import_finished', 'products', $this->organizationId, $importer, [
            'status' => 'completed', 'queued' => true, 'stats' => $import->getStats(),
        ]);

        // Only remove the upload once the import has fully succeeded; a
        // retryable failure must leave the file in place for the next attempt.
        // failed() removes it after the final attempt.
        Storage::disk($this->disk)->delete($this->path);
    }

    public function failed(Throwable $exception): void
    {
        Storage::disk($this->disk)->delete($this->path);

        NotificationService::createImportFailedNotification(
            $this->organizationId,
            $this->userId,
        );

        do_action('import_finished', 'products', $this->organizationId, User::withoutGlobalScopes()->find($this->userId), [
            'status' => 'failed', 'queued' => true, 'stats' => [],
        ]);
    }
}
