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
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationEmailQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_low_stock_notification_queues_the_email_instead_of_sending_inline(): void
    {
        SystemSetting::set('installed', true, 'boolean');
        Mail::fake();

        $org = Organization::create([
            'name' => 'N', 'email' => 'n@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        // A stock manager who should receive low-stock alerts.
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

        NotificationService::createLowStockNotification($product);

        // The email is queued off the request, not sent synchronously (which
        // would block the caller on SMTP and can fan out to many managers).
        Mail::assertQueued(LowStockEmail::class);
        Mail::assertNothingSent();
    }
}
