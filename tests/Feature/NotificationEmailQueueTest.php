<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\LowStockEmail;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Notification;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationEmailQueueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A stock manager (who should receive low-stock alerts) authenticated for
     * the settings context, plus a low-stock product.
     *
     * @return array{0: User, 1: Product}
     */
    private function managerAndLowStockProduct(): array
    {
        SystemSetting::set('installed', true, 'boolean');

        $org = Organization::create([
            'name' => 'N', 'email' => 'n@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $manager = User::create([
            'name' => 'Manager', 'email' => 'manager@n.com', 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'member',
        ]);
        $role = Role::create([
            'slug' => 'stock-mgr', 'name' => 'Stock Mgr', 'is_system' => false,
            'permissions' => ['manage_stock'],
        ]);
        $manager->roles()->syncWithoutDetaching([$role->id]);

        $product = Product::create([
            'organization_id' => $org->id, 'sku' => 'NQ-1', 'name' => 'NQ',
            'price' => 10, 'currency' => 'USD', 'stock' => 2, 'min_stock' => 10, 'is_active' => true,
        ]);

        // Applying the org email config reads settings, which need an auth context.
        $this->actingAs($manager);

        return [$manager, $product];
    }

    public function test_low_stock_notification_queues_the_email_instead_of_sending_inline(): void
    {
        Mail::fake();
        [, $product] = $this->managerAndLowStockProduct();

        NotificationService::createLowStockNotification($product);

        // The email is queued off the request, not sent synchronously (which
        // would block the caller on SMTP and can fan out to many managers).
        Mail::assertQueued(LowStockEmail::class);
        Mail::assertNothingSent();
    }

    public function test_repeat_low_stock_alerts_are_suppressed_within_the_cooldown(): void
    {
        Mail::fake();
        [$manager, $product] = $this->managerAndLowStockProduct();

        // A product that stays low would otherwise re-alert on every subsequent
        // stock adjustment.
        NotificationService::createLowStockNotification($product);
        NotificationService::createLowStockNotification($product);

        // Only one alert despite two calls: one in-app notification, one email.
        $this->assertSame(1, Notification::query()
            ->where('type', 'low_stock')
            ->where('user_id', $manager->id)
            ->count());
        Mail::assertQueued(LowStockEmail::class, 1);
    }
}
