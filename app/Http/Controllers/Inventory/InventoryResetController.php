<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\InventoryResetRun;
use App\Services\InventoryResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InventoryResetController extends Controller
{
    public function preview(Request $request, InventoryResetService $reset)
    {
        $preview = $reset->preview($request->user());
        $token = (string) Str::uuid();
        $request->session()->put('stock_reset_preview', [
            'token' => $token, 'signature' => $preview['signature'], 'organization_id' => $request->user()->organization_id,
            'expires' => now()->addMinutes(10)->timestamp,
        ]);

        return Inertia::render('Products/ResetStock', [
            'preview' => $preview, 'token' => $token, 'organizationName' => $request->user()->organization->name,
            'pendingRun' => InventoryResetRun::where('organization_id', $request->user()->organization_id)
                ->whereIn('status', ['pending', 'running'])->value('public_id'),
        ]);
    }

    public function store(Request $request, InventoryResetService $reset)
    {
        $data = $request->validate([
            'token' => ['required', 'uuid'], 'confirmation' => ['required', Rule::in(['ZERO STOCK'])],
            'password' => ['required', 'current_password'], 'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $preview = $request->session()->get('stock_reset_preview');
        if (! $preview || ! hash_equals($preview['token'], $data['token'])
            || (int) $preview['organization_id'] !== (int) $request->user()->organization_id || $preview['expires'] <= now()->timestamp) {
            throw ValidationException::withMessages(['reset' => 'stockReset.errors.expired']);
        }
        $run = $reset->queue($request->user(), $preview['signature'], $data['reason']);
        $request->session()->forget('stock_reset_preview');

        return redirect()->route('products.stock-reset.show', $run->public_id);
    }

    public function show(Request $request, InventoryResetRun $resetRun)
    {
        abort_unless((int) $resetRun->organization_id === (int) $request->user()->organization_id, 404);

        return Inertia::render('Products/ResetStockStatus', ['run' => $resetRun->only([
            'public_id', 'status', 'counts', 'reason', 'error_code', 'created_at', 'finished_at',
        ])]);
    }
}
