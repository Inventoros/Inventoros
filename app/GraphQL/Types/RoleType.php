<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Role;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Type as GraphQLType;

class RoleType extends GraphQLType
{
    protected $attributes = [
        'name' => 'Role',
        'description' => 'A role granting a set of permissions',
        'model' => Role::class,
    ];

    public function fields(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the role'],
            'name' => ['type' => Type::nonNull(Type::string()), 'description' => 'Role name'],
            'slug' => ['type' => Type::nonNull(Type::string()), 'description' => 'Role slug'],
            'is_system' => ['type' => Type::boolean(), 'description' => 'Whether this is a built-in role'],
        ];
    }
}
