<?php

declare(strict_types=1);

namespace App\GraphQL\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class ListResourceLimits
{
    public const MAX_RESULTS = 100;

    public const MAX_RESPONSE_NODES = 50_000;

    /**
     * Bound a relationship at the database, reusing an existing eager load.
     */
    public static function relation(Model $model, string $relation): Collection
    {
        if ($model->relationLoaded($relation)) {
            return $model->getRelation($relation)->take(self::MAX_RESULTS);
        }

        return $model->{$relation}()->limit(self::MAX_RESULTS)->get();
    }

    /**
     * Keep nested eager loads bounded per parent, including item collections
     * loaded even when the client does not select their GraphQL fields.
     *
     * @return array<string, callable>
     */
    public static function eager(string $relation): array
    {
        $root = explode('.', $relation, 2)[0];

        return [
            $relation => static fn ($query) => $query,
            $root => static fn ($query) => $query->limit(self::MAX_RESULTS),
        ];
    }
}
