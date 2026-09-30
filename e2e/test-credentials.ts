/**
 * E2E Test Credentials
 * These MUST match the values in database/seeders/E2ETestSeeder.php
 */
export const E2E_TEST_USER = {
    email: 'e2e-test@inventoros.test',
    password: 'E2ETestPassword123!',
    name: 'E2E Test User',
};

export const E2E_TEST_ORGANIZATION = {
    name: 'E2E Test Organization',
};

/**
 * A member holding only the Warehouse Staff permission set (E2ETestSeeder),
 * with an order and a purchase order to look at.
 */
export const E2E_STAFF_USER = {
    email: 'e2e-staff@inventoros.test',
    password: 'E2EStaffPassword123!',
    orderNumber: 'E2E-ORDER-1',
    poNumber: 'E2E-PO-1',
    productName: 'E2E Test Product',
    productSku: 'E2E-PRODUCT-1',
};
