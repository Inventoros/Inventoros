<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Routing\UrlGenerator;

/** Token-bearing email links must never inherit an incoming Host header. */
final class ApplicationUrl
{
    public static function route(string $name, array $parameters = []): string
    {
        return self::generator()->route($name, $parameters);
    }

    public static function temporarySignedRoute(string $name, DateTimeInterface $expiration, array $parameters = []): string
    {
        return self::generator()->temporarySignedRoute($name, $expiration, $parameters);
    }

    private static function generator(): UrlGenerator
    {
        // Clone so ordinary navigation keeps its request context. Force both
        // root and scheme: forceRootUrl alone still accepts the request scheme.
        $generator = clone app('url');
        // Reset the cached RouteUrlGenerator, which otherwise still points to
        // the original instance after a shallow clone.
        $generator->setRequest($generator->getRequest());
        $root = (string) config('app.url');
        $generator->useOrigin($root);
        $generator->forceScheme(parse_url($root, PHP_URL_SCHEME));

        return $generator;
    }
}
