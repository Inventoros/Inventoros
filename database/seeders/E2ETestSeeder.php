<?php

namespace Database\Seeders;

use App\Models\Auth\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class E2ETestSeeder extends Seeder
{
    /**
     * E2E Test User Credentials
     * These are used by Playwright tests for authentication
     */
    public const TEST_EMAIL = 'e2e-test@inventoros.test';
    public const TEST_PASSWORD = 'E2ETestPassword123!';
    public const TEST_NAME = 'E2E Test User';
    public const TEST_ORG_NAME = 'E2E Test Organization';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Run role seeder first to ensure system roles exist
        $this->call(RoleSeeder::class);

        // Create or update test organization
        $organization = Organization::updateOrCreate(
            ['name' => self::TEST_ORG_NAME],
            [
                'address' => '123 Test Street',
                'city' => 'Test City',
                'state' => 'TS',
                'zip' => '12345',
                'country' => 'Test Country',
                'phone' => '555-0100',
                'email' => 'org@inventoros.test',
            ]
        );

        // Create or update test user with admin role
        $user = User::updateOrCreate(
            ['email' => self::TEST_EMAIL],
            [
                'name' => self::TEST_NAME,
                'password' => Hash::make(self::TEST_PASSWORD),
                'organization_id' => $organization->id,
                'role' => 'admin',
                'email_verified_at' => now(),
                'notification_preferences' => [
                    'email_notifications' => true,
                    'low_stock_alerts' => true,
                    'order_updates' => true,
                ],
            ]
        );

        // Assign system administrator role
        $user->assignRole('system-administrator');

        $this->command->info('E2E test user created successfully.');
        $this->command->info('Email: ' . self::TEST_EMAIL);
        $this->command->info('Password: ' . self::TEST_PASSWORD);
    }

    /**
     * Clean up E2E test data.
     * Can be called after tests to remove test data.
     */
    public static function cleanup(): void
    {
        User::where('email', self::TEST_EMAIL)->delete();
        Organization::where('name', self::TEST_ORG_NAME)->delete();
    }
}
