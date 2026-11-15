<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

/**
 * Second-factor checks shared by the web challenge and the API login.
 *
 * - A per-account failure budget for TOTP and recovery codes, independent of
 *   the caller's IP, so spreading guesses over many clients does not help.
 *   It is only reachable after the password step, so nobody without the
 *   password can lock an account's second factor.
 * - One-time TOTP codes: the timestep of the last accepted code is stored and
 *   only a newer code is accepted afterwards.
 */
final class TwoFactorVerifier
{
    public const MAX_FAILURES = 5;

    public const DECAY_SECONDS = 900;

    public function tooManyFailures(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->key($user), self::MAX_FAILURES);
    }

    public function secondsUntilUnlocked(User $user): int
    {
        return max(1, RateLimiter::availableIn($this->key($user)));
    }

    public function lockoutMessage(User $user): string
    {
        $seconds = $this->secondsUntilUnlocked($user);

        return trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]);
    }

    public function recordFailure(User $user): void
    {
        RateLimiter::hit($this->key($user), self::DECAY_SECONDS);
    }

    public function clearFailures(User $user): void
    {
        RateLimiter::clear($this->key($user));
    }

    /**
     * Verify a TOTP code against the user's stored secret. An accepted code's
     * timestep is persisted, so neither it nor an older code is accepted again.
     */
    public function verifyTotp(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $locked = $user->newQuery()->lockForUpdate()->findOrFail($user->getKey());

            if (! $locked->two_factor_secret) {
                return false;
            }

            $timestep = (new Google2FA)->verifyKeyNewer(
                decrypt($locked->two_factor_secret),
                $code,
                (int) ($locked->two_factor_last_used_timestep ?? 0),
            );

            if (! is_int($timestep)) {
                return false;
            }

            // Bookkeeping only: no model events, so a sign-in does not show
            // up as a profile change in the activity log.
            $locked->forceFill(['two_factor_last_used_timestep' => $timestep])->saveQuietly();

            return true;
        });
    }

    /**
     * The timestep of a code just verified against a secret that is not yet
     * stored (enabling two-factor), so that code cannot be replayed at sign-in.
     */
    public function timestepOf(string $secret, string $code): ?int
    {
        $timestep = (new Google2FA)->verifyKeyNewer($secret, $code, 0);

        return is_int($timestep) ? $timestep : null;
    }

    private function key(User $user): string
    {
        return 'two-factor-failures:'.$user->getKey();
    }
}
