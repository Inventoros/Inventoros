<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Reflector;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answer 404 for a non-numeric value in an integer-keyed model parameter,
 * before route-model binding queries the database.
 *
 * `/webhooks/create` matches `/webhooks/{webhook}`, and so does any mistyped
 * id. SQLite and MySQL simply find no row, but PostgreSQL rejects
 * `where id = 'create'` against a bigint column, so the same URL was a 404 on
 * one database and a 500 on another. Runs just before SubstituteBindings.
 */
class RejectNonNumericModelKeys
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route instanceof Route) {
            foreach ($route->signatureParameters(['subClass' => Model::class]) as $parameter) {
                $name = $parameter->getName();

                if ($route->hasParameter($name)) {
                    $value = $route->parameter($name);
                } elseif ($route->hasParameter(Str::snake($name))) {
                    $name = Str::snake($name);
                    $value = $route->parameter($name);
                } else {
                    continue;
                }

                // Already bound, or a custom binding field such as {product:slug}.
                if (! is_string($value) || $route->bindingFieldFor($name) !== null) {
                    continue;
                }

                if (ctype_digit($value)) {
                    continue;
                }

                $class = Reflector::getParameterClassName($parameter);
                if ($class === null || ! is_subclass_of($class, Model::class)) {
                    continue;
                }

                /** @var Model $model */
                $model = new $class;

                if ($model->getRouteKeyName() === $model->getKeyName()
                    && $model->getIncrementing()
                    && in_array($model->getKeyType(), ['int', 'integer'], true)) {
                    abort(404);
                }
            }
        }

        return $next($request);
    }
}
