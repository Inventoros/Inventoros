<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasSlug
{
    /**
     * Boot the trait.
     */
    public static function bootHasSlug(): void
    {
        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = $model->generateUniqueSlug($model->getSlugSource());
            }
        });

        static::updating(function ($model) {
            // Only regenerate slug if the source field changed and slug should update
            if ($model->shouldUpdateSlugOnChange() && $model->isDirty($model->getSlugSourceField())) {
                $model->slug = $model->generateUniqueSlug($model->getSlugSource(), $model->getKey());
            }
        });
    }

    /**
     * Get the source value for the slug.
     */
    protected function getSlugSource(): string
    {
        return $this->{$this->getSlugSourceField()} ?? '';
    }

    /**
     * Get the field to use as slug source. Override to customize.
     */
    protected function getSlugSourceField(): string
    {
        return 'name';
    }

    /**
     * Whether the slug should be unique within scope. Override to customize.
     */
    protected function slugShouldBeUnique(): bool
    {
        return true;
    }

    /**
     * Whether to update slug when source field changes. Override to customize.
     */
    protected function shouldUpdateSlugOnChange(): bool
    {
        return false;
    }

    /**
     * Get the scope column for uniqueness (e.g., 'organization_id'). Override to customize.
     * Return null for global uniqueness.
     */
    protected function getSlugScopeColumn(): ?string
    {
        return property_exists($this, 'organization_id') || isset($this->organization_id)
            ? 'organization_id'
            : null;
    }

    /**
     * Generate a unique slug.
     */
    public function generateUniqueSlug(string $source, $excludeId = null): string
    {
        $slug = Str::slug($source);

        if (!$this->slugShouldBeUnique()) {
            return $slug;
        }

        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug, $excludeId)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Check if a slug already exists.
     */
    protected function slugExists(string $slug, $excludeId = null): bool
    {
        $query = static::where('slug', $slug);

        // Apply scope if defined
        $scopeColumn = $this->getSlugScopeColumn();
        if ($scopeColumn && isset($this->{$scopeColumn})) {
            $query->where($scopeColumn, $this->{$scopeColumn});
        }

        // Exclude current model when updating
        if ($excludeId !== null) {
            $query->where($this->getKeyName(), '!=', $excludeId);
        }

        return $query->exists();
    }
}
