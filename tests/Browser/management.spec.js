import { test, expect } from '@playwright/test';

const password = process.env.E2E_ADMIN_PASSWORD;

async function login(page, role = 'admin', pass = password, email = `${role}@example.test`) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(pass);
    await page.getByRole('button', { name: 'Sign in' }).click();
}

test('admin creates a branch, edits settings, and adds a staff member who changes their temporary password', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    await expect(page.getByRole('heading', { name: 'Hello, Admin Example.' })).toBeVisible();
    await page.getByRole('link', { name: 'Branches & settings' }).click();
    await page.getByRole('link', { name: '+ Add branch' }).click();
    await page.getByLabel('Branch name').fill('Gulshan Store');
    await page.getByLabel('Branch code').fill('GLS');
    await page.getByLabel('Business address').fill('Gulshan, Dhaka');
    await page.getByRole('button', { name: 'Create branch' }).click();
    await expect(page.getByRole('status')).toContainText('Branch created');
    const card = page.locator('.branch-card').filter({ hasText: 'Gulshan Store' });
    await card.getByRole('link', { name: 'Manage settings' }).click();
    await page.getByLabel('Default tax rate').fill('10.25');
    await page.getByLabel('Receipt footer').fill('Come back soon!');
    await page.getByRole('button', { name: 'Save branch settings' }).click();
    await expect(page.getByLabel('Default tax rate')).toHaveValue('10.25');
    await expect(page.getByLabel('Receipt footer')).toHaveValue('Come back soon!');
    await page.getByRole('link', { name: 'User management', exact: false }).first().click();
    await page.getByRole('link', { name: '+ Add staff member' }).click();
    await page.getByLabel('Full name').fill('Nadia Rahman');
    await page.getByLabel('Email address').fill('nadia@example.test');
    await page.getByLabel('Role', { exact: true }).selectOption('cashier');
    await page.getByRole('checkbox', { name: /Gulshan Store/ }).check();
    await page.getByLabel('Temporary password', { exact: false }).fill('NadiaTemporary123');
    await page.getByLabel('Confirm password').fill('NadiaTemporary123');
    await page.getByRole('button', { name: 'Create staff account' }).click();
    await expect(page.getByRole('status')).toContainText('Staff account created');
    await expect(page.getByText('Nadia Rahman', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Sign out' }).click();
    await login(page, 'cashier', 'NadiaTemporary123', 'nadia@example.test');
    await expect(page).toHaveURL(/account\/password/);
    await page.getByLabel('Current password').fill('NadiaTemporary123');
    await page.getByLabel('New password', { exact: true }).fill('NadiaPrivatePass123');
    await page.getByLabel('Confirm new password').fill('NadiaPrivatePass123');
    await page.getByRole('button', { name: 'Update password' }).click();
    await expect(page.getByRole('heading', { name: 'Gulshan Store', exact: true })).toBeVisible();
    await expect(page.getByRole('navigation').getByRole('link', { name: 'User management' })).toHaveCount(0);
    expect(errors).toEqual([]);
});

test('manager sees only their assigned branch and cannot open administrative pages', async ({ page }) => {
    await login(page, 'manager');
    await expect(page.getByLabel('Active branch').locator('option')).toHaveText(['Dhanmondi Store']);
    await page.getByRole('navigation').getByRole('link', { name: 'Branch team' }).click();
    await expect(page.getByRole('heading', { name: 'Dhanmondi Store team.' })).toBeVisible();
    await expect(page.getByRole('table')).toContainText('Manager Example');
    await expect(page.getByRole('table')).not.toContainText('Cashier Example');
    expect((await page.goto('/users')).status()).toBe(403);
    expect((await page.goto('/branches')).status()).toBe(403);
});

test('cashier workspace stays usable on mobile without exposing administration', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, 'cashier');
    await expect(page.getByRole('heading', { name: 'Uttara Store', exact: true })).toBeVisible();
    await expect(page.getByRole('navigation').getByRole('link', { name: 'Branches & settings' })).toHaveCount(0);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    expect(overflow).toBe(false);
    await page.getByRole('link', { name: 'Account security', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Change your password' })).toBeVisible();
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/login/);
});
