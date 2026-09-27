<?php

namespace Tests\Feature\Security;

use App\Mail\UserActivityAlertEmail;
use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\UserActivityAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UserActivityAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $actingAdmin;

    protected User $optedInAdmin;

    protected User $optedOutAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Alerts Org',
            'email' => 'alerts@example.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->actingAdmin = User::factory()->admin()->forOrganization($this->organization->id)->create([
            'notification_preferences' => ['user_activity_alerts' => true],
        ]);
        $this->optedInAdmin = User::factory()->admin()->forOrganization($this->organization->id)->create([
            'email' => 'watcher@example.com',
            'notification_preferences' => ['user_activity_alerts' => true],
        ]);
        $this->optedOutAdmin = User::factory()->admin()->forOrganization($this->organization->id)->create([
            'email' => 'quiet@example.com',
        ]);
    }

    public function test_new_user_alert_is_queued_to_opted_in_admins_only(): void
    {
        Mail::fake();

        $this->actingAs($this->actingAdmin);
        User::factory()->forOrganization($this->organization->id)->create(['name' => 'New Hire']);

        Mail::assertQueued(UserActivityAlertEmail::class, function (UserActivityAlertEmail $mail) {
            return $mail->hasTo('watcher@example.com')
                && $mail->data['type'] === UserActivityAlertService::TYPE_USER_CREATED
                && $mail->data['subject_name'] === 'New Hire';
        });
        Mail::assertQueuedCount(1);
        Mail::assertNotQueued(UserActivityAlertEmail::class, fn ($mail) => $mail->hasTo('quiet@example.com'));
        Mail::assertNotQueued(UserActivityAlertEmail::class, fn ($mail) => $mail->hasTo($this->actingAdmin->email));
        Mail::assertNothingSent();
    }

    public function test_promotion_to_admin_alerts_admins(): void
    {
        Mail::fake();

        $member = User::factory()->forOrganization($this->organization->id)->create();

        $this->actingAs($this->actingAdmin);
        $member->update(['role' => 'manager']);
        Mail::assertNothingQueued();

        $member->update(['role' => 'admin']);

        Mail::assertQueued(UserActivityAlertEmail::class, function (UserActivityAlertEmail $mail) {
            return $mail->hasTo('watcher@example.com')
                && $mail->data['type'] === UserActivityAlertService::TYPE_PROMOTED_TO_ADMIN;
        });
        Mail::assertQueuedCount(1);
    }

    public function test_five_failed_logins_in_fifteen_minutes_alert_once(): void
    {
        Mail::fake();

        $target = User::factory()->forOrganization($this->organization->id)->create(['email' => 'target@example.com']);

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['email' => 'target@example.com', 'password' => 'wrong']);
        }
        Mail::assertNothingQueued();

        $this->post('/login', ['email' => 'target@example.com', 'password' => 'wrong']);

        Mail::assertQueued(UserActivityAlertEmail::class, function (UserActivityAlertEmail $mail) use ($target) {
            return $mail->hasTo('watcher@example.com')
                && $mail->data['type'] === UserActivityAlertService::TYPE_REPEATED_FAILED_LOGINS
                && $mail->data['subject_email'] === $target->email
                && $mail->data['failed_count'] === 5;
        });

        // Further failures in the same window do not re-alert.
        $this->travel(1)->minutes();
        $this->post('/login', ['email' => 'target@example.com', 'password' => 'wrong']);
        $this->assertCount(2, Mail::queued(UserActivityAlertEmail::class), 'one per opted-in admin, sent once');
    }

    public function test_failed_logins_spread_beyond_the_window_do_not_alert(): void
    {
        Mail::fake();

        User::factory()->forOrganization($this->organization->id)->create(['email' => 'slow@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'slow@example.com', 'password' => 'wrong']);
            $this->travel(5)->minutes();
        }

        Mail::assertNothingQueued();
    }

    public function test_master_email_switch_off_suppresses_alerts(): void
    {
        Mail::fake();

        $this->optedInAdmin->update(['notification_preferences' => [
            'user_activity_alerts' => true,
            'email_notifications' => false,
        ]]);

        $this->actingAs($this->actingAdmin);
        User::factory()->forOrganization($this->organization->id)->create();

        Mail::assertNothingQueued();
    }

    public function test_alert_email_renders(): void
    {
        $mail = new UserActivityAlertEmail([
            'type' => UserActivityAlertService::TYPE_REPEATED_FAILED_LOGINS,
            'recipient_name' => 'Watcher',
            'subject_name' => 'Target Person',
            'subject_email' => 'target@example.com',
            'subject_role' => 'member',
            'failed_count' => 5,
            'window_minutes' => 15,
            'url' => 'https://example.test/activity-log?category=security',
        ]);

        $html = $mail->render();

        $this->assertStringContainsString('Repeated failed sign-ins', $html);
        $this->assertStringContainsString('target@example.com', $html);
        $this->assertSame('Repeated failed sign-ins for Target Person', $mail->subjectLine());
    }

    public function test_preference_toggle_is_saved_from_account_settings(): void
    {
        $this->actingAs($this->optedOutAdmin)
            ->patch(route('settings.account.update.notifications'), [
                'email_notifications' => true,
                'low_stock_alerts' => true,
                'order_notifications' => true,
                'system_notifications' => true,
                'user_activity_alerts' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->optedOutAdmin->fresh()->notification_preferences['user_activity_alerts']);
        $this->assertTrue(UserActivityAlertService::wantsAlerts($this->optedOutAdmin->fresh()));
    }
}
