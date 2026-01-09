import { test as base, expect } from '@playwright/test';

/**
 * Custom fixtures for Inventoros E2E tests
 */

// Extend base test with custom fixtures
export const test = base.extend({
    // Add any custom fixtures here
});

export { expect };

/**
 * Helper functions for common test operations
 */

/**
 * Generate a unique identifier for test data
 */
export function generateTestId(): string {
    return `test-${Date.now()}-${Math.random().toString(36).substring(7)}`;
}

/**
 * Format currency for assertions
 */
export function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

/**
 * Wait for toast notification
 */
export async function waitForToast(page: any, message: string) {
    await expect(page.locator(`text=${message}`)).toBeVisible({ timeout: 5000 });
}

/**
 * Navigate to a section via sidebar
 */
export async function navigateTo(page: any, section: string) {
    await page.click(`nav >> text=${section}`);
}
