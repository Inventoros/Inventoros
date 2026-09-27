<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * The interactive API docs are private outside local development unless
 * API_DOCS_PUBLIC=true opts in.
 */
class ApiDocsAccessTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $this->app['env'] = 'production';
    }

    private function user(): User
    {
        return $this->makeMember($this->makeOrganization('Acme'));
    }

    public function test_guests_cannot_view_the_docs_in_production(): void
    {
        $this->assertFalse(Gate::allows('viewApiDocs'));
        $this->get('/docs/api')->assertForbidden();
    }

    public function test_signed_in_users_can_view_the_docs(): void
    {
        $user = $this->user();

        $this->assertTrue(Gate::forUser($user)->allows('viewApiDocs'));
        $this->actingAs($user)->get('/docs/api')->assertOk();
    }

    public function test_docs_can_be_made_public_by_config(): void
    {
        config(['scramble.public_docs' => true]);

        $this->assertTrue(Gate::allows('viewApiDocs'));
        $this->get('/docs/api')->assertOk();
    }

    public function test_docs_are_open_in_local_development(): void
    {
        $this->app['env'] = 'local';

        $this->assertTrue(Gate::allows('viewApiDocs'));
    }
}
