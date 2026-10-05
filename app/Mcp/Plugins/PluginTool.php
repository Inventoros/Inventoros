<?php

declare(strict_types=1);

namespace App\Mcp\Plugins;

use App\Http\Middleware\CheckApiPermission;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Wraps a tool a plugin registered with register_mcp_tool(), so core, not
 * the plugin, enforces who may see and call it: the acting user must hold
 * one of the tool's permissions AND the bearer token must allow it (the same
 * rule as the core tools and the REST API). A tool the caller may not use is
 * left out of tools/list and refused if called by name.
 */
final class PluginTool extends Tool
{
    /**
     * @param  array<int, string>  $permissions  Any of these grants access.
     */
    public function __construct(
        private readonly Tool $inner,
        public readonly string $plugin,
        public readonly array $permissions,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function title(): string
    {
        return $this->inner->title();
    }

    public function description(): string
    {
        return $this->inner->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->inner->schema($schema);
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return $this->inner->outputSchema($schema);
    }

    public function toArray(): array
    {
        return $this->inner->toArray();
    }

    public function inner(): Tool
    {
        return $this->inner;
    }

    /**
     * Listed only for callers who may use it.
     */
    public function shouldRegister(): bool
    {
        return $this->allows($this->actingUser());
    }

    public function handle(Request $request): mixed
    {
        if (! $this->allows($this->actingUser())) {
            return Response::error('Token lacks any of the required permissions: '.implode(', ', $this->permissions));
        }

        return Container::getInstance()->call([$this->inner, 'handle'], ['request' => $request]);
    }

    private function actingUser(): ?User
    {
        $user = Auth::guard('sanctum')->user() ?? request()?->user();

        return $user instanceof User ? $user : null;
    }

    private function allows(?User $user): bool
    {
        if ($user === null || $user->organization_id === null) {
            return false;
        }

        $tokenAllows = CheckApiPermission::tokenAllows($user->currentAccessToken());

        foreach ($this->permissions as $permission) {
            if ($user->hasPermission($permission) && $tokenAllows($permission)) {
                return true;
            }
        }

        return false;
    }
}
