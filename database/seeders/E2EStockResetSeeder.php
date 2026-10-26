<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Isolated stock: resetting it cannot change another browser test's data. */
final class E2EStockResetSeeder extends Seeder
{
    public function run(): void
    {
        $id = (string) env('E2E_RESET_FIXTURE_ID');
        if (! Str::isUuid($id)) {
            throw new \InvalidArgumentException('E2E_RESET_FIXTURE_ID must be a fresh UUID.');
        }
        $organization = Organization::create(['name' => 'Reset fixture '.$id, 'email' => $id.'@reset.test',
            'currency' => 'USD', 'timezone' => 'UTC']);
        User::create(['name' => 'Reset administrator', 'email' => $id.'@reset.test',
            'password' => Hash::make('E2EResetPassword123!'), 'organization_id' => $organization->id,
            'role' => 'admin', 'email_verified_at' => now()]);
        Product::create(['organization_id' => $organization->id, 'sku' => 'RESET-'.$id,
            'name' => 'Reset fixture product', 'price' => 10, 'currency' => 'USD', 'stock' => 12,
            'min_stock' => 0, 'is_active' => true]);
    }
}
