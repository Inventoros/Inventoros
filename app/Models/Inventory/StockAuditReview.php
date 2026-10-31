<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class StockAuditReview extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['count_sequence' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Count review evidence cannot be changed.'));
        self::deleting(static fn () => throw new LogicException('Count review evidence cannot be deleted.'));
    }
}
