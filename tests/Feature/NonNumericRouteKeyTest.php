<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RejectNonNumericModelKeys;
use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * A non-numeric value for an integer-keyed model parameter (for example
 * /webhooks/create, or a mistyped id) must be a 404. On PostgreSQL the
 * binding query `where id = 'create'` is a hard error on a bigint column,
 * so without this guard those URLs were a 500 there and a 404 elsewhere.
 */
final class NonNumericRouteKeyTest extends TestCase
{
    private function handle(string $value): \Symfony\Component\HttpFoundation\Response
    {
        $route = new Route(['GET'], '/webhooks/{webhook}', ['uses' => fn (Webhook $webhook) => 'ok']);
        $request = Request::create('/webhooks/'.$value);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return (new RejectNonNumericModelKeys)->handle($request, fn () => response('passed'));
    }

    public function test_non_numeric_key_for_an_integer_model_is_a_404_before_binding(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->handle('create');
    }

    public function test_numeric_key_passes_through_to_binding(): void
    {
        $this->assertSame('passed', $this->handle('42')->getContent());
    }

    public function test_the_guard_runs_in_the_web_and_api_groups(): void
    {
        $this->assertTrue(
            in_array(RejectNonNumericModelKeys::class, app('router')->getMiddlewareGroups()['web'], true),
            'The guard must be registered in the web group'
        );
        $this->assertTrue(
            in_array(RejectNonNumericModelKeys::class, app('router')->getMiddlewareGroups()['api'], true),
            'The guard must be registered in the api group'
        );
    }
}
