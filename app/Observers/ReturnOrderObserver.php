<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Order\ReturnOrder;
use Illuminate\Support\Facades\DB;

/**
 * Fires return (RMA) lifecycle action hooks after commit, so the created
 * payload includes the return's lines and a rolled-back receive never fires.
 */
final class ReturnOrderObserver
{
    public function created(ReturnOrder $returnOrder): void
    {
        DB::afterCommit(fn () => do_action('return_created', $returnOrder, auth()->user()));
    }

    public function updated(ReturnOrder $returnOrder): void
    {
        if ($returnOrder->isDirty('status') && $returnOrder->status === 'received') {
            DB::afterCommit(fn () => do_action('return_received', $returnOrder, auth()->user()));
        }
    }
}
