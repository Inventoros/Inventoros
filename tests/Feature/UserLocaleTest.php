<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The saved language preference drives the app locale.
 *
 * SetLocale resolves, in order: the authenticated user's saved language, the
 * `locale` cookie the language switcher sets, then config('app.locale'). The
 * result is shared with the frontend as the `locale` prop and the <html lang>.
 */
class UserLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);
    }

    private function preferLanguage(string $language): void
    {
        $this->user->forceFill([
            'notification_preferences' => ['preferences' => ['language' => $language]],
        ])->save();
    }

    public function test_the_default_locale_is_shared_when_nothing_is_set(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.account.index'))
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_the_users_saved_language_sets_the_locale(): void
    {
        $this->preferLanguage('fr');

        $response = $this->actingAs($this->user)->get(route('settings.account.index'));

        $response->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));
        $response->assertSee('<html lang="fr"', false);
    }

    public function test_the_users_saved_language_wins_over_the_cookie(): void
    {
        $this->preferLanguage('de');

        $this->actingAs($this->user)
            ->withUnencryptedCookie('locale', 'es')
            ->get(route('settings.account.index'))
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'de'));
    }

    /**
     * The language switcher writes the cookie from JavaScript, so it is never
     * encrypted. If the cookie is not excluded from encryption Laravel drops it
     * and the switch silently does nothing.
     */
    public function test_the_cookie_set_by_the_language_switcher_is_honoured(): void
    {
        $this->withUnencryptedCookie('locale', 'es')
            ->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'es'));
    }

    public function test_an_unsupported_saved_language_falls_back(): void
    {
        $this->preferLanguage('xx');

        $this->actingAs($this->user)
            ->withUnencryptedCookie('locale', 'it')
            ->get(route('settings.account.index'))
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'it'));
    }

    public function test_saving_preferences_rejects_an_unsupported_language(): void
    {
        $this->actingAs($this->user)
            ->patch(route('settings.account.update.preferences'), [
                'theme' => 'dark',
                'language' => 'klingon',
                'items_per_page' => 25,
            ])
            ->assertSessionHasErrors('language');
    }

    public function test_saving_preferences_changes_the_locale_on_the_next_request(): void
    {
        $this->actingAs($this->user)
            ->patch(route('settings.account.update.preferences'), [
                'theme' => 'dark',
                'language' => 'pt-BR',
                'items_per_page' => 25,
            ])
            ->assertSessionHasNoErrors();

        $this->get(route('settings.account.index'))
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'pt-BR'));
    }

    /**
     * The top-strip switcher persists the choice for signed-in users, and
     * keeps their other preferences intact.
     */
    public function test_the_language_switcher_endpoint_saves_the_users_language(): void
    {
        $this->user->forceFill([
            'notification_preferences' => [
                'low_stock_alerts' => false,
                'preferences' => ['theme' => 'light', 'items_per_page' => 50],
            ],
        ])->save();

        $this->actingAs($this->user)
            ->patch(route('settings.account.update.locale'), ['locale' => 'ja'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $prefs = $this->user->fresh()->notification_preferences;
        $this->assertSame('ja', $prefs['preferences']['language']);
        $this->assertSame('light', $prefs['preferences']['theme']);
        $this->assertSame(50, $prefs['preferences']['items_per_page']);
        $this->assertFalse($prefs['low_stock_alerts']);
    }

    public function test_the_language_switcher_endpoint_rejects_unsupported_locales(): void
    {
        $this->actingAs($this->user)
            ->patch(route('settings.account.update.locale'), ['locale' => 'xx'])
            ->assertSessionHasErrors('locale');
    }
}
