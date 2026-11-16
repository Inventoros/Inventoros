<?php

declare(strict_types=1);

namespace App\GraphQL\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * An `exists` rule limited to the caller's organization, so a GraphQL input
 * id from another tenant fails exactly like an id that does not exist. The
 * organization is read when the rule runs, not when the rule is declared:
 * input types are built with the schema, which may happen before (or be
 * reused after) the request is authenticated.
 */
final class OrganizationExists
{
    public static function rule(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where(
            fn (Builder $query) => $query->where('organization_id', (int) auth()->user()?->organization_id)
        );
    }
}
