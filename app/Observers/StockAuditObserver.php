<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Inventory\StockAudit;
use Illuminate\Support\Facades\DB;

/**
 * Fires the stock-audit completion action hook after commit, so subscribers
 * see the audit only once its recount adjustments are durable.
 */
final class StockAuditObserver
{
    public function updated(StockAudit $stockAudit): void
    {
        if ($stockAudit->isDirty('status') && $stockAudit->status === 'completed') {
            DB::afterCommit(fn () => do_action('stock_audit_completed', $stockAudit, auth()->user()));
        }
    }
}
