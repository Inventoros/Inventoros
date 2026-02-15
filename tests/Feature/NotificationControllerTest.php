<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Notification;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => [],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    protected function createNotification(array $attributes = []): Notification
    {
        return Notification::create(array_merge([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'type' => 'info',
            'title' => 'Test Notification',
            'message' => 'This is a test notification',
            'read_at' => null,
        ], $attributes));
    }

    public function test_user_can_view_notifications_list(): void
    {
        $this->createNotification();

        $response = $this->actingAs($this->admin)
            ->get(route('notifications.index'));

        $response->assertStatus(200);
    }

    public function test_user_can_get_unread_count(): void
    {
        $this->createNotification();
        $this->createNotification(['read_at' => now()]);

        $response = $this->actingAs($this->admin)
            ->get(route('notifications.unread-count'));

        $response->assertStatus(200);
        $response->assertJsonStructure(['count']);
    }

    public function test_user_can_mark_notification_as_read(): void
    {
        $notification = $this->createNotification();

        $response = $this->actingAs($this->admin)
            ->post(route('notifications.mark-as-read', $notification));

        $response->assertStatus(200);

        $notification->refresh();
        $this->assertNotNull($notification->read_at);
    }

    public function test_user_can_mark_all_notifications_as_read(): void
    {
        $this->createNotification();
        $this->createNotification(['title' => 'Another Notification']);

        $response = $this->actingAs($this->admin)
            ->post(route('notifications.mark-all-read'));

        $response->assertStatus(200);

        $this->assertEquals(0, Notification::where('user_id', $this->admin->id)->whereNull('read_at')->count());
    }

    public function test_user_can_delete_notification(): void
    {
        $notification = $this->createNotification();

        $response = $this->actingAs($this->admin)
            ->delete(route('notifications.destroy', $notification));

        $response->assertStatus(200);

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_user_can_clear_read_notifications(): void
    {
        $this->createNotification(['read_at' => now()]);
        $this->createNotification();

        $response = $this->actingAs($this->admin)
            ->delete(route('notifications.clear-read'));

        $response->assertStatus(200);

        $this->assertEquals(1, Notification::where('user_id', $this->admin->id)->count());
    }

    public function test_guest_cannot_view_notifications(): void
    {
        $response = $this->get(route('notifications.index'));

        $response->assertRedirect(route('login'));
    }
}
