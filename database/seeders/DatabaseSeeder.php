<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with a development login.
     *
     * Only outside production, and only when Faker (a dev dependency) is
     * installed: release packages are built with `composer install --no-dev`,
     * and a known test login must never exist on a live install. Create real
     * users with the web installer instead.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Nothing to seed in production. Create the first admin with the web installer (/install).');

            return;
        }

        if (! $this->fakerAvailable()) {
            $this->command?->warn('Skipping the test user: Faker is not installed (composer install without --no-dev).');

            return;
        }

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }

    protected function fakerAvailable(): bool
    {
        return class_exists(\Faker\Factory::class);
    }
}
