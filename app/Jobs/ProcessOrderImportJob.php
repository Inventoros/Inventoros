<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Imports\OrdersImport;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Organizations\ActiveOrganization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Processes a large order import off the web request and notifies the user.
 *
 * Mirrors ProcessProductImportJob. Retrying is safe: OrdersImport skips any
 * external_reference already imported, so orders created by a partially
 * completed attempt are not duplicated.
 */
final class ProcessOrderImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /**
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
        public bool $historical,
        public bool $notifyIntegrations = true,
        // Chosen from the detected upload content by the controller; null for
        // jobs queued before this was recorded (the reader then follows the name).
        public ?string $readerType = null,
    ) {}

    public function handle(): void
    {
        // The importer as they were when they queued the import: working in
        // that organization (which need not be their home one), and only
        // while they are still a member of it.
        $importer = app(ActiveOrganization::class)->userIn($this->userId, $this->organizationId)
            ?? throw (new ModelNotFoundException)->setModel(User::class, [$this->userId]);

        $import = (new OrdersImport($importer, $this->historical, $this->notifyIntegrations))
            ->importFile($this->path, $this->disk, $this->readerType);

        NotificationService::createImportCompleteNotification(
            $this->organizationId,
            $this->userId,
            $import->getStats(),
            'order',
        );

        do_action('import_finished', 'orders', $this->organizationId, $importer, [
            'status' => 'completed', 'queued' => true, 'stats' => $import->getStats(),
        ]);

        Storage::disk($this->disk)->delete($this->path);
    }

    public function failed(Throwable $exception): void
    {
        Storage::disk($this->disk)->delete($this->path);

        NotificationService::createImportFailedNotification(
            $this->organizationId,
            $this->userId,
            'order',
        );

        do_action('import_finished', 'orders', $this->organizationId, User::withoutGlobalScopes()->find($this->userId), [
            'status' => 'failed', 'queued' => true, 'stats' => [],
        ]);
    }
}
