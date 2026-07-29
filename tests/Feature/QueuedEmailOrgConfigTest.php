<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\LowStockEmail;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Notification email must resolve the org's mail config from an explicit
 * organization (not auth()), so it works on the queue/import context and the
 * queued mailable applies the sending org's mailer in the worker.
 */
class QueuedEmailOrgConfigTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $manager = User::create([
            'name' => 'Mgr', 'email' => 'mgr@o.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $role = Role::create([
            'slug' => 'stock-mgr', 'name' => 'Stock Mgr', 'is_system' => false,
            'organization_id' => $this->org->id, 'permissions' => ['manage_stock'],
        ]);
        $manager->roles()->syncWithoutDetaching([$role->id]);

        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'P-1', 'name' => 'P',
            'price' => 10, 'currency' => 'USD', 'stock' => 2, 'min_stock' => 10, 'is_active' => true,
        ]);
    }

    public function test_low_stock_notification_in_a_queue_context_does_not_crash(): void
    {
        Mail::fake();
        Notification::fake();

        // No authenticated user — exactly the ProcessProductImportJob / queue
        // context where SettingsService::get() used to throw "User must be
        // authenticated" and fail the job.
        $this->assertGuest();

        NotificationService::createLowStockNotification($this->product);

        // It queued the alert, tagged with the org so the worker can apply the
        // right mail config.
        Mail::assertQueued(LowStockEmail::class, fn (LowStockEmail $m) => ($m->data['organization_id'] ?? null) === $this->org->id);
    }

    public function test_the_mailable_applies_the_orgs_mail_config_at_build_time(): void
    {
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.provider', 'value' => 'smtp', 'encrypted' => false]);
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.from_address', 'value' => 'store@acme.test', 'encrypted' => false]);
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.from_name', 'value' => 'Acme Store', 'encrypted' => false]);

        Config::set('mail.from.address', 'default@system.test');

        // build() runs in the worker; it must pull the org's config with no auth.
        (new LowStockEmail(['organization_id' => $this->org->id, 'product' => $this->product]))->build();

        $this->assertSame('store@acme.test', Config::get('mail.from.address'));
        $this->assertSame('Acme Store', Config::get('mail.from.name'));
    }
}
