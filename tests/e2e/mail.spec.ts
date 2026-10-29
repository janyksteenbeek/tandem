import { expect, test, type Page } from '@playwright/test';

/**
 * Smoke test against a seeded local instance (php artisan migrate:fresh --seed).
 * Run with: npx playwright test
 */
async function login(page: Page) {
    await page.goto('/northwind-labs/mail');
    await page.waitForURL(/\/mail\//);
}

test('a user lands in their mailbox and can open a conversation', async ({ page }) => {
    await login(page);

    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByText('Shared')).toBeVisible();

    const firstRow = page.locator('article').first();
    await firstRow.click();

    await expect(page.getByRole('heading', { level: 2 })).toBeVisible();
    await expect(page.locator('iframe[title="Message body"]').first()).toBeVisible({ timeout: 15_000 });
    await page.screenshot({ path: 'tests/e2e/screenshots/personal-inbox.png', fullPage: false });
});

test('a shared inbox shows team controls and internal comments', async ({ page }) => {
    await login(page);

    await page.getByRole('link', { name: 'Support' }).first().click();
    await page.waitForURL(/\/mail\/[0-9a-f-]+\/open/);

    await page.locator('article', { hasText: 'Export to CSV' }).first().click();
    await expect(page.getByRole('button', { name: /Comment/ })).toBeVisible();
    await expect(page.getByText('internal').first()).toBeVisible();
    await expect(page.getByRole('button', { name: /Priya|Unassigned|You/ }).first()).toBeVisible();
    await page.screenshot({ path: 'tests/e2e/screenshots/shared-inbox.png' });
});

test('the command palette searches across inboxes', async ({ page }) => {
    await login(page);

    await page.keyboard.press('Meta+K');
    await page.getByPlaceholder(/Search mail/).fill('export');
    await expect(page.getByRole('option', { name: /Export to CSV/ })).toBeVisible();
});
