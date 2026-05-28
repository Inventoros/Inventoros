<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that constrains every query for a tenant-owned model to the
 * authenticated user's organization.
 *
 * Skips entirely when there is no authenticated user (background jobs, the
 * scheduler, console commands, the installer, and login itself), so those
 * contexts keep their existing cross-tenant or payload-driven behaviour. When
 * a script genuinely needs to reach across tenants while authenticated, it can
 * opt out per query with `Model::withoutGlobalScope(OrganizationScope::class)`.
 */
final class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // auth()->check() resolves the session user via the provider, which
        // queries the User model — User deliberately does NOT use this scope,
        // so there is no resolution recursion.
        if (! auth()->check()) {
            return;
        }

        $organizationId = auth()->user()->organization_id;

        if ($organizationId === null) {
            return;
        }

        $builder->where($model->getTable().'.organization_id', $organizationId);
    }
}
