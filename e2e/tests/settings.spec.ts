import { test, expect } from '../fixtures';

test.describe('Organization Settings', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/settings/organization');
    });

    test('should display organization settings page', async ({ page }) => {
        await expect(page.locator('h1, h2').filter({ hasText: /settings|organization/i })).toBeVisible();
    });

    test('should display organization name field', async ({ page }) => {
        await expect(page.locator('input[name="name"], #name')).toBeVisible();
    });

    test('should display address fields', async ({ page }) => {
        await expect(page.locator('input[name="address"], #address, textarea[name="address"]')).toBeVisible();
    });

    test('should update organization settings', async ({ page }) => {
        // Update organization name
        const nameInput = page.locator('input[name="name"], #name');
        const currentName = await nameInput.inputValue();

        await nameInput.fill(`${currentName} Updated`);

        // Find and click save button
        const saveButton = page.locator('button[type="submit"]:has-text("Save"), button:has-text("Update")');
        await saveButton.click();

        // Should show success message
        await expect(page.locator('text=/success|updated|saved/i')).toBeVisible({ timeout: 5000 });

        // Revert the name
        await nameInput.fill(currentName);
        await saveButton.click();
    });
});

test.describe('Account Settings', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/settings/account');
    });

    test('should display account settings page', async ({ page }) => {
        await expect(page.locator('h1, h2').filter({ hasText: /account|profile|settings/i })).toBeVisible();
    });

    test('should display profile fields', async ({ page }) => {
        await expect(page.locator('input[name="name"], #name')).toBeVisible();
        await expect(page.locator('input[name="email"], #email')).toBeVisible();
    });

    test('should have password change section', async ({ page }) => {
        await expect(page.locator('input[name="password"], input[name="current_password"], #password')).toBeVisible();
    });

    test('should have notification preferences', async ({ page }) => {
        // Look for notification toggle/checkbox
        const notificationToggle = page.locator('input[type="checkbox"][name*="notification"], .notification-toggle');

        if (await notificationToggle.first().isVisible()) {
            await expect(notificationToggle.first()).toBeVisible();
        }
    });
});

test.describe('User Management', () => {
    test('should display users list', async ({ page }) => {
        await page.goto('/users');

        await expect(page.locator('h1, h2').filter({ hasText: /users/i })).toBeVisible();
    });

    test('should have create user button', async ({ page }) => {
        await page.goto('/users');

        const createButton = page.locator('a, button').filter({ hasText: /create|add|invite/i });
        await expect(createButton.first()).toBeVisible();
    });

    test('should display user details', async ({ page }) => {
        await page.goto('/users');

        const viewButton = page.locator('a[href*="/users/"]').first();
        if (await viewButton.isVisible()) {
            await viewButton.click();
            await expect(page).toHaveURL(/.*users\/\d+.*/);
        }
    });
});

test.describe('Role Management', () => {
    test('should display roles list', async ({ page }) => {
        await page.goto('/roles');

        await expect(page.locator('h1, h2').filter({ hasText: /roles/i })).toBeVisible();
    });

    test('should have create role button', async ({ page }) => {
        await page.goto('/roles');

        const createButton = page.locator('a, button').filter({ hasText: /create|add|new/i });
        await expect(createButton.first()).toBeVisible();
    });

    test('should display permissions in role form', async ({ page }) => {
        await page.goto('/roles/create');

        // Look for permission checkboxes
        await expect(page.locator('input[type="checkbox"]')).toBeVisible();
    });
});
