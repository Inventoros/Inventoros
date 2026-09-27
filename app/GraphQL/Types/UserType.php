<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\User;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

/**
 * An organization user. Deliberately exposes no credential, two-factor or
 * token fields.
 */
class UserType extends GraphQLType
{
    protected $attributes = [
        'name' => 'User',
        'description' => 'A user in the organization',
        'model' => User::class,
    ];

    public function fields(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the user'],
            'name' => ['type' => Type::nonNull(Type::string()), 'description' => 'Name'],
            'email' => ['type' => Type::nonNull(Type::string()), 'description' => 'Email address'],
            'role' => ['type' => Type::string(), 'description' => 'Base role: admin, manager or member'],
            'two_factor_enabled' => [
                'type' => Type::boolean(),
                'description' => 'Whether two-factor authentication is on',
                'resolve' => fn (User $user) => (bool) $user->two_factor_enabled,
            ],
            'roles' => [
                'type' => Type::listOf(GraphQL::type('Role')),
                'description' => 'Custom roles assigned to the user',
                'resolve' => fn (User $user) => $user->roles,
            ],
            'created_at' => [
                'type' => Type::string(),
                'description' => 'Creation timestamp',
                'resolve' => fn (User $user) => $user->created_at?->toIso8601String(),
            ],
        ];
    }
}
