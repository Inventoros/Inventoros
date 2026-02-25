import { test as setup, expect } from '@playwright/test';
import { E2E_TEST_USER } from '../test-credentials';

const authFile = 'e2e/.auth/user.json';

/**
 * Authentication setup - runs before all tests
 * This logs in the E2E test user (created by E2ETestSeeder) and saves the authentication state
 */
setup('authenticate', async ({ page }) => {
    // Navigate to login page and wait for Vue to hydrate
    await page.goto('/login', { waitUntil: 'networkidle' });

    // Fill in credentials from E2E test user (seeded by global-setup.ts)
    await page.fill('#email', E2E_TEST_USER.email);
    await page.fill('#password', E2E_TEST_USER.password);

    // Submit the form
    await page.getByRole('button', { name: 'Log in' }).click();

    // Wait for navigation to dashboard
    await page.waitForURL('/dashboard');

    // Verify we're logged in
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();

    // Save authentication state
    await page.context().storageState({ path: authFile });
});
