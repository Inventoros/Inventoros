<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\User;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class UserQuery extends Query
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'user',
        'description' => 'Get a single user in the organization by ID (read-only)',
    ];

    protected function permissions(): array
    {
        return ['view_users'];
    }

    public function type(): Type
    {
        return GraphQL::type('User');
    }

    public function args(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the user'],
        ];
    }

    public function resolve($root, array $args)
    {
        return User::with('roles')
            ->forOrganization($this->organizationId())
            ->find($args['id']) ?? throw new Error('User not found');
    }
}
