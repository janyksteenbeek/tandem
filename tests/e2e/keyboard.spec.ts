import { expect, test } from '@playwright/test';

test('the list is fully keyboard driven', async ({ page }) => {
    await page.goto('/northwind-labs/mail');
    await page.waitForURL(/\/mail\//);
    await page.getByRole('link', { name: 'Support' }).first().click();
    await page.waitForURL(/\/open/);
    await expect(page.locator('article').first()).toBeVisible();

    await page.keyboard.press('j');
    await page.keyboard.press('j');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/open\/[0-9a-f-]{36}/);
    await expect(page.getByRole('heading', { level: 2 })).toBeVisible();

    await page.keyboard.press('k');
    await expect(page.getByRole('heading', { level: 2 })).toBeVisible();

    await page.keyboard.press('Shift+/');
    await expect(page.getByText('Keyboard shortcuts')).toBeVisible();
    await page.keyboard.press('Escape');

    await page.keyboard.press('x');
    await expect(page.getByText('1 selected')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByText('1 selected')).toHaveCount(0);
});
