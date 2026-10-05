<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\Permission;
use App\Enums\SecurityEvent;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\SecurityEventLogger;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * The organization a user works in for the current request.
 *
 * Every tenant check in the application reads `$user->organization_id`
 * (OrganizationScope, controllers, services, policies). This service makes
 * that attribute mean "the active organization" on the authenticated
 * instance, so the existing checks apply unchanged to whichever organization
 * is active:
 *
 * - a browser session works in the user's home organization until they
 *   switch (switchTo()); the choice is stored in the session, checked against
 *   the user's memberships on every request, and dropped at sign-in;
 * - an API token (REST, GraphQL, MCP) works in the organization it was
 *   created in, whatever the browser session has switched to;
 * - queued jobs and plugins load a user into a given organization with
 *   userIn(), or run work inside one with runAs(), both of which check the
 *   membership (and permissions) first.
 *
 * Nothing is written to the user row: users.organization_id remains the home
 * organization that owns the account.
 *
 * @api
 */
final class ActiveOrganization
{
    public const SESSION_KEY = 'organization.active';

    public function __construct(
        private readonly OrganizationMembershipService $memberships,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * Activate $organizationId on $user (this instance, in memory) when they
     * are a member. Returns false, leaving the user as it was, otherwise.
     */
    public function activate(User $user, int $organizationId): bool
    {
        $membership = $this->memberships->resolve($user, $organizationId);

        if ($membership === null) {
            return false;
        }

        $user->useOrganization($organizationId, $membership['role']);

        return true;
    }

    /**
     * A copy of $user (or the user with that id) working in $organizationId,
     * or null when they are not a member. For queued jobs and plugins that
     * recorded the organization a user acted in: never use the stored row
     * as-is, its organization_id is the home organization.
     *
     * @api
     */
    public function userIn(User|int $user, int $organizationId): ?User
    {
        $copy = $user instanceof User ? clone $user : User::query()->find($user);

        if ($copy === null || ! $this->activate($copy, $organizationId)) {
            return null;
        }

        return $copy;
    }

    /**
     * Run $callback inside $organizationId as $user: every scoped query and
     * every new tenant row is confined to that organization, and $callback
     * receives a copy of the user working there to pass to core services.
     * Throws unless the user is a member holding every one of $permissions
     * there.
     *
     * @template T
     *
     * @param  Permission|string|array<int, Permission|string>  $permissions
     * @param  callable(User): T  $callback
     * @return T
     *
     * @throws AuthorizationException
     *
     * @api
     */
    public function runAs(User $user, int $organizationId, Permission|string|array $permissions, callable $callback): mixed
    {
        $member = $this->authorize($user, $organizationId, $permissions);

        return $this->context->run($organizationId, fn () => $callback($member));
    }

    /**
     * The user working in $organizationId, after checking the membership and
     * every one of $permissions there.
     *
     * @param  Permission|string|array<int, Permission|string>  $permissions
     *
     * @throws AuthorizationException
     *
     * @api
     */
    public function authorize(User $user, int $organizationId, Permission|string|array $permissions = []): User
    {
        $member = $this->userIn($user, $organizationId);

        if ($member === null) {
            throw new AuthorizationException('You are not a member of that organization.');
        }

        $permissions = is_array($permissions) ? $permissions : [$permissions];

        if ($permissions !== [] && ! $member->hasAllPermissions($permissions)) {
            throw new AuthorizationException('You do not have permission to do that in that organization.');
        }

        return $member;
    }

    /**
     * Switch the signed-in browser session to another organization the user
     * is a member of. The session id and CSRF token are regenerated and the
     * old session destroyed (so a session id or form captured before the
     * switch is useless after it), and the active warehouse, which belongs
     * to the previous organization, is cleared.
     *
     * @throws AuthorizationException when the user is not a member
     */
    public function switchTo(Request $request, User $user, int $organizationId): void
    {
        $from = $user->organization_id === null ? null : (int) $user->organization_id;

        if (! $this->activate($user, $organizationId)) {
            throw new AuthorizationException('You are not a member of that organization.');
        }

        $session = $request->session();
        $session->forget('active_warehouse_id');
        $session->put(self::SESSION_KEY, ['user_id' => (int) $user->getKey(), 'organization_id' => $organizationId]);
        // Destroy the old session: an id captured before the switch must
        // not keep a signed-in session alive.
        $session->regenerate(true);
        $session->regenerateToken();

        if ($from !== $organizationId) {
            $security = app(SecurityEventLogger::class);
            $properties = ['from_organization_id' => $from, 'to_organization_id' => $organizationId];

            if ($from !== null) {
                $security->record(SecurityEvent::ORGANIZATION_SWITCHED, $user, $user, $properties, organizationId: $from);
            }
            $security->record(SecurityEvent::ORGANIZATION_SWITCHED, $user, $user, $properties, organizationId: $organizationId);

            do_action('organization_switched', $user, $from, $organizationId);
        }
    }

    /**
     * Event listener: a user was resolved from the session (or signed in).
     * Re-apply the organization chosen with the switcher when it still is
     * one of theirs; otherwise they work in their home organization.
     */
    public function handleAuthenticated(Authenticated $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        // A token-authenticated request works in the token's organization.
        if ($user->currentAccessToken() instanceof PersonalAccessToken && $user->currentAccessToken()->exists === true) {
            return;
        }

        $session = $this->session();
        $stored = $session?->get(self::SESSION_KEY);

        if (is_array($stored) && (int) ($stored['user_id'] ?? 0) === (int) $user->getKey() && isset($stored['organization_id'])) {
            if ($this->activate($user, (int) $stored['organization_id'])) {
                return;
            }

            // The membership was withdrawn (or the organization disabled).
            $session->forget(self::SESSION_KEY);
            $session->forget('active_warehouse_id');
        }

        $this->restoreHome($user);
    }

    /**
     * Event listener: a fresh sign-in always starts in the home organization.
     */
    public function handleLogin(Login $event): void
    {
        // Staff sign-ins only: a customer portal sign-in in the same browser
        // leaves the staff session's organization alone.
        if (! $event->user instanceof User) {
            return;
        }

        $session = $this->session();

        if ($session !== null && $session->has(self::SESSION_KEY)) {
            $session->forget(self::SESSION_KEY);
            $session->forget('active_warehouse_id');
        }
    }

    /**
     * Bind a token-authenticated user to the token's organization. Called by
     * User::withAccessToken(); tokens of withdrawn memberships were already
     * refused by tokenIsUsable().
     */
    public function bindToToken(User $user, PersonalAccessToken $token): void
    {
        $organizationId = $token->organization_id === null ? $user->homeOrganizationId() : (int) $token->organization_id;

        if ($organizationId === null || ! $this->activate($user, $organizationId)) {
            // Fail closed: no organization, no tenant data.
            $user->useOrganization(null, null);
        }
    }

    /**
     * Sanctum token validation: a token bound to an organization its user no
     * longer belongs to does not authenticate.
     */
    public function tokenIsUsable(mixed $token, bool $isValid): bool
    {
        if (! $isValid) {
            return false;
        }

        if (! $token instanceof PersonalAccessToken) {
            return true;
        }

        $user = $token->tokenable;

        if (! $user instanceof User) {
            return false;
        }

        // A token from before organizations were bound works at home.
        $organizationId = $token->organization_id === null ? $user->homeOrganizationId() : (int) $token->organization_id;

        return $organizationId === null || $this->memberships->isMember($user, $organizationId);
    }

    /**
     * Back to the home organization. When it is disabled (or deleted), the
     * first other organization the user may work in, else none at all: the
     * user then reaches no tenant data and holds no role.
     */
    private function restoreHome(User $user): void
    {
        $home = $user->homeOrganizationId();

        if ($home === null) {
            if ($user->isInGuestOrganization()) {
                $user->useOrganization(null, $user->homeRole());
            }

            return;
        }

        if ($this->activate($user, $home)) {
            return;
        }

        foreach ($this->memberships->organizationsFor($user) as $organization) {
            if ($this->activate($user, (int) $organization->id)) {
                return;
            }
        }

        $user->useOrganization(null, null);
    }

    private function session(): ?Session
    {
        $request = app('request');

        return $request instanceof Request && $request->hasSession() ? $request->session() : null;
    }
}
