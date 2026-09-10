<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Controllers redirect with ->with('success'|'error'|...) in hundreds of
 * places, and none of it reached the browser: HandleInertiaRequests shared a
 * `flash` prop containing only the webhook secret, so every one of those
 * messages was written to the session and thrown away.
 *
 * The suite did not catch it because assertSessionHas() proves the session was
 * written, never that anything shipped it to the page. These tests assert the
 * prop, which is the part that was missing.
 */
class FlashMessageSharingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $organization = Organization::create([
            'name' => 'Flash Org',
            'email' => 'flash@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->user = User::create([
            'name' => 'Flash User',
            'email' => 'flash@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'admin',
        ]);

        $role = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            ['name' => 'Administrator', 'is_system' => true, 'permissions' => []],
        );

        $this->user->roles()->syncWithoutDetaching([$role->id]);
    }

    /** @return array<int, array{0: string, 1: string}> */
    public static function flashKeyProvider(): array
    {
        return [
            'success' => ['success', 'Product created successfully.'],
            'error' => ['error', 'Insufficient stock for SKU-1.'],
            'status' => ['status', 'Two-factor authentication enabled.'],
        ];
    }

    #[DataProvider('flashKeyProvider')]
    public function test_flash_keys_reach_the_page_props(string $key, string $message): void
    {
        $this->actingAs($this->user)
            ->withSession([$key => $message])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where("flash.{$key}", $message));
    }

    public function test_flash_keys_are_null_when_nothing_was_flashed(): void
    {
        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.success', null)
                ->where('flash.error', null)
                ->where('flash.warning', null)
                ->where('flash.status', null)
            );
    }

    /**
     * The importer flashes ['message' => ..., 'stats' => [...]] and draws a
     * per-row breakdown from it. Sharing must pass the value through untouched
     * rather than coercing it to a string, or that page loses its detail.
     */
    public function test_a_structured_flash_payload_is_shared_unchanged(): void
    {
        $payload = [
            'message' => 'Import completed with some errors',
            'stats' => ['imported' => 4, 'updated' => 1, 'errors' => ['Row 7: missing SKU']],
        ];

        $this->actingAs($this->user)
            ->withSession(['warning' => $payload])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.warning.message', 'Import completed with some errors')
                ->where('flash.warning.stats.imported', 4)
                ->where('flash.warning.stats.errors.0', 'Row 7: missing SKU')
            );
    }

    /**
     * The webhook secret is a one-time reveal that predates the other keys and
     * must keep working alongside them.
     */
    public function test_the_webhook_secret_is_still_shared(): void
    {
        $this->actingAs($this->user)
            ->withSession(['newWebhookSecret' => 'whsec_test'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('flash.newWebhookSecret', 'whsec_test'));
    }

    /**
     * The end-to-end shape: a controller redirects with a flash, the browser
     * follows the redirect, and the message is on the page it lands on. This is
     * the assertion the 89 existing assertSessionHas() calls could not make.
     */
    public function test_a_flashed_redirect_carries_its_message_to_the_next_page(): void
    {
        $response = $this->actingAs($this->user)
            ->from(route('dashboard'))
            ->withSession(['error' => 'Insufficient stock for SKU-1.'])
            ->get(route('dashboard'));

        $response->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('flash.error', 'Insufficient stock for SKU-1.')
        );
    }
}
