<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FileStorageService;
use App\Support\StorageAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class StorageSettingsController extends Controller
{
    public function index(Request $request, FileStorageService $storage): Response
    {
        abort_unless(StorageAdministration::canManage($request->user()), 403);

        return Inertia::render('Settings/Storage', ['disks' => $storage->disks()]);
    }

    public function check(Request $request, FileStorageService $storage): JsonResponse
    {
        abort_unless(StorageAdministration::canManage($request->user()), 403);
        $data = $request->validate([
            'disk' => ['required', 'string', Rule::in(array_values($storage->purposes()))],
        ]);

        return response()->json($storage->check($data['disk']));
    }
}
