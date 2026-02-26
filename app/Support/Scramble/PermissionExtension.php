<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

class PermissionExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $route = $routeInfo->route;
        $middlewares = $route->gatherMiddleware();

        $permissions = [];
        foreach ($middlewares as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'api.permission:')) {
                $permString = substr($middleware, strlen('api.permission:'));
                $permissions = array_merge($permissions, explode('|', $permString));
            }
        }

        if (empty($permissions)) {
            return;
        }

        $permList = implode(', ', array_map(fn ($p) => "`{$p}`", $permissions));
        $permNote = "**Required permissions:** {$permList}";

        $existing = $operation->description;
        $operation->description = $existing
            ? "{$permNote}\n\n{$existing}"
            : $permNote;

        $errorSchema = (new ObjectType())
            ->addProperty('message', new StringType())
            ->addProperty('error', new StringType());

        $operation->addResponse(
            (new Response(401))
                ->setDescription('Unauthenticated — invalid or missing token')
                ->setContent('application/json', Schema::fromType($errorSchema))
        );

        $operation->addResponse(
            (new Response(403))
                ->setDescription('Forbidden — insufficient permissions')
                ->setContent('application/json', Schema::fromType($errorSchema))
        );
    }
}
