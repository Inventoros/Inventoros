<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Concerns\RequiresPermissions;
use App\Models\User;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class UsersQuery extends Query
{
    use RequiresPermissions;

    protected $attributes = [
        'name' => 'users',
        'description' => 'List users in the organization (read-only)',
    ];

    protected function permissions(): array
    {
        return ['view_users'];
    }

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('User'));
    }

    public function args(): array
    {
        return [
            'search' => ['type' => Type::string(), 'description' => 'Search by name or email'],
            'role' => ['type' => Type::string(), 'description' => 'Filter by base role: admin, manager or member'],
            'limit' => ['type' => Type::int(), 'description' => 'Maximum number of results (default: 50, max: 100)'],
        ];
    }

    public function resolve($root, array $args)
    {
        return User::with('roles')
            ->forOrganization($this->organizationId())
            ->when($args['search'] ?? null, function ($query, $search) {
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->when($args['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->orderBy('name')
            ->limit(max(1, min($args['limit'] ?? 50, 100)))
            ->get();
    }
}
