<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\SecurityEvent;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\DataExport;
use App\Models\Inventory\Product;
use App\Models\Notification;
use App\Models\Scopes\OrganizationScope;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Organizations\ActiveOrganization;
use App\Services\Organizations\OrganizationMembershipService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\Concerns\BuildsOrganizations;
use Tests\TestCase;

/**
 * Switching the browser session between organizations, and every web surface
 * after a switch: nothing of the previous organization can be read or
 * written, permissions are those held in the active organization, and the
 * session itself is renewed.
 *
 * Requests are made the way a browser makes them: the user signs in once and
 * every later request resolves them again from the session (nextRequest()),
 * so the active organization is re-applied, and re-checked, each time.
 */
final class OrganizationSwitchingTest extends TestCase
{
    use BuildsOrganizations, RefreshDatabase;

    private Organization $alpha;

    private Organization $beta;

    private User $user;

    private Product $alphaProduct;

    private Product $betaProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();

        $this->alpha = $this->organization('Alpha', 'USD');
        $this->beta = $this->organization('Beta', 'EUR');
        $this->user = $this->homeUser($this->alpha, 'admin');
        $this->homeUser($this->beta, 'admin', 'Owner');

        $this->alphaProduct = $this->product($this->alpha, 'ALPHA-1');
        $this->betaProduct = $this->product($this->beta, 'BETA-1');
    }

    private function signIn(?User $user = null): void
    {
        $user ??= $this->user;
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->nextRequest();
    }

    /**
     * A new request, as a browser sends it: with the session cookie, and the
     * user resolved from the session again.
     */
    private function nextRequest(): static
    {
        $this->app['auth']->forgetGuards();

        $this->withCookie(config('session.cookie'), session()->getId());

        return $this;
    }

    private function switchTo(Organization $organization): \Illuminate\Testing\TestResponse
    {
        $response = $this->post(route('organizations.switch'), ['organization_id' => $organization->id]);
        $this->nextRequest();

        return $response;
    }

    /** @return array<int, string> */
    private function listedSkus(): array
    {
        $response = $this->get(route('products.index'))->assertOk();
        $this->nextRequest();

        return collect($response->viewData('page')['props']['products']['data'])->pluck('sku')->all();
    }

    public function test_a_user_of_one_organization_sees_no_switcher_and_works_as_before(): void
    {
        $this->signIn();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.organization.id', $this->alpha->id)
                ->has('auth.organizations', 1));
        $this->nextRequest();

        $this->assertSame(['ALPHA-1'], $this->listedSkus());
    }

    public function test_a_member_of_two_organizations_is_offered_both(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        $this->signIn();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.organization.id', $this->alpha->id)
                ->where('auth.organizations.0.id', $this->alpha->id)
                ->where('auth.organizations.1.id', $this->beta->id));
    }

    public function test_switching_confines_every_page_to_the_chosen_organization(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        $this->signIn();

        $this->switchTo($this->beta)->assertRedirect(route('dashboard'));

        $this->assertSame(['BETA-1'], $this->listedSkus());

        // The previous organization's records are gone, for reading and writing.
        $this->get(route('products.show', $this->alphaProduct))->assertNotFound();
        $this->nextRequest();
        $this->put(route('products.update', $this->alphaProduct), ['name' => 'Hijacked', 'sku' => 'ALPHA-1', 'price' => 1])->assertNotFound();
        $this->nextRequest();
        $this->delete(route('products.destroy', $this->alphaProduct))->assertNotFound();
        $this->nextRequest();
        $this->assertDatabaseHas('products', ['id' => $this->alphaProduct->id, 'name' => 'Product ALPHA-1', 'deleted_at' => null]);

        // New records belong to the active organization.
        $this->post(route('products.store'), [
            'sku' => 'NEW-1', 'name' => 'Made in Beta', 'price' => 5, 'currency' => 'EUR', 'stock' => 1, 'min_stock' => 0, 'is_active' => true,
        ])->assertRedirect();
        $this->nextRequest();
        $this->assertDatabaseHas('products', ['sku' => 'NEW-1', 'organization_id' => $this->beta->id]);

        // The shared props describe the active organization.
        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('auth.organization.id', $this->beta->id)
            ->where('auth.user.organization_id', $this->beta->id)
            ->where('regional.currency', 'EUR'));
        $this->nextRequest();

        // And back again.
        $this->switchTo($this->alpha)->assertRedirect(route('dashboard'));
        $this->assertSame(['ALPHA-1'], $this->listedSkus());
    }

    public function test_switching_renews_the_session_and_csrf_token_and_forgets_the_warehouse(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->alpha->id, 'is_active' => true]);
        $this->signIn();

        $this->post(route('warehouses.set-active'), ['warehouse_id' => $warehouse->id])->assertRedirect();
        $this->nextRequest();
        $this->assertSame($warehouse->id, session('active_warehouse_id'));

        $sessionId = session()->getId();
        $token = session()->token();

        $this->switchTo($this->beta);

        $this->assertNotSame($sessionId, session()->getId(), 'The session id must change on a switch.');
        $this->assertNotSame($token, session()->token(), 'The CSRF token must change on a switch.');
        $this->assertNull(session('active_warehouse_id'));
        $this->assertSame(
            ['user_id' => $this->user->id, 'organization_id' => $this->beta->id],
            session(ActiveOrganization::SESSION_KEY),
        );
        $this->assertSame(2, ActivityLog::where('action', SecurityEvent::ORGANIZATION_SWITCHED->value)->count());
    }

    public function test_the_organization_switched_hook_fires(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        $seen = null;
        add_action('organization_switched', function ($user, $from, $to) use (&$seen) {
            $seen = [$user->id, $from, $to, $user->organization_id];
        });
        $this->signIn();

        $this->switchTo($this->beta);

        $this->assertSame([$this->user->id, $this->alpha->id, $this->beta->id, $this->beta->id], $seen);
    }

    public function test_a_user_cannot_switch_into_an_organization_they_do_not_belong_to(): void
    {
        $this->signIn();
        $sessionId = session()->getId();

        $this->switchTo($this->beta)->assertForbidden();

        $this->assertSame($sessionId, session()->getId());
        $this->assertNull(session(ActiveOrganization::SESSION_KEY));
        $this->assertSame(['ALPHA-1'], $this->listedSkus());
    }

    public function test_disabled_and_deleted_organizations_cannot_be_switched_to(): void
    {
        $gamma = $this->organization('Gamma');
        $this->addMember($this->beta, $this->user, 'admin');
        $this->addMember($gamma, $this->user, 'admin');
        $this->beta->update(['is_active' => false]);
        $gamma->delete();
        $this->signIn();

        $this->switchTo($this->beta)->assertForbidden();
        $this->switchTo($gamma)->assertForbidden();
        $this->post(route('organizations.switch'), ['organization_id' => 'abc'])->assertSessionHasErrors('organization_id');
        $this->nextRequest();

        $this->assertSame(['ALPHA-1'], $this->listedSkus());
    }

    public function test_a_withdrawn_membership_ends_on_the_next_request(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        $this->signIn();
        $this->switchTo($this->beta);
        $this->assertSame(['BETA-1'], $this->listedSkus());

        app(OrganizationMembershipService::class)->remove($this->beta, $this->user);

        $this->assertSame(['ALPHA-1'], $this->listedSkus());
        $this->assertNull(session(ActiveOrganization::SESSION_KEY));
    }

    public function test_a_disabled_organization_ends_the_session_there_on_the_next_request(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        $this->signIn();
        $this->switchTo($this->beta);

        $this->beta->update(['is_active' => false]);

        $this->assertSame(['ALPHA-1'], $this->listedSkus());
    }

    public function test_signing_in_always_starts_in_the_home_organization(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');

        // A session that already names Beta for this user (planted before
        // sign-in, or left from an earlier one) does not survive the sign-in.
        $this->withSession([ActiveOrganization::SESSION_KEY => ['user_id' => $this->user->id, 'organization_id' => $this->beta->id]]);
        $this->signIn();

        $this->assertSame(['ALPHA-1'], $this->listedSkus());
        $this->assertNull(session(ActiveOrganization::SESSION_KEY));
    }

    public function test_an_active_organization_stored_for_another_user_is_ignored(): void
    {
        $intruder = $this->homeUser($this->beta, 'admin', 'Intruder');
        $this->signIn();

        // Session data naming a different user (fixation, a shared machine).
        session()->put(ActiveOrganization::SESSION_KEY, ['user_id' => $intruder->id, 'organization_id' => $this->beta->id]);

        $this->assertSame(['ALPHA-1'], $this->listedSkus());
    }

    public function test_permissions_are_those_held_in_the_active_organization(): void
    {
        // Administrator at home, a plain member in Beta with one custom role.
        $this->addMember($this->beta, $this->user, 'member');
        $this->grantInOrganization($this->user, $this->beta, ['view_products']);
        $this->signIn();

        $this->delete(route('products.destroy', $this->alphaProduct))->assertRedirect();
        $this->nextRequest();
        $this->assertSoftDeleted('products', ['id' => $this->alphaProduct->id]);

        $this->switchTo($this->beta);

        $this->assertSame(['BETA-1'], $this->listedSkus());
        $this->delete(route('products.destroy', $this->betaProduct))->assertForbidden();
        $this->nextRequest();
        $this->get(route('users.index'))->assertForbidden();
        $this->nextRequest();
        $this->assertDatabaseHas('products', ['id' => $this->betaProduct->id, 'deleted_at' => null]);

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('auth.user.role', 'member')
            ->where('auth.permissions', ['view_products']));
    }

    public function test_a_write_from_a_tab_still_showing_the_previous_organization_is_refused(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        $this->signIn();
        $this->switchTo($this->beta);
        $header = ['X-Inventoros-Organization' => (string) $this->alpha->id];

        $this->withHeaders($header)
            ->post(route('products.store'), ['sku' => 'STALE-1', 'name' => 'Meant for Alpha', 'price' => 1, 'currency' => 'USD', 'stock' => 1, 'min_stock' => 0])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('warning');
        $this->nextRequest();
        $this->assertDatabaseMissing('products', ['sku' => 'STALE-1']);

        $this->withHeaders($header + ['Accept' => 'application/json'])
            ->put(route('products.update', $this->betaProduct), ['name' => 'Nope', 'sku' => 'BETA-1', 'price' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error', 'organization_changed');
        $this->nextRequest();
        $this->assertDatabaseHas('products', ['id' => $this->betaProduct->id, 'name' => 'Product BETA-1']);

        // Reads, the current organization's header, switching and signing
        // out all still work from that tab.
        $this->withHeaders($header)->get(route('products.index'))->assertOk();
        $this->nextRequest();
        $this->withHeaders(['X-Inventoros-Organization' => (string) $this->beta->id])
            ->post(route('products.store'), ['sku' => 'FRESH-1', 'name' => 'Meant for Beta', 'price' => 1, 'currency' => 'EUR', 'stock' => 1, 'min_stock' => 0])
            ->assertRedirect(route('products.index'));
        $this->nextRequest();
        $this->assertDatabaseHas('products', ['sku' => 'FRESH-1', 'organization_id' => $this->beta->id]);

        $this->withHeaders(['X-Inventoros-Organization' => (string) $this->beta->id]);
        $this->switchTo($this->alpha)->assertRedirect(route('dashboard'));
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_notifications_of_another_organization_stay_in_it(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        $alphaNote = Notification::create([
            'organization_id' => $this->alpha->id, 'user_id' => $this->user->id, 'type' => 'info',
            'title' => 'Alpha news', 'message' => 'For Alpha', 'action_url' => '/products/'.$this->alphaProduct->id,
        ]);
        $this->signIn();
        $this->switchTo($this->beta);

        $this->getJson(route('notifications.unread-count'))->assertOk()->assertJsonPath('count', 0);
        $this->nextRequest();
        $this->get(route('notifications.index'))->assertInertia(fn ($page) => $page->where('stats.total', 0));
        $this->nextRequest();
        $this->post(route('notifications.mark-as-read', $alphaNote))->assertNotFound();
        $this->nextRequest();
        $this->delete(route('notifications.destroy', $alphaNote))->assertNotFound();
        $this->nextRequest();

        $this->assertDatabaseHas('notifications', ['id' => $alphaNote->id, 'read_at' => null]);
    }

    public function test_an_export_made_in_another_organization_cannot_be_downloaded(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/a.xlsx', 'alpha data');
        $this->addMember($this->beta, $this->user, 'admin');
        $export = DataExport::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $this->alpha->id, 'user_id' => $this->user->id, 'type' => 'products',
            'filename' => 'products.xlsx', 'disk' => 'local', 'path' => 'exports/a.xlsx', 'status' => 'completed',
        ]);
        $this->signIn();

        $this->get(route('import-export.download', $export))->assertOk();
        $this->nextRequest();

        $this->switchTo($this->beta);
        $this->get(route('import-export.download', $export))->assertNotFound();
    }

    public function test_the_active_warehouse_must_belong_to_the_active_organization(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        $alphaWarehouse = Warehouse::factory()->create(['organization_id' => $this->alpha->id, 'is_active' => true]);
        $this->signIn();
        $this->switchTo($this->beta);

        $this->post(route('warehouses.set-active'), ['warehouse_id' => $alphaWarehouse->id])->assertNotFound();
        $this->assertNull(session('active_warehouse_id'));
    }

    public function test_cached_settings_follow_the_active_organization(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        Setting::create(['organization_id' => $this->alpha->id, 'key' => 'feature.flag', 'value' => 'alpha', 'encrypted' => false]);
        Setting::create(['organization_id' => $this->beta->id, 'key' => 'feature.flag', 'value' => 'beta', 'encrypted' => false]);

        $this->actingAs($this->user);
        $this->assertSame('alpha', SettingsService::get('feature.flag'));

        app(ActiveOrganization::class)->activate(auth()->user(), $this->beta->id);
        $this->assertSame('beta', SettingsService::get('feature.flag'));
        $this->assertSame(['BETA-1'], Product::pluck('sku')->all());
    }
}
