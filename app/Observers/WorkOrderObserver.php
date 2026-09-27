<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Inventory\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Fires the work-order completion action hook after commit, whichever surface
 * (web or REST) completed the work order.
 */
final class WorkOrderObserver
{
    public function updated(WorkOrder $workOrder): void
    {
        if ($workOrder->isDirty('status') && $workOrder->status === 'completed') {
            DB::afterCommit(fn () => do_action('work_order_completed', $workOrder, auth()->user()));
        }
    }
}
