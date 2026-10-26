<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

class InventoryResetRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
