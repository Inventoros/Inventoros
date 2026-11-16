<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle` for the installer routes.
 *
 * The shared rate limiter is bound at boot to the configured cache store,
 * usually `database`, whose table does not exist before the installer has
 * migrated. This limiter uses the store chosen for the current request,
 * which UseFileDriversUntilInstalled switches to `file` until Inventoros is
 * installed.
 */
final class ThrottleInstaller extends ThrottleRequests
{
    public function __construct(CacheFactory $cache)
    {
        parent::__construct(new RateLimiter($cache->store()));
    }
}
