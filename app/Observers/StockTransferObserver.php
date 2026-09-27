<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Inventory\StockTransfer;
use Illuminate\Support\Facades\DB;

/**
 * Fires stock-transfer lifecycle action hooks after commit, so the created
 * payload includes the transfer's lines.
 */
final class StockTransferObserver
{
    public function created(StockTransfer $transfer): void
    {
        DB::afterCommit(fn () => do_action('transfer_created', $transfer, auth()->user()));
    }

    public function updated(StockTransfer $transfer): void
    {
        if ($transfer->isDirty('status') && $transfer->status === 'completed') {
            DB::afterCommit(fn () => do_action('transfer_completed', $transfer, auth()->user()));
        }
    }
}
