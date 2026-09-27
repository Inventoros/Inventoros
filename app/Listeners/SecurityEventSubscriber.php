<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\SecurityEvent;
use App\Models\User;
use App\Services\SecurityEventLogger;
use App\Services\UserActivityAlertService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Events\Dispatcher;
use Laravel\Sanctum\Sanctum;

/**
 * Records framework auth events and API token lifecycle to the security
 * activity log.
 *
 * Methods are named on* (not handle*) so Laravel's listener discovery does not
 * register them a second time alongside subscribe().
 */
final class SecurityEventSubscriber
{
    public function __construct(
        private readonly SecurityEventLogger $logger,
        private readonly UserActivityAlertService $alerts,
    ) {}

    public function onLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->logger->record(SecurityEvent::LOGIN, $event->user, $event->user, [
            'guard' => $event->guard,
            'remember' => $event->remember,
        ]);
    }

    public function onLogout(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->logger->record(SecurityEvent::LOGOUT, $event->user, $event->user, [
            'guard' => $event->guard,
        ]);
    }

    public function onFailed(Failed $event): void
    {
        // Only the email is taken from the credentials. The password is never
        // read, copied, or logged.
        $email = isset($event->credentials['email']) ? (string) $event->credentials['email'] : null;

        // Customer portal sign-ins are recorded by the portal itself, against
        // the contact, so they never read as a staff account's failed login.
        if ($event->guard === 'customer') {
            return;
        }

        if (! $event->user instanceof User) {
            $this->logger->recordUnknownAccount(SecurityEvent::LOGIN_FAILED, $email);

            return;
        }

        $this->logger->record(SecurityEvent::LOGIN_FAILED, $event->user, null, [
            'email' => $email,
            'guard' => $event->guard,
        ]);

        $this->alerts->checkRepeatedFailedLogins($event->user);
    }

    public function onLockout(Lockout $event): void
    {
        $email = (string) $event->request->input('email', '');
        $user = $email !== '' ? User::where('email', $email)->first() : null;

        $this->logger->recordLockout($user, $email !== '' ? $email : null);
    }

    public function onPasswordResetLinkSent(PasswordResetLinkSent $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->logger->record(SecurityEvent::PASSWORD_RESET_REQUESTED, $event->user);
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->logger->record(SecurityEvent::PASSWORD_RESET, $event->user, $event->user);
    }

    public function onTokenCreated($token): void
    {
        $this->recordToken(SecurityEvent::API_TOKEN_CREATED, $token);
    }

    public function onTokenDeleted($token): void
    {
        $this->recordToken(SecurityEvent::API_TOKEN_REVOKED, $token);
    }

    private function recordToken(SecurityEvent $event, $token): void
    {
        $owner = $token->tokenable ?? null;
        if (! $owner instanceof User) {
            return;
        }

        $actor = auth()->user();

        $this->logger->record($event, $owner, $actor instanceof User ? $actor : $owner, [
            'token_id' => $token->getKey(),
            'token_name' => $token->name,
            'abilities' => $token->abilities,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        $tokenModel = Sanctum::$personalAccessTokenModel;

        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            PasswordResetLinkSent::class => 'onPasswordResetLinkSent',
            PasswordReset::class => 'onPasswordReset',
            'eloquent.created: '.$tokenModel => 'onTokenCreated',
            'eloquent.deleted: '.$tokenModel => 'onTokenDeleted',
        ];
    }
}
