<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SecurityEvent;
use App\Mail\UserActivityAlertEmail;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails organization admins about account activity they should know about:
 * a new user, a user promoted to admin, and repeated failed sign-ins against
 * one account.
 *
 * Opt-in per admin through the `user_activity_alerts` notification
 * preference (off by default). Mail is queued.
 */
final class UserActivityAlertService
{
    public const PREFERENCE_KEY = 'user_activity_alerts';

    public const TYPE_USER_CREATED = 'user_created';

    public const TYPE_PROMOTED_TO_ADMIN = 'promoted_to_admin';

    public const TYPE_REPEATED_FAILED_LOGINS = 'repeated_failed_logins';

    public const TYPE_USERS_IMPORTED = 'users_imported';

    /** How many imported accounts the summary email lists by name. */
    public const IMPORT_SUMMARY_LIST_LIMIT = 50;

    /**
     * Depth of withoutUserCreatedAlerts() calls in progress. While non-zero,
     * notifyUserCreated() is a no-op so a bulk import can send one summary.
     */
    private static int $userCreatedSuppression = 0;

    /**
     * Run $callback with per-user "new user" alerts suppressed. The security
     * log still records every account; only the admin email is held back so
     * the caller can send a single summary (see notifyUsersImported()).
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function withoutUserCreatedAlerts(\Closure $callback): mixed
    {
        self::$userCreatedSuppression++;

        try {
            return $callback();
        } finally {
            self::$userCreatedSuppression--;
        }
    }

    /**
     * One alert summarising a bulk user import, in place of one per user.
     *
     * @param  array<int, User>  $users  The accounts the import created.
     */
    public function notifyUsersImported(int $organizationId, array $users, ?User $actor): void
    {
        if ($users === []) {
            return;
        }

        $count = count($users);
        $listed = array_slice($users, 0, self::IMPORT_SUMMARY_LIST_LIMIT);

        $this->sendToAdmins(self::TYPE_USERS_IMPORTED, $organizationId, $actor, [$actor?->id], [
            'subject_name' => $count === 1 ? '1 user' : "{$count} users",
            'imported_count' => $count,
            'admin_count' => count(array_filter($users, fn (User $user) => $user->role === 'admin')),
            'imported_users' => array_map(fn (User $user) => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ], $listed),
            'unlisted_count' => $count - count($listed),
        ]);
    }

    public const FAILED_LOGIN_THRESHOLD = 5;

    public const FAILED_LOGIN_WINDOW_MINUTES = 15;

    public function notifyUserCreated(User $user, ?User $actor): void
    {
        if (self::$userCreatedSuppression > 0) {
            return;
        }

        $this->send(self::TYPE_USER_CREATED, $user, $actor, [$actor?->id, $user->id]);
    }

    public function notifyPromotedToAdmin(User $user, ?User $actor): void
    {
        $this->send(self::TYPE_PROMOTED_TO_ADMIN, $user, $actor, [$actor?->id, $user->id]);
    }

    /**
     * Alert once per window when an account reaches the failed sign-in
     * threshold within the window.
     */
    public function checkRepeatedFailedLogins(User $user): void
    {
        if (! $user->organization_id) {
            return;
        }

        $failures = ActivityLog::query()
            ->where('organization_id', $user->organization_id)
            ->where('category', SecurityEvent::CATEGORY)
            ->where('action', SecurityEvent::LOGIN_FAILED->value)
            ->where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->where('created_at', '>=', now()->subMinutes(self::FAILED_LOGIN_WINDOW_MINUTES))
            ->count();

        if ($failures < self::FAILED_LOGIN_THRESHOLD) {
            return;
        }

        $key = 'security-alert:failed-logins:'.$user->id;
        if (! Cache::add($key, true, now()->addMinutes(self::FAILED_LOGIN_WINDOW_MINUTES))) {
            return;
        }

        $this->send(self::TYPE_REPEATED_FAILED_LOGINS, $user, null, [], [
            'failed_count' => $failures,
            'window_minutes' => self::FAILED_LOGIN_WINDOW_MINUTES,
        ]);
    }

    /**
     * Admins in the organization who opted in to these alerts.
     *
     * @param  array<int, int|null>  $excludeIds
     * @return Collection<int, User>
     */
    public function recipients(int $organizationId, array $excludeIds = []): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('role', 'admin')
            ->whereNotIn('id', array_values(array_filter($excludeIds)))
            ->get()
            ->filter(fn (User $admin) => self::wantsAlerts($admin))
            ->values();
    }

    public static function wantsAlerts(User $user): bool
    {
        $prefs = $user->notification_preferences ?? [];

        if (($prefs['email_notifications'] ?? true) === false) {
            return false;
        }

        return (bool) ($prefs[self::PREFERENCE_KEY] ?? false);
    }

    /**
     * @param  array<int, int|null>  $excludeIds
     * @param  array<string, mixed>  $extra
     */
    private function send(string $type, User $subject, ?User $actor, array $excludeIds, array $extra = []): void
    {
        if (! $subject->organization_id) {
            return;
        }

        $this->sendToAdmins($type, (int) $subject->organization_id, $actor, $excludeIds, [
            'subject_name' => $subject->name,
            'subject_email' => $subject->email,
            'subject_role' => $subject->role,
        ] + $extra, $subject->id);
    }

    /**
     * Queue an alert to every opted-in admin of the organization.
     *
     * @param  array<int, int|null>  $excludeIds
     * @param  array<string, mixed>  $data
     */
    private function sendToAdmins(string $type, int $organizationId, ?User $actor, array $excludeIds, array $data, ?int $subjectId = null): void
    {
        try {
            $recipients = $this->recipients($organizationId, $excludeIds);

            foreach ($recipients as $admin) {
                if (empty($admin->email) || ! filter_var($admin->email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                Mail::to($admin->email)->queue(new UserActivityAlertEmail([
                    'type' => $type,
                    'organization_id' => $organizationId,
                    'recipient_name' => $admin->name,
                    'actor_name' => $actor?->name,
                    'ip_address' => app()->bound('request') ? request()->ip() : null,
                    'occurred_at' => now()->toIso8601String(),
                    'url' => route('activity-log.index', ['category' => SecurityEvent::CATEGORY]),
                ] + $data));
            }
        } catch (\Throwable $e) {
            // An alert failure must never break the action that triggered it.
            Log::error('Failed to queue user activity alert', [
                'type' => $type,
                'user_id' => $subjectId,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
