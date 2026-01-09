import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
    // Use a fresh context for auth tests (no stored state)
    test.use({ storageState: { cookies: [], origins: [] } });

    test('should display login page', async ({ page }) => {
        await page.goto('/login');

        await expect(page.locator('h1, h2').filter({ hasText: /login|sign in/i })).toBeVisible();
        await expect(page.locator('input[name="email"]')).toBeVisible();
        await expect(page.locator('input[name="password"]')).toBeVisible();
        await expect(page.locator('button[type="submit"]')).toBeVisible();
    });

    test('should show validation errors for empty form', async ({ page }) => {
        await page.goto('/login');

        // Click submit without filling form
        await page.click('button[type="submit"]');

        // Should show validation errors
        await expect(page.locator('text=/email.*required|required.*email/i')).toBeVisible();
    });

    test('should show error for invalid credentials', async ({ page }) => {
        await page.goto('/login');

        await page.fill('input[name="email"]', 'invalid@example.com');
        await page.fill('input[name="password"]', 'wrongpassword');
        await page.click('button[type="submit"]');

        // Should show authentication error
        await expect(page.locator('text=/credentials|invalid|incorrect/i')).toBeVisible();
    });

    test('should redirect unauthenticated users to login', async ({ page }) => {
        await page.goto('/dashboard');

        // Should redirect to login
        await expect(page).toHaveURL(/.*login.*/);
    });

    test('should display registration page', async ({ page }) => {
        await page.goto('/register');

        await expect(page.locator('input[name="name"]')).toBeVisible();
        await expect(page.locator('input[name="email"]')).toBeVisible();
        await expect(page.locator('input[name="password"]')).toBeVisible();
    });
});
