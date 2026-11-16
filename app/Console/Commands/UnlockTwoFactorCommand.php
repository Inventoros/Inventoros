<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorVerifier;
use Illuminate\Console\Command;

/**
 * Clear one staff user's two-factor failure lockout (5 wrong codes in 15
 * minutes), for an administrator helping a locked-out user back in.
 */
class UnlockTwoFactorCommand extends Command
{
    protected $signature = 'inventoros:2fa-unlock {email : The user\'s sign-in email}';

    protected $description = 'Clear a user\'s two-factor failure lockout';

    public function handle(TwoFactorVerifier $verifier): int
    {
        $user = User::where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user has that email.');

            return self::FAILURE;
        }

        $verifier->clearFailures($user);
        $this->info('Two-factor lockout cleared for '.$user->email.'.');

        return self::SUCCESS;
    }
}
