<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Inventory\InventoryResetRun;
use App\Models\User;
use App\Services\InventoryResetService;
use App\Services\Organizations\ActiveOrganization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class RunInventoryReset implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId) {}

    public function handle(InventoryResetService $resets, ActiveOrganization $organizations): void
    {
        // Claim once, including when a queue redelivers a job whose worker is
        // still running. The long transaction below also locks this run row.
        if (! InventoryResetRun::whereKey($this->runId)->where('status', 'pending')
            ->update(['status' => 'running', 'started_at' => now()])) {
            return;
        }
        $run = InventoryResetRun::findOrFail($this->runId);
        try {
            $actor = User::find($run->actor_id);
            abort_unless($actor, 403);
            $organizations->runAs($actor, (int) $run->organization_id, 'manage_stock', function (User $member) use ($resets): void {
                DB::transaction(function () use ($member, $resets): void {
                    $locked = InventoryResetRun::whereKey($this->runId)->lockForUpdate()->firstOrFail();
                    $counts = $resets->reset($member, $locked->signature, $locked->reason, $locked);
                    $locked->update(['status' => 'completed', 'counts' => $counts, 'finished_at' => now()]);
                }, 3);
            });
        } catch (Throwable $error) {
            $code = $error instanceof ValidationException
                ? ($error->errors()['reset'][0] ?? 'stockReset.errors.failed')
                : 'stockReset.errors.failed';
            $this->markFailed($code);
            if (! $error instanceof ValidationException) {
                Log::error('Inventory reset failed', ['run_id' => $this->runId, 'exception_type' => $error::class]);
            }
        }
    }

    public function failed(?Throwable $error): void
    {
        // A timeout can invoke failed() while interrupted inside the stock
        // transaction. Roll it back before persisting the durable failure.
        if ($error instanceof TimeoutExceededException && DB::transactionLevel() > 0) {
            DB::rollBack(0);
        }
        $this->markFailed('stockReset.errors.failed');
    }

    private function markFailed(string $code): void
    {
        DB::transaction(function () use ($code): void {
            $run = InventoryResetRun::whereKey($this->runId)->lockForUpdate()->first();
            if ($run !== null && in_array($run->status, ['pending', 'running'], true)) {
                $run->update(['status' => 'failed', 'error_code' => $code, 'finished_at' => now()]);
            }
        });
    }
}
