<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\InventoryResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        ]);
    }

    public function store(Request $request, InventoryResetService $reset)
    {
        $data = $request->validate([
            'token' => ['required', 'uuid'], 'confirmation' => ['required', Rule::in(['ZERO STOCK'])],
            'password' => ['required', 'current_password'], 'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $preview = $request->session()->get('stock_reset_preview');
        abort_unless($preview && hash_equals($preview['token'], $data['token']) && $preview['organization_id'] === $request->user()->organization_id && $preview['expires'] > now()->timestamp, 419);
        $reset->reset($request->user(), $preview['signature'], $data['reason']);
        $request->session()->forget('stock_reset_preview');

        return redirect()->route('products.index')->with('success', 'Stock quantities reset to zero. Products and history have been kept.');
    }
}
