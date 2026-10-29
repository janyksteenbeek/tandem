import { expect, test } from '@playwright/test';

/**
 * Two people look at the same shared conversation; a comment posted by one shows up
 * for the other through Reverb without a reload. Requires `reverb:start` and `horizon`.
 */
test('internal comments arrive in real time for teammates', async ({ browser }) => {
    const alice = await browser.newContext({ storageState: 'test-results/.auth/demo.json' });
    const bob = await browser.newContext({ storageState: 'test-results/.auth/demo.json' });
    const a = await alice.newPage();
    const b = await bob.newPage();

    for (const page of [a, b]) {
        await page.goto('/northwind-labs/mail');
        await page.waitForURL(/\/mail\//);
        await page.getByRole('link', { name: 'Support' }).first().click();
        await page.locator('article', { hasText: 'Wrong timezone' }).first().click();
        await expect(page.locator('iframe[title="Message body"]').first()).toBeVisible({ timeout: 15_000 });
    }

    const note = `Realtime check ${Date.now()}`;
    await a.getByRole('button', { name: /Comment/ }).click();
    await a.locator('.tiptap').last().fill(note);
    await a.keyboard.press('Meta+Enter');

    await expect(a.getByText(note)).toBeVisible();
    await expect(b.getByText(note)).toBeVisible({ timeout: 20_000 });

    await alice.close();
    await bob.close();
});
