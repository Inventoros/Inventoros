<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * The organization tenant-owned queries are constrained to.
 *
 * Normally that is the authenticated user's active organization (their home
 * organization, the one chosen with the organization switcher, or the one
 * their API token is bound to). Core services that must work inside another
 * organization the actor has ALREADY been authorized for (the inter-company
 * transfer service, for instance) run a callback with run(), which narrows
 * every scoped query, and the organization stamped on new rows, to that
 * organization for the duration of the callback only.
 *
 * Bound as a scoped instance: an override can never outlive the request or
 * queued job that set it.
 *
 * @internal Plugins call ActiveOrganization::runAs(), which checks membership
 *           and permissions first.
 */
final class OrganizationContext
{
    /** @var list<int> */
    private array $overrides = [];

    /**
     * Whether queries should be scoped at all. Unauthenticated contexts
     * (queued jobs, the scheduler, console commands, the installer, sign-in)
     * stay unscoped unless a service runs them inside an organization.
     */
    public function isScoped(): bool
    {
        return $this->overrides !== [] || auth()->check();
    }

    /**
     * The organization to scope to: the innermost run() override, else the
     * authenticated user's active organization. Null while scoped means the
     * caller owns no tenant data (fail closed).
     */
    public function id(): ?int
    {
        if ($this->overrides !== []) {
            return $this->overrides[array_key_last($this->overrides)];
        }

        $organizationId = auth()->user()?->organization_id;

        return $organizationId === null ? null : (int) $organizationId;
    }

    /**
     * Run $callback with every scoped query constrained to $organizationId.
     * Authorization is the caller's job; this only narrows the scope.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(int $organizationId, callable $callback): mixed
    {
        $this->overrides[] = $organizationId;

        try {
            return $callback();
        } finally {
            array_pop($this->overrides);
        }
    }
}
