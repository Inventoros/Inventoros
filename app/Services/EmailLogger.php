<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Service for logging email send attempts.
 *
 * Records successful and failed email deliveries to the database
 * for tracking and debugging purposes.
 */
final class EmailLogger
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    /**
     * Log a successfully sent email.
     *
     * @param string $type The email type (e.g., 'low_stock', 'order_status_updated')
     * @param User $user The user who received the email
     * @param array $data Additional data about the email
     * @return void
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
     *
     * @param string $type The email type that failed
     * @param User $user The intended recipient
     * @param Exception $e The exception that occurred
     * @return void
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
