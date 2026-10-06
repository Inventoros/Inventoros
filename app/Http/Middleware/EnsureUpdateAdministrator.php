<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\UpdateAdministration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUpdateAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            UpdateAdministration::canManage($request->user()),
            403,
            'Only administrators of the installation administrator organization can manage updates and backups.',
        );

        return $next($request);
    }
}
