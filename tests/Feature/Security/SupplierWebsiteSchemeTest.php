<?php

namespace Tests\Feature\Security;

use App\Models\Auth\Organization;
use App\Models\Inventory\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * A supplier's website is rendered as a link, so every way of saving it
 * (web form, REST API, GraphQL) only accepts http and https URLs.
 */
final class SupplierWebsiteSchemeTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();

        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonWebUrls(): array
    {
        return [
            'javascript' => ['javascript://example.com/%0Aalert(1)'],
            'data' => ['data://text/html,hello'],
            'ftp' => ['ftp://files.example.com/catalog.pdf'],
            'file' => ['file://server/share/catalog.pdf'],
        ];
    }

    private function supplier(string $website = 'https://acme.example.com'): Supplier
    {
        return Supplier::create([
            'organization_id' => $this->org->id,
            'name' => 'Existing',
            'website' => $website,
        ]);
    }

    #[DataProvider('nonWebUrls')]
    public function test_the_web_form_refuses_a_non_web_website(string $url): void
    {
        $this->actingAs($this->admin)
            ->post(route('suppliers.store'), ['name' => 'Bad', 'website' => $url])
            ->assertSessionHasErrors('website');

        $supplier = $this->supplier();
        $this->actingAs($this->admin)
            ->put(route('suppliers.update', $supplier), ['name' => 'Existing', 'website' => $url])
            ->assertSessionHasErrors('website');

        $this->assertDatabaseMissing('suppliers', ['name' => 'Bad']);
        $this->assertSame('https://acme.example.com', $supplier->fresh()->website);
    }

    #[DataProvider('nonWebUrls')]
    public function test_the_rest_api_refuses_a_non_web_website(string $url): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/suppliers', ['name' => 'Bad', 'website' => $url])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('website');

        $supplier = $this->supplier();
        $this->putJson("/api/v1/suppliers/{$supplier->id}", ['name' => 'Existing', 'website' => $url])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('website');

        $this->assertDatabaseMissing('suppliers', ['name' => 'Bad']);
        $this->assertSame('https://acme.example.com', $supplier->fresh()->website);
    }

    #[DataProvider('nonWebUrls')]
    public function test_graphql_refuses_a_non_web_website(string $url): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/graphql', [
            'query' => 'mutation($w: String) { createSupplier(name: "Bad", website: $w) { id } }',
            'variables' => ['w' => $url],
        ])->assertJsonPath('data.createSupplier', null);

        $supplier = $this->supplier();
        $this->postJson('/graphql', [
            'query' => 'mutation($id: Int!, $w: String) { updateSupplier(id: $id, website: $w) { id } }',
            'variables' => ['id' => $supplier->id, 'w' => $url],
        ])->assertJsonPath('data.updateSupplier', null);

        $this->assertDatabaseMissing('suppliers', ['name' => 'Bad']);
        $this->assertSame('https://acme.example.com', $supplier->fresh()->website);
    }

    public function test_http_and_https_websites_are_still_accepted_everywhere(): void
    {
        $this->actingAs($this->admin)
            ->post(route('suppliers.store'), ['name' => 'Web form', 'website' => 'https://web.example.com/path?q=1'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('suppliers', ['name' => 'Web form', 'website' => 'https://web.example.com/path?q=1']);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/suppliers', ['name' => 'Api', 'website' => 'http://api.example.com'])
            ->assertCreated();
        $this->assertDatabaseHas('suppliers', ['name' => 'Api', 'website' => 'http://api.example.com']);

        $this->postJson('/graphql', [
            'query' => 'mutation { createSupplier(name: "Graph", website: "https://graph.example.com") { id website } }',
        ])->assertJsonPath('data.createSupplier.website', 'https://graph.example.com');
    }
}
