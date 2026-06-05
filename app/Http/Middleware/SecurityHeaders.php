<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // The Vite dev server (npm run dev) injects its own client script and
        // relies on eval/websockets; a strict CSP would break local dev. The
        // `hot` file exists only while that server runs and is never present
        // in a production build or in CI, so this cannot weaken prod CSP.
        $viteDevServer = file_exists(public_path('hot'));

        if (! $viteDevServer) {
            Vite::useCspNonce();
        }

        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        if (! $viteDevServer) {
            $nonce = Vite::cspNonce();
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; "
                ."script-src 'self' 'nonce-{$nonce}'; "
                ."style-src 'self' 'unsafe-inline' https://fonts.bunny.net; "
                ."img-src 'self' data: blob:; font-src 'self' data: https://fonts.bunny.net; "
                ."connect-src 'self'; worker-src 'self' blob:; form-action 'self'"
            );
        }

        return $response;
    }
}
