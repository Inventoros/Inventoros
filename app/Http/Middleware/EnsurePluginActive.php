<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Plugin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePluginActive
{
    public function handle(Request $request, Closure $next, string $slug): Response
    {
        abort_unless(Plugin::where('slug', $slug)->where('is_active', true)->exists(), 404);

        return $next($request);
    }
}
