<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\OrganizationScope;

/**
 * Marks a model as owned by an organization (tenant).
 *
 * Applies OrganizationScope so reads are automatically constrained to the
 * authenticated user's organization — the safety net behind the hand-written
 * `where('organization_id', ...)` / `organization_id !== ...` checks scattered
 * across the controllers — and stamps `organization_id` on create from the
 * authenticated user when the caller hasn't set it explicitly.
 *
 * Not applied to auth/permission infrastructure (User, Role, PermissionSet,
 * Setting): those resolve during authentication and may legitimately hold
 * null/global organization_id (e.g. system roles), where an automatic scope
 * would hide rows or recurse during user resolution.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model): void {
            if (empty($model->organization_id) && auth()->check() && auth()->user()->organization_id) {
                $model->organization_id = auth()->user()->organization_id;
            }
        });
    }
}
