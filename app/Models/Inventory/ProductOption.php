<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the number of values for this option.
     */
    public function getValueCountAttribute(): int
    {
        return count($this->values ?? []);
    }

    /**
     * Check if a value exists in this option.
     */
    public function hasValue(string $value): bool
    {
        return in_array($value, $this->values ?? [], true);
    }

    /**
     * Add a value to this option.
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
     */
    public function removeValue(string $value): void
    {
        $values = array_filter($this->values ?? [], fn($v) => $v !== $value);
        $this->values = array_values($values);
        $this->save();
    }

    /**
     * Scope to order by position.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }
}
