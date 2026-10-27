import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

async function login(page, role) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(`${role}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill(process.env.E2E_ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/$/);
}
async function csv(page, name) {
    const pending = page.waitForEvent('download');
    await page.getByRole('link', { name, exact: true }).click();
    const download = await pending;
    expect(await download.failure()).toBeNull();
    expect(download.suggestedFilename()).toMatch(/\.csv$/);
    return readFile(await download.path(), 'utf8');
}

test('admin reconciles reports with discounted checkout and refund, filters movements and downloads CSV on mobile', async ({ page }) => {
    test.setTimeout(60000);
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await login(page, 'admin');
    await page.goto('/branches/create');
    await page.getByLabel('Branch name').fill('Reporting Store');
    await page.getByLabel('Branch code').fill('REPORT');
    await page.getByLabel('Default tax rate').fill('7.50');
    await page.getByRole('button', { name: 'Create branch' }).click();
    await expect(page.getByRole('status')).toContainText('Branch created');
    await page.goto('/products/create');
    await page.getByLabel('Product name').fill('Reporting Tea E2E');
    await page.getByLabel('SKU').fill('REPORT-E2E');
    await page.getByLabel('Selling price').fill('10.00');
    await page.getByLabel('Opening stock').fill('5');
    await page.getByLabel('Low-stock threshold').fill('3');
    await page.getByRole('button', { name: 'Save product' }).click();
    await page.goto('/pos');
    const product = page.getByRole('button', { name: 'Add Reporting Tea E2E to cart' });
    await product.click(); await product.click();
    await page.locator('#discount_type').selectOption('percent');
    await page.locator('#discount_value').fill('10');
    await page.locator('#discount_reason').fill('Reporting promotion');
    await expect(page.locator('#cart-total')).toHaveText('BDT 19.35');
    await page.getByLabel('Cash received').fill('20.00');
    await page.getByRole('button', { name: 'Complete cash sale' }).click();
    await expect(page).toHaveURL(/\/sales\/\d+$/);
    await page.getByRole('link', { name: 'Record a refund' }).click();
    await page.getByLabel('Return quantity for Reporting Tea E2E').fill('1');
    await page.getByLabel('Refund reason').fill('Returned without restocking');
    await page.getByLabel('I authorize this cash refund').check();
    await page.locator('#refund-submit').click();
    await expect(page).toHaveURL(/\/refunds\/\d+$/);
    await page.getByRole('link', { name: 'Reports & analytics' }).click();
    await expect(page.locator('[data-report="charged"]')).toHaveText('BDT 19.35');
    await expect(page.locator('[data-report="refunded"]')).toHaveText('BDT 9.68');
    await expect(page.locator('[data-report="net"]')).toHaveText('BDT 9.67');
    await expect(page.locator('[data-report="discount"]')).toHaveText('BDT 2.00');
    const today = await page.getByLabel('Through date').inputValue();
    await page.getByLabel('From date').fill(today);
    await page.getByRole('button', { name: 'Apply dates' }).click();
    await expect(page.locator('.report-daily tbody tr')).toHaveCount(1);
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    const sales = await csv(page, 'Download daily sales CSV');
    expect(sales).toContain('net_activity_incl_tax');
    expect(sales).toContain('19.35'); expect(sales).toContain('9.67');
    await page.getByRole('navigation', { name: 'Report sections' }).getByRole('link', { name: 'Stock movements', exact: true }).click();
    await page.getByLabel('Movement type').selectOption('sale');
    await page.getByRole('button', { name: 'Apply filters' }).click();
    await expect(page.locator('.data-table tbody tr')).toHaveCount(1);
    await expect(page.getByRole('table')).toContainText('-2');
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    const movements = await csv(page, 'Download stock movements CSV');
    expect(movements).toContain('Reporting Tea E2E'); expect(movements).toContain(',-2,3,');
    await page.getByRole('link', { name: 'Current low stock', exact: true }).click();
    await expect(page.getByRole('table')).toContainText('Reporting Tea E2E');
    const stock = await csv(page, 'Download current low stock CSV');
    expect(stock).toContain('current_stock,reorder_level'); expect(stock).toContain(',3,3');
    expect(errors).toEqual([]);
});

test('manager can view and export reports only for their assigned branch', async ({ page }) => {
    await login(page, 'manager');
    await page.goto('/reports');
    await expect(page.getByRole('heading', { name: 'The numbers behind the day.' })).toBeVisible();
    await expect(page.getByLabel('Active branch').locator('option')).toHaveText(['Dhanmondi Store']);
    await page.goto('/reports/low-stock');
    await expect(page.getByRole('table')).not.toContainText('Reporting Tea E2E');
    expect(await csv(page, 'Download current low stock CSV')).not.toContain('Reporting Tea E2E');
});

test('cashier cannot access report pages or download endpoints', async ({ page }) => {
    await login(page, 'cashier');
    await expect(page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Reports & analytics' })).toHaveCount(0);
    for (const url of ['/reports', '/reports/inventory', '/reports/low-stock', '/reports/sales.csv', '/reports/inventory.csv', '/reports/low-stock.csv']) {
        expect((await page.goto(url)).status()).toBe(403);
    }
});
