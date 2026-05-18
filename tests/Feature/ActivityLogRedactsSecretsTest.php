<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogRedactsSecretsTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_user_password_does_not_log_either_hash(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $org = Organization::create(['name' => 'Org', 'email' => 'o@test.com']);
        $user = User::create([
            'name' => 'A',
            'email' => 'a@test.com',
            'password' => bcrypt('original-secret'),
            'organization_id' => $org->id,
            'role' => 'admin',
        ]);

        $this->actingAs($user);

        $oldHash = $user->password;
        $user->update(['password' => bcrypt('rotated-secret')]);
        $newHash = $user->fresh()->password;

        $log = ActivityLog::where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->where('action', 'updated')
            ->latest()
            ->first();

        // If only the password changed, the resulting `new` payload is empty
        // and no log row is written at all. That's the correct outcome.
        if ($log === null) {
            $this->addToAssertionCount(1);
            return;
        }

        $encoded = json_encode($log->properties ?? []);
        $this->assertStringNotContainsString($oldHash, $encoded);
        $this->assertStringNotContainsString($newHash, $encoded);
        $this->assertStringNotContainsString('$2y$', $encoded, 'no bcrypt hash should leak into activity_logs');
    }

    public function test_updating_user_2fa_secret_and_recovery_codes_does_not_log_them(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $org = Organization::create(['name' => 'Org', 'email' => 'o2@test.com']);
        $user = User::create([
            'name' => 'B',
            'email' => 'b@test.com',
            'password' => bcrypt('x'),
            'organization_id' => $org->id,
            'role' => 'admin',
        ]);

        $this->actingAs($user);

        $secretCiphertext = encrypt('JBSWY3DPEHPK3PXP'); // arbitrary
        $codesCiphertext = encrypt(json_encode(['code-a-1234', 'code-b-5678']));

        $user->update([
            'name' => 'B Renamed',
            'two_factor_enabled' => true,
            'two_factor_secret' => $secretCiphertext,
            'two_factor_recovery_codes' => $codesCiphertext,
        ]);

        $log = ActivityLog::where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->where('action', 'updated')
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $encoded = json_encode($log->properties ?? []);

        // The harmless name change still appears.
        $this->assertStringContainsString('B Renamed', $encoded);

        // The 2FA-related fields must not appear.
        $this->assertStringNotContainsString('two_factor_secret', $encoded);
        $this->assertStringNotContainsString('two_factor_recovery_codes', $encoded);
        $this->assertStringNotContainsString($secretCiphertext, $encoded);
        $this->assertStringNotContainsString($codesCiphertext, $encoded);
    }
}
