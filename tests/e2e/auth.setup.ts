import { expect, test as setup } from '@playwright/test';

const authFile = 'test-results/.auth/demo.json';

setup('authenticate as the demo user', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('demo@tandem.test');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/mail\//);
    await expect(page.getByText('Shared')).toBeVisible();
    await page.context().storageState({ path: authFile });
});
