<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents an option (like size or color) for a product with variants.
 *
 * @property int $id
 * @property int $product_id
 * @property string $name
 * @property int $position
 * @property array|null $values
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read int $value_count
 * @property-read \App\Models\Inventory\Product $product
 */
class ProductOption extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'name',
        'position',
        'values',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'values' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * Get the product that owns this option.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Inventory\Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the number of values for this option.
     *
     * @return int
     */
    public function getValueCountAttribute(): int
    {
        return count($this->values ?? []);
    }

    /**
     * Check if a value exists in this option.
     *
     * @param string $value
     * @return bool
     */
    public function hasValue(string $value): bool
    {
        return in_array($value, $this->values ?? [], true);
    }

    /**
     * Add a value to this option.
     *
     * @param string $value
     * @return void
     */
    public function addValue(string $value): void
    {
        if (!$this->hasValue($value)) {
            $values = $this->values ?? [];
            $values[] = $value;
            $this->values = $values;
            $this->save();
        }
    }

    /**
     * Remove a value from this option.
     *
     * @param string $value
     * @return void
     */
    public function removeValue(string $value): void
    {
        $values = array_filter($this->values ?? [], fn($v) => $v !== $value);
        $this->values = array_values($values);
        $this->save();
    }

    /**
     * Scope to order by position.
     *
     * @param \Illuminate\Database\Eloquent\Builder<static> $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }
}
