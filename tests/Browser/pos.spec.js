import { test, expect } from '@playwright/test';

async function login(page, role) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(`${role}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill(process.env.E2E_ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/$/);
}

test('manager receives stock, completes cash checkout, prints an immutable receipt and reviews the ledger', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page, 'manager');
    await page.getByRole('link', { name: 'Products & stock' }).click();
    await page.getByRole('link', { name: '+ Add product' }).click();
    await page.getByLabel('Product name').fill('Coffee Beans E2E');
    await page.getByLabel('SKU').fill('COFFEE-E2E');
    await page.getByLabel('Barcode', { exact: true }).fill('9876543210');
    await page.getByLabel('Selling price').fill('10.25');
    await page.getByLabel('Opening stock').fill('10');
    await page.getByRole('button', { name: 'Save product' }).click();
    await expect(page.getByRole('status')).toContainText('Product saved');
    await page.getByRole('link', { name: 'Customers', exact: true }).click();
    await page.getByRole('link', { name: '+ Add customer' }).click();
    await page.getByLabel('Customer name').fill('Lina Ahmed E2E');
    await page.getByLabel('Phone number').fill('01700000001');
    await page.getByRole('button', { name: 'Save customer' }).click();
    await page.getByRole('link', { name: 'Point of sale', exact: true }).click();
    await page.getByLabel('Find a product').fill('9876543210');
    await page.getByLabel('Find a product').press('Enter');
    const product = page.getByRole('button', { name: 'Add Coffee Beans E2E to cart' });
    await product.click();
    await product.click();
    await expect(page.getByLabel('Quantity for Coffee Beans E2E')).toHaveValue('2');
    await page.getByLabel('Customer', { exact: true }).selectOption({ label: 'Lina Ahmed E2E · 01700000001' });
    await expect(page.locator('#cart-total')).toHaveText('BDT 22.04');
    await page.getByLabel('Cash received').fill('30.00');
    await expect(page.locator('#cart-change')).toHaveText('BDT 7.96');
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    await page.getByRole('button', { name: 'Complete cash sale' }).click();
    await expect(page).toHaveURL(/\/sales\/\d+$/);
    const receiptUrl = page.url();
    await expect(page.locator('[data-receipt-total]')).toHaveText('BDT 22.04');
    await expect(page.locator('.receipt')).toContainText('Lina Ahmed E2E');
    await expect(page.locator('.receipt')).toContainText('BDT 7.96');
    await page.emulateMedia({ media: 'print' });
    await expect(page.locator('.workspace-sidebar')).toBeHidden();
    await expect(page.locator('.receipt')).toBeVisible();
    await page.emulateMedia({ media: 'screen' });
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.getByRole('link', { name: 'Products & stock' }).click();
    await page.getByRole('link', { name: 'Stock ledger' }).click();
    await expect(page.getByText('8 units on hand')).toBeVisible();
    await expect(page.getByRole('table')).toContainText('Sale S-');
    await page.getByLabel('Quantity change').fill('5');
    await page.getByLabel('Reason', { exact: false }).fill('Supplier delivery');
    await page.getByRole('button', { name: 'Record adjustment' }).click();
    await expect(page.getByText('13 units on hand')).toBeVisible();
    await page.getByRole('link', { name: 'Back to products' }).click();
    await page.getByRole('link', { name: 'Edit', exact: true }).click();
    await page.getByLabel('Product name').fill('Renamed Coffee');
    await page.getByLabel('Selling price').fill('20.00');
    await page.getByRole('button', { name: 'Save product' }).click();
    await page.goto(receiptUrl);
    await expect(page.locator('.receipt')).toContainText('Coffee Beans E2E');
    await expect(page.locator('.receipt')).not.toContainText('Renamed Coffee');
    await expect(page.locator('[data-receipt-total]')).toHaveText('BDT 22.04');
    expect(errors).toEqual([]);
});

test('cashier cannot see another branch catalog or change inventory', async ({ page }) => {
    await login(page, 'cashier');
    await page.getByRole('link', { name: 'Point of sale', exact: true }).click();
    await expect(page.locator('#catalog-message')).toContainText('No products found');
    await expect(page.locator('#catalog-grid')).not.toContainText('Coffee');
    expect((await page.goto('/products')).status()).toBe(403);
});
