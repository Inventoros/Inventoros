<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fold the legacy `email_enabled` preference into `email_notifications`,
     * the one master email key both settings pages now write and every mail
     * sender reads.
     *
     * A user who has both keys keeps `email_notifications`: that is the value
     * the account settings page could actually save, while `email_enabled` was
     * dropped by validation whenever the email settings page posted it.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('notification_preferences')
            ->orderBy('id')
            ->select(['id', 'notification_preferences'])
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    $prefs = json_decode((string) $user->notification_preferences, true);

                    if (! is_array($prefs) || ! array_key_exists('email_enabled', $prefs)) {
                        continue;
                    }

                    $legacy = $prefs['email_enabled'];
                    unset($prefs['email_enabled']);

                    if (! array_key_exists('email_notifications', $prefs)) {
                        $prefs['email_notifications'] = (bool) $legacy;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['notification_preferences' => json_encode($prefs)]);
                }
            });
    }

    /**
     * Not reversible without losing which key a value came from, and the old
     * key is no longer read, so down() is a no-op.
     */
    public function down(): void
    {
        //
    }
};
