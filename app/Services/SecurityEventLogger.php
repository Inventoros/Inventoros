<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SecurityEvent;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Writes security events (sign-ins, 2FA, API tokens, permission denials,
 * role changes) to the activity log under the `security` category.
 *
 * Every entry is scoped to the organization of the account the event is
 * about. Events that cannot be tied to an organization (a failed sign-in for
 * an email that matches no account, or a user without an organization) go to
 * the application log instead, so the activity log never holds rows that no
 * tenant can see and never leaks which emails exist to another tenant.
 *
 * Never pass secrets (passwords, codes, tokens) in $properties.
 */
final class SecurityEventLogger
{
    /** Seconds a repeated permission denial for the same user + route stays quiet. */
    public const DENIED_THROTTLE_SECONDS = 600;

    /** Seconds a repeated lockout for the same email + IP stays quiet. */
    public const LOCKOUT_THROTTLE_SECONDS = 300;

    /**
     * Record a security event about $subject.
     *
     * @param  Model  $subject  What the event is about: usually the account, a Role for role changes.
     * @param  User|null  $actor  Who performed it; null when unknown (failed sign-in).
     * @param  array<string, mixed>  $properties  Non-secret context.
     */
    public function record(
        SecurityEvent $event,
        Model $subject,
        ?User $actor = null,
        array $properties = [],
        ?string $description = null,
        ?int $organizationId = null,
    ): ?ActivityLog {
        $request = $this->currentRequest();
        $organizationId ??= $subject->organization_id;

        if (! $organizationId) {
            Log::info('Security event for a user without an organization', [
                'event' => $event->value,
                'subject' => $subject::class.'#'.$subject->getKey(),
                'ip' => $request?->ip(),
            ]);

            return null;
        }

        try {
            return ActivityLog::create([
                'organization_id' => $organizationId,
                'user_id' => $actor?->getKey(),
                'category' => SecurityEvent::CATEGORY,
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'action' => $event->value,
                'description' => $description ?? $event->label(),
                'properties' => $properties === [] ? null : $properties,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? substr((string) $request->userAgent(), 0, 1000) : null,
            ]);
        } catch (\Throwable $e) {
            // Logging must never break sign-in or the action being audited.
            Log::warning('Security event could not be written to the activity log', [
                'event' => $event->value,
                'subject' => $subject::class.'#'.$subject->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record an event for an email that matches no account. These have no
     * tenant, so they go to the application log only.
     */
    public function recordUnknownAccount(SecurityEvent $event, ?string $email): void
    {
        $request = $this->currentRequest();

        Log::warning(match ($event) {
            SecurityEvent::LOGIN_FAILED => 'Failed sign-in for unknown email',
            SecurityEvent::LOCKOUT => 'Sign-in lockout for unknown email',
            default => 'Security event for unknown email',
        }, [
            'event' => $event->value,
            'email' => $email !== null ? mb_substr($email, 0, 255) : null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 1000) : null,
        ]);
    }

    /**
     * Record a lockout, at most once per email + IP per throttle window.
     */
    public function recordLockout(?User $user, ?string $email): void
    {
        $request = $this->currentRequest();
        $key = 'security:lockout:'.sha1(mb_strtolower((string) $email).'|'.$request?->ip());

        if (! Cache::add($key, true, self::LOCKOUT_THROTTLE_SECONDS)) {
            return;
        }

        if ($user === null) {
            $this->recordUnknownAccount(SecurityEvent::LOCKOUT, $email);

            return;
        }

        $this->record(SecurityEvent::LOCKOUT, $user, null, ['email' => $email]);
    }

    /**
     * Record a 403 for the current request, once per request and at most once
     * per user + method + route per throttle window, so a page that polls a
     * forbidden endpoint does not flood the log.
     */
    public function recordPermissionDenied(Request $request, User $user): void
    {
        if ($request->attributes->get('security.denied_logged')) {
            return;
        }
        $request->attributes->set('security.denied_logged', true);

        $routeName = $request->route()?->getName();
        $target = $routeName ?? $request->path();
        $key = 'security:denied:'.$user->getKey().':'.sha1($request->method().' '.$target);

        if (! Cache::add($key, true, self::DENIED_THROTTLE_SECONDS)) {
            return;
        }

        $properties = [
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => $routeName,
        ];

        $required = $request->attributes->get('security.required_permissions');
        if (is_array($required) && $required !== []) {
            $properties['required_permissions'] = array_values($required);
        }

        $this->record(SecurityEvent::PERMISSION_DENIED, $user, $user, $properties);
    }

    private function currentRequest(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
