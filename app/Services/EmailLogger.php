<?php

namespace App\Services;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

class EmailLogger
{
    /**
     * Log a successfully sent email.
     */
    public static function logSent(string $type, User $user, array $data): void
    {
        DB::table('email_logs')->insert([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'type' => $type,
            'status' => 'sent',
            'created_at' => now(),
        ]);
    }

    /**
     * Log a failed email attempt.
     */
    public static function logFailed(string $type, User $user, Exception $e): void
    {
        DB::table('email_logs')->insert([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'type' => $type,
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'created_at' => now(),
        ]);
    }
}
