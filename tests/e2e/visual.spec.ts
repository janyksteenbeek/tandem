import { expect, test } from '@playwright/test';

test('mobile layout stacks the list and the thread', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/northwind-labs/mail');
    await page.waitForURL(/\/mail\//);
    await expect(page.locator('article').first()).toBeVisible();
    await page.screenshot({ path: 'tests/e2e/screenshots/mobile-list.png' });
    await page.locator('article').first().click();
    await expect(page.getByRole('heading', { level: 2 })).toBeVisible();
    await expect(
        page.locator('iframe[title="Message body"]').first(),
    ).toBeVisible({ timeout: 15_000 });
    await page.screenshot({ path: 'tests/e2e/screenshots/mobile-thread.png' });
    const width = await page.evaluate(
        () => document.documentElement.scrollWidth,
    );
    expect(width).toBeLessThanOrEqual(390);
});

test('dark mode renders with the brand palette', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('appearance', 'dark'));
    await page.goto('/northwind-labs/mail');
    await page.waitForURL(/\/mail\//);
    await page.getByRole('link', { name: 'Support' }).first().click();
    await page.locator('article', { hasText: 'Export to CSV' }).first().click();
    await expect(
        page.locator('iframe[title="Message body"]').first(),
    ).toBeVisible({ timeout: 15_000 });
    await page.screenshot({ path: 'tests/e2e/screenshots/dark-shared.png' });
});
