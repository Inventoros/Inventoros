<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Server-side validation messages were always English: the app had no
 * lang/ files. Laravel's validation, auth, passwords and pagination strings
 * now ship for every UI locale, and the request locale (the user's saved
 * language, then the language cookie, then the default) applies to web and
 * REST validation responses.
 */
class ValidationMessageLocaleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $organization = Organization::create(['name' => 'Acme', 'email' => 'acme@example.com', 'currency' => 'USD', 'timezone' => 'UTC']);

        $this->user = User::create([
            'name' => 'Marie', 'email' => 'marie@example.com', 'password' => bcrypt('password'),
            'organization_id' => $organization->id,
        ]);
        $this->user->forceFill([
            'role' => 'admin',
            'notification_preferences' => ['preferences' => ['language' => 'fr']],
        ])->save();
    }

    public function test_every_ui_locale_has_the_laravel_message_files(): void
    {
        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            if ($locale === 'en') {
                continue; // Laravel's own English files.
            }
            foreach (['validation', 'auth', 'passwords', 'pagination'] as $file) {
                $this->assertFileExists(lang_path("{$locale}/{$file}.php"));
            }
            $this->assertNotSame(
                trans('validation.required', [], 'en'),
                trans('validation.required', [], $locale),
                "{$locale} has no translated validation.required",
            );
        }
    }

    public function test_a_french_user_gets_french_web_validation_messages(): void
    {
        $this->actingAs($this->user)
            ->from(route('suppliers.create'))
            ->post(route('suppliers.store'), [])
            ->assertSessionHasErrors(['name' => 'Le champ nom est obligatoire.']);
    }

    public function test_a_french_user_gets_french_api_validation_messages_with_a_bearer_token(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/suppliers', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Le champ nom est obligatoire.');
    }

    public function test_a_guest_with_the_language_cookie_gets_messages_in_that_language(): void
    {
        $this->withUnencryptedCookie(SetLocale::COOKIE, 'de')
            ->from(route('login'))
            ->post(route('login'), [])
            ->assertSessionHasErrors(['email' => 'E-Mail-Adresse muss ausgefüllt werden.']);
    }

    public function test_an_english_user_still_gets_english_messages(): void
    {
        $this->user->forceFill(['notification_preferences' => []])->save();

        $this->actingAs($this->user)
            ->from(route('suppliers.create'))
            ->post(route('suppliers.store'), [])
            ->assertSessionHasErrors(['name' => 'The name field is required.']);
    }
}
