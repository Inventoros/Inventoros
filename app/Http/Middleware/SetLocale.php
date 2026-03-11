<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED_LOCALES = [
        'en', 'es', 'fr', 'de', 'pt-BR', 'it',
        'ja', 'ko', 'zh-CN', 'ar', 'ru', 'nl', 'tr', 'pl',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie('locale') ?? config('app.locale');

        if (in_array($locale, self::SUPPORTED_LOCALES)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
