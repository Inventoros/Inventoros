<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\LowStockEmail;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The master email switch has one key, `email_notifications`, written by both
 * settings pages and read by every mail sender. Before, the account page
 * saved `email_notifications` (which no sender read) and the email settings
 * page posted `email_enabled` / `email_low_stock` / ... (which the controller
 * silently dropped), so neither page could stop an email.
 */
class EmailPreferenceKeyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Prefs', 'email' => 'p@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
    }

    private function stockManager(array $preferences): User
    {
        $user = User::create([
            'name' => 'Manager', 'email' => 'manager@p.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
            'notification_preferences' => $preferences,
        ]);
        $role = Role::create([
            'slug' => 'stock-mgr', 'name' => 'Stock Mgr', 'is_system' => false,
            'permissions' => ['manage_stock'],
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function lowStockProduct(): Product
    {
        return Product::create([
            'organization_id' => $this->org->id, 'sku' => 'PK-1', 'name' => 'PK',
            'price' => 10, 'currency' => 'USD', 'stock' => 2, 'min_stock' => 10, 'is_active' => true,
        ]);
    }

    public function test_email_notifications_off_stops_low_stock_emails(): void
    {
        Mail::fake();
        $manager = $this->stockManager(['email_notifications' => false]);
        $this->actingAs($manager);

        NotificationService::createLowStockNotification($this->lowStockProduct());

        Mail::assertNothingQueued();
    }

    public function test_email_notifications_on_still_sends(): void
    {
        Mail::fake();
        $manager = $this->stockManager(['email_notifications' => true]);
        $this->actingAs($manager);

        NotificationService::createLowStockNotification($this->lowStockProduct());

        Mail::assertQueued(LowStockEmail::class);
    }

    public function test_email_settings_page_saves_the_master_switch_and_per_type_choices(): void
    {
        $user = $this->stockManager([]);

        $this->actingAs($user)
            ->patch(route('settings.account.update.notifications'), [
                'email_notifications' => false,
                'email_low_stock' => false,
                'email_orders' => true,
                'email_approvals' => false,
            ])
            ->assertSessionHasNoErrors();

        $prefs = $user->fresh()->notification_preferences;
        $this->assertFalse($prefs['email_notifications']);
        $this->assertFalse($prefs['email_low_stock']);
        $this->assertTrue($prefs['email_orders']);
        $this->assertFalse($prefs['email_approvals']);
        $this->assertArrayNotHasKey('email_enabled', $prefs);
    }

    public function test_per_type_email_choice_is_honoured(): void
    {
        Mail::fake();
        $manager = $this->stockManager(['email_notifications' => true, 'email_low_stock' => false]);
        $this->actingAs($manager);

        NotificationService::createLowStockNotification($this->lowStockProduct());

        Mail::assertNothingQueued();
    }

    public function test_backfill_migration_moves_legacy_email_enabled_to_email_notifications(): void
    {
        $legacyOff = User::create([
            'name' => 'Legacy Off', 'email' => 'off@p.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $legacyOn = User::create([
            'name' => 'Legacy On', 'email' => 'on@p.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $both = User::create([
            'name' => 'Both', 'email' => 'both@p.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $none = User::create([
            'name' => 'None', 'email' => 'none@p.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);

        // Write raw JSON so the rows look exactly like pre-migration data.
        DB::table('users')->where('id', $legacyOff->id)->update(['notification_preferences' => json_encode(['email_enabled' => false, 'low_stock_alerts' => true])]);
        DB::table('users')->where('id', $legacyOn->id)->update(['notification_preferences' => json_encode(['email_enabled' => true])]);
        // A choice made on the account page (email_notifications) is the one
        // the user could actually save, so it wins over a stale email_enabled.
        DB::table('users')->where('id', $both->id)->update(['notification_preferences' => json_encode(['email_enabled' => true, 'email_notifications' => false])]);
        DB::table('users')->where('id', $none->id)->update(['notification_preferences' => null]);

        $migration = require database_path('migrations/2026_09_27_000010_unify_email_notification_preference_key.php');
        $migration->up();

        $this->assertSame(['low_stock_alerts' => true, 'email_notifications' => false], $legacyOff->fresh()->notification_preferences);
        $this->assertSame(['email_notifications' => true], $legacyOn->fresh()->notification_preferences);
        $this->assertSame(['email_notifications' => false], $both->fresh()->notification_preferences);
        $this->assertNull($none->fresh()->notification_preferences);
    }
}
