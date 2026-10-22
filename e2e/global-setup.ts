import { execSync } from 'child_process';
import { FullConfig } from '@playwright/test';

/**
 * Global setup for Playwright E2E tests
 * Runs before all tests to set up the test environment
 */
async function globalSetup(config: FullConfig): Promise<void> {
    console.log('\n🔧 Setting up E2E test environment...\n');

    try {
        // Seed every fixture before auth.setup saves the shared login. Layout
        // seeding also refreshes the staff password, revoking older sessions.
        console.log('📦 Running E2E fixture seeders...');
        execSync('php artisan db:seed --class=E2ELayoutSeeder --force', {
            cwd: process.cwd(),
            stdio: 'inherit',
            env: {
                ...process.env,
                // Ensure we're using the testing environment if needed
                // APP_ENV: 'testing',
            },
        });
        console.log('✅ E2E test user created successfully\n');
    } catch (error) {
        console.error('❌ Failed to run E2E seeder:', error);
        console.log('\n⚠️  Make sure PHP is available and the Laravel app is configured correctly.');
        console.log('   You can manually run: php artisan db:seed --class=E2ETestSeeder\n');
        throw error;
    }
}

export default globalSetup;
