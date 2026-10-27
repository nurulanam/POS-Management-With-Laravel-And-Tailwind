import { test, expect } from '@playwright/test';

async function login(page, role) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(`${role}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill(process.env.E2E_ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/$/);
}

test('manager creates suppliers, partially receives an order, closes its remainder, then receives another in full', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page, 'manager');
    await page.getByRole('link', { name: 'Suppliers', exact: true }).click();
    await page.getByRole('link', { name: '+ Add supplier' }).click();
    await page.getByLabel('Supplier name').fill('Wholesale Tea E2E');
    await page.getByLabel('Supplier code').fill('WHOLESALE-E2E');
    await page.getByLabel('Contact person').fill('Mina Ahmed');
    await page.getByRole('button', { name: 'Save supplier' }).click();
    await expect(page.getByRole('table')).toContainText('Wholesale Tea E2E');
    await page.getByRole('link', { name: 'Products & stock' }).click();
    await page.getByRole('link', { name: '+ Add product' }).click();
    await page.getByLabel('Product name').fill('Purchase Tea E2E');
    await page.getByLabel('SKU').fill('PURCHASE-E2E');
    await page.getByLabel('Selling price').fill('12.50');
    await page.getByLabel('Opening stock').fill('0');
    await page.getByRole('button', { name: 'Save product' }).click();
    async function createOrder(quantity) {
        await page.goto('/purchases/create');
        await page.getByLabel('Supplier *', { exact: true }).selectOption({ label: 'Wholesale Tea E2E · WHOLESALE-E2E' });
        await page.getByLabel('Find a product to order').fill('PURCHASE-E2E');
        await page.getByLabel('Find a product to order').press('Enter');
        await page.getByRole('button', { name: 'Order Purchase Tea E2E', exact: true }).click();
        await page.getByLabel('Order quantity for Purchase Tea E2E').fill(String(quantity));
        await page.getByLabel('Unit cost for Purchase Tea E2E').fill('6.25');
        await page.getByLabel('I checked the supplier').check();
        await page.getByRole('button', { name: 'Create purchase order', exact: true }).click();
        await expect(page).toHaveURL(/\/purchases\/\d+$/);
    }
    await createOrder(5);
    const orderUrl = page.url();
    await expect(page.locator('[data-order-total]')).toHaveText('BDT 31.25');
    await page.getByRole('link', { name: 'Receive goods' }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    await page.getByLabel('Receive quantity for Purchase Tea E2E').fill('2');
    await expect(page.locator('#receiving-total')).toHaveText('BDT 12.50');
    await page.getByLabel('Delivery / packing-slip reference').fill('DELIVERY-E2E-A');
    await page.getByLabel('Receiving notes').fill('Two sellable units inspected');
    await page.getByLabel('I counted and inspected these goods').check();
    await page.getByRole('button', { name: 'Record goods receipt' }).click();
    await expect(page).toHaveURL(/\/purchase-receipts\/\d+$/);
    await expect(page.locator('[data-received-total]')).toHaveText('BDT 12.50');
    await page.emulateMedia({ media: 'print' });
    await expect(page.locator('.workspace-sidebar')).toBeHidden();
    await expect(page.locator('.receipt')).toBeVisible();
    await page.emulateMedia({ media: 'screen' });
    await page.goto(orderUrl);
    await expect(page.getByText('Partially received', { exact: true })).toBeVisible();
    await expect(page.locator('[data-order-total]')).toHaveText('BDT 31.25');
    await page.getByLabel('Reason for closing').fill('Supplier cannot deliver the remaining units');
    await page.getByLabel('I confirm the outstanding units').check();
    await page.getByRole('button', { name: 'Close outstanding quantities', exact: true }).click();
    await expect(page.locator('.badge').filter({ hasText: 'Closed short' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Receive goods' })).toHaveCount(0);
    await page.emulateMedia({ media: 'print' });
    await expect(page.locator('.receipt')).toBeVisible();
    await page.emulateMedia({ media: 'screen' });
    await createOrder(3);
    await page.getByRole('link', { name: 'Receive goods' }).click();
    await page.getByRole('button', { name: 'Receive all remaining units' }).click();
    await expect(page.locator('#receiving-total')).toHaveText('BDT 18.75');
    await page.getByLabel('Delivery / packing-slip reference').fill('DELIVERY-E2E-B');
    await page.getByLabel('Receiving notes').fill('Full replacement order inspected');
    await page.getByLabel('I counted and inspected these goods').check();
    await page.getByRole('button', { name: 'Record goods receipt' }).click();
    await expect(page.locator('[data-received-total]')).toHaveText('BDT 18.75');
    await page.getByRole('link', { name: 'Purchase order', exact: true }).click();
    await expect(page.getByText('Fully received', { exact: true })).toBeVisible();
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/products?q=PURCHASE-E2E');
    await page.getByRole('link', { name: 'Stock ledger ↗', exact: true }).click();
    await expect(page.getByText('5 units on hand')).toBeVisible();
    await expect(page.getByRole('table')).toContainText('Goods received G-');
    await page.goto('/purchase-receipts?q=DELIVERY-E2E');
    await expect(page.getByRole('table')).toContainText('DELIVERY-E2E-A');
    await expect(page.getByRole('table')).toContainText('DELIVERY-E2E-B');
    expect(errors).toEqual([]);
});

test('cashier cannot open supplier, purchasing or receiving screens', async ({ page }) => {
    await login(page, 'cashier');
    await expect(page.getByRole('navigation').getByRole('link', { name: 'Purchasing', exact: true })).toHaveCount(0);
    await expect(page.getByRole('navigation').getByRole('link', { name: 'Suppliers', exact: true })).toHaveCount(0);
    for (const url of ['/suppliers', '/purchases', '/purchases/create', '/purchase-receipts']) {
        expect((await page.goto(url)).status()).toBe(403);
    }
});
