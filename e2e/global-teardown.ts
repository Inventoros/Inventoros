import { execSync } from 'child_process';
import { FullConfig } from '@playwright/test';

/**
 * Global teardown for Playwright E2E tests
 * Runs after all tests to clean up the test environment
 */
async function globalTeardown(config: FullConfig): Promise<void> {
    console.log('\n🧹 Cleaning up E2E test environment...\n');

    // Optional: Clean up test data after tests
    // Uncomment if you want to remove test user after tests complete
    /*
    try {
        console.log('🗑️  Removing E2E test data...');
        execSync('php artisan tinker --execute="Database\\Seeders\\E2ETestSeeder::cleanup();"', {
            cwd: process.cwd(),
            stdio: 'inherit',
        });
        console.log('✅ E2E test data cleaned up\n');
    } catch (error) {
        console.warn('⚠️  Failed to clean up E2E test data:', error);
    }
    */

    console.log('✅ E2E tests completed\n');
}

export default globalTeardown;
