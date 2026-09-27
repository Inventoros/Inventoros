<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\Inventory\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Work-order operations shared by the web WorkOrderController, the REST API
 * and the MCP server.
 */
final class WorkOrderService
{
    /**
     * Statuses in which a work order has never consumed or produced stock,
     * so deleting it leaves nothing to reverse.
     */
    public const DELETABLE_STATUSES = ['draft', 'cancelled'];

    /**
     * Delete a draft or cancelled work order and its component lines.
     *
     * @throws InvalidStateException when the work order has started, completed or otherwise moved stock
     */
    public function delete(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder) {
            // Lock and re-check so a concurrent start cannot slip in between
            // the guard and the delete.
            $locked = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::DELETABLE_STATUSES, true)) {
                throw new InvalidStateException('Only draft or cancelled work orders can be deleted.', 'invalid_status');
            }

            $locked->items()->delete();
            $locked->delete();
        });
    }
}
