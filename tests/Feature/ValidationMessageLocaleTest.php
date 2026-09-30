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

    /**
     * Field names on the order, product and purchase order forms came out
     * raw in other languages ("Le champ customer name est obligatoire.",
     * "items.0.quantity"), and the JSON summary kept Laravel's English
     * "(and 2 more errors)". Both are translated now.
     */
    public function test_a_french_user_gets_french_field_names_on_the_order_form(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('orders.store'), ['items' => [['quantity' => 0]]])
            ->assertStatus(422)
            ->assertJsonPath('errors.customer_name.0', 'Le champ nom du client est obligatoire.')
            ->assertJsonPath('errors.order_date.0', 'Le champ date de commande est obligatoire.');

        $this->assertSame('Le champ produit est obligatoire.', $response->json('errors')['items.0.product_id'][0]);
        $this->assertStringContainsString('quantité', $response->json('errors')['items.0.quantity'][0]);
        foreach (collect($response->json('errors'))->flatten() as $message) {
            $this->assertStringNotContainsString('items.', $message);
            $this->assertStringNotContainsString('_', $message);
        }
    }

    public function test_the_more_errors_suffix_is_translated(): void
    {
        $message = $this->actingAs($this->user)
            ->postJson(route('orders.store'), [])
            ->assertStatus(422)
            ->json('message');

        $this->assertMatchesRegularExpression('/\(et \d+ erreurs de plus\)$/u', $message);
    }

    public function test_english_messages_name_nested_fields_plainly(): void
    {
        $this->user->forceFill(['notification_preferences' => []])->save();

        $errors = $this->actingAs($this->user)
            ->postJson(route('orders.store'), ['items' => [['product_id' => null]]])
            ->assertStatus(422)
            ->json('errors');

        $this->assertSame('The customer name field is required.', $errors['customer_name'][0]);
        $this->assertSame('The product field is required.', $errors['items.0.product_id'][0]);
    }

    public function test_every_locale_names_the_same_form_fields_and_translates_the_suffix(): void
    {
        $english = (require lang_path('en/validation.php'))['attributes'];
        $this->assertArrayHasKey('customer_name', $english);
        $this->assertArrayHasKey('items.*.quantity', $english);

        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            if ($locale === 'en') {
                continue;
            }
            $attributes = (require lang_path("{$locale}/validation.php"))['attributes'];
            $missing = array_diff(array_keys($english), array_keys($attributes));
            $this->assertSame([], array_values($missing), "{$locale} does not name these fields");

            $json = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
            foreach (['(and :count more error)', '(and :count more errors)'] as $key) {
                $this->assertArrayHasKey($key, $json, "{$locale}.json lacks {$key}");
                $this->assertStringContainsString(':count', $json[$key]);
                $this->assertNotSame($key, $json[$key]);
            }
        }

        // Plural forms: Russian, Polish and Arabic change the word with the count.
        $this->assertSame('(и ещё 5 ошибок)', trans_choice('(and :count more errors)', 5, ['count' => 5], 'ru'));
        $this->assertSame('(и ещё 3 ошибки)', trans_choice('(and :count more errors)', 3, ['count' => 3], 'ru'));
        $this->assertSame('(i jeszcze 5 błędów)', trans_choice('(and :count more errors)', 5, ['count' => 5], 'pl'));
        $this->assertSame('(et 4 erreurs de plus)', trans_choice('(and :count more errors)', 4, ['count' => 4], 'fr'));
    }
}
