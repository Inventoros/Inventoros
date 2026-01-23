import { test as setup, expect } from '@playwright/test';
import { E2E_TEST_USER } from '../test-credentials';

const authFile = 'e2e/.auth/user.json';

/**
 * Authentication setup - runs before all tests
 * This logs in the E2E test user (created by E2ETestSeeder) and saves the authentication state
 */
setup('authenticate', async ({ page }) => {
    // Navigate to login page
    await page.goto('/login');

    // Fill in credentials from E2E test user (seeded by global-setup.ts)
    await page.fill('input[name="email"]', E2E_TEST_USER.email);
    await page.fill('input[name="password"]', E2E_TEST_USER.password);

    // Submit the form
    await page.click('button[type="submit"]');

    // Wait for navigation to dashboard
    await page.waitForURL('/dashboard');

    // Verify we're logged in
    await expect(page.locator('text=Dashboard')).toBeVisible();

    // Save authentication state
    await page.context().storageState({ path: authFile });
});
