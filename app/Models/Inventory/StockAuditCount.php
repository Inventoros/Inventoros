<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Accepted count evidence; subsequent recounts append a new row. */
final class StockAuditCount extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'system_quantity' => 'integer', 'counted_quantity' => 'integer',
            'discrepancy' => 'integer', 'is_snapshot' => 'boolean', 'counted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Saved count evidence cannot be changed.'));
        self::deleting(static fn () => throw new LogicException('Saved count evidence cannot be deleted.'));
    }
}
