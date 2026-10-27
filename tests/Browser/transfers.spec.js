import { test, expect } from '@playwright/test';

async function login(page, role) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(`${role}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill(process.env.E2E_ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/$/);
}
async function branch(page, name) {
    await page.getByLabel('Active branch').selectOption({ label: name });
    await page.getByRole('button', { name: 'Switch', exact: true }).click();
}
async function product(page, name, stock) {
    await page.goto('/products/create');
    await page.getByLabel('Product name').fill(name);
    await page.getByLabel('SKU').fill('TRANSFER-E2E');
    await page.getByLabel('Selling price').fill('12.50');
    await page.getByLabel('Opening stock').fill(String(stock));
    await page.getByRole('button', { name: 'Save product' }).click();
    await expect(page.getByRole('status')).toContainText('Product saved');
}

test('admin dispatches between stores and records partial acceptance, damage and loss without duplicating stock', async ({ page }) => {
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    await login(page, 'admin');
    await branch(page, 'Uttara Store');
    await product(page, 'Destination Transfer Tea', 0);
    await branch(page, 'Dhanmondi Store');
    await product(page, 'Source Transfer Tea', 10);
    await page.goto('/transfers/create');
    await page.getByLabel('Destination branch').selectOption({ label: 'Uttara Store · UTT' });
    await page.getByLabel('Find source products').fill('TRANSFER-E2E');
    await page.getByLabel('Find source products').press('Enter');
    await page.getByRole('button', { name: 'Transfer Source Transfer Tea', exact: true }).click();
    await page.getByLabel('Dispatch quantity for Source Transfer Tea').fill('5');
    await page.getByLabel('Dispatch notes').fill('Five matching units sent with courier');
    await page.getByLabel('I verified both product identities').check();
    await page.getByRole('button', { name: 'Dispatch stock', exact: true }).click();
    await expect(page).toHaveURL(/\/transfers\/\d+$/);
    const transferUrl = page.url();
    await expect(page.getByText('5 units still in transit.', { exact: false })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Receive transfer' })).toHaveCount(0);
    await page.emulateMedia({ media: 'print' });
    await expect(page.locator('.workspace-sidebar')).toBeHidden();
    await expect(page.locator('.receipt')).toBeVisible();
    await page.emulateMedia({ media: 'screen' });
    await page.goto('/products?q=TRANSFER-E2E');
    await page.getByRole('link', { name: 'Stock ledger ↗', exact: true }).click();
    await expect(page.getByText('5 units on hand')).toBeVisible();
    await branch(page, 'Uttara Store');
    await page.goto(transferUrl);
    await page.getByRole('link', { name: 'Receive transfer' }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    await page.getByLabel('Accepted: Destination Transfer Tea', { exact: true }).fill('2');
    await page.getByLabel('Damaged: Destination Transfer Tea', { exact: true }).fill('1');
    await page.getByLabel('Delivery / handover reference').fill('VAN-E2E-A');
    await page.getByLabel('Inspection / discrepancy notes').fill('Two accepted, one damaged, two still with courier');
    await page.getByLabel('I inspected these units').check();
    await expect(page.locator('#transfer-receive-submit')).toBeDisabled();
    await page.getByLabel('I authorize these damaged/missing units').check();
    await page.getByRole('button', { name: 'Record transfer receipt' }).click();
    await expect(page).toHaveURL(/\/transfer-receipts\/\d+$/);
    await expect(page.locator('.receipt')).toContainText('Accepted 2 · Damaged 1 · Missing 0');
    await page.goto(transferUrl);
    await expect(page.getByText('2 units still in transit.', { exact: false })).toBeVisible();
    await page.getByRole('link', { name: 'Receive transfer' }).click();
    await page.getByLabel('Accepted: Destination Transfer Tea', { exact: true }).fill('1');
    await page.getByLabel('Confirmed missing: Destination Transfer Tea', { exact: true }).fill('1');
    await page.getByLabel('Delivery / handover reference').fill('VAN-E2E-B');
    await page.getByLabel('Inspection / discrepancy notes').fill('One arrived, final unit confirmed lost by courier');
    await page.getByLabel('I inspected these units').check();
    await page.getByLabel('I authorize these damaged/missing units').check();
    await page.getByRole('button', { name: 'Record transfer receipt' }).click();
    await expect(page.locator('.receipt')).toContainText('Accepted 1 · Damaged 0 · Missing 1');
    await page.emulateMedia({ media: 'print' });
    await expect(page.locator('.receipt')).toBeVisible();
    await expect(page.locator('.workspace-sidebar')).toBeHidden();
    await page.emulateMedia({ media: 'screen' });
    await page.goto(transferUrl);
    await expect(page.getByRole('heading', { name: 'Completed', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Receive transfer' })).toHaveCount(0);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/products?q=TRANSFER-E2E');
    await page.getByRole('link', { name: 'Stock ledger ↗', exact: true }).click();
    await expect(page.getByText('3 units on hand')).toBeVisible();
    await expect(page.getByRole('table')).toContainText('Transfer received Y-');
    expect(errors).toEqual([]);
});

test('cashier cannot access transfer records or catalogs', async ({ page }) => {
    await login(page, 'cashier');
    await expect(page.getByRole('navigation').getByRole('link', { name: 'Stock transfers' })).toHaveCount(0);
    expect((await page.goto('/transfers')).status()).toBe(403);
    expect((await page.goto('/transfers/create')).status()).toBe(403);
});
