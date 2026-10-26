<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

final class InventoryWorkflowLock
{
    public static function acquire(int $organizationId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The inventory workflow lock requires a transaction.');
        }
        DB::table('inventory_workflow_locks')->insertOrIgnore(['organization_id' => $organizationId]);
        DB::table('inventory_workflow_locks')->where('organization_id', $organizationId)->lockForUpdate()->first();
    }
}
