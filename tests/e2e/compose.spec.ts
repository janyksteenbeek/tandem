import { expect, test } from '@playwright/test';

test('replying from a shared inbox queues an outbound message', async ({
    page,
}) => {
    await page.goto('/northwind-labs/mail');
    await page.waitForURL(/\/mail\//);
    await page.getByRole('link', { name: 'Support' }).first().click();
    await page
        .locator('article', { hasText: 'Invoice attached twice' })
        .first()
        .click();
    await expect(
        page.locator('iframe[title="Message body"]').first(),
    ).toBeVisible({ timeout: 15_000 });

    await page.getByRole('button', { name: /Reply to/ }).click();
    const editor = page.locator('.tiptap').last();
    await expect(editor).toBeVisible();
    await page.screenshot({ path: 'tests/e2e/screenshots/composer.png' });
    await editor.click();
    await page.keyboard.press('Meta+ArrowUp');
    await page.keyboard.type(
        'Thanks Tim, we have fixed the duplicate attachment. ',
    );
    await expect(page.getByText(/Saved|Saving/)).toBeVisible({
        timeout: 10_000,
    });
    await page.getByRole('button', { name: /^Send$/ }).click();
    await expect(page.getByText('you').first()).toBeVisible({
        timeout: 15_000,
    });
    await expect(
        page.getByText(/Thanks Tim, we have fixed/).first(),
    ).toBeVisible({ timeout: 15_000 });
    await page.screenshot({ path: 'tests/e2e/screenshots/sent-reply.png' });
});

test('a personal conversation can be shared with a team inbox', async ({
    page,
}) => {
    await page.goto('/northwind-labs/mail');
    await page.waitForURL(/\/mail\//);
    await page.locator('article', { hasText: 'boarding pass' }).first().click();
    await expect(
        page.locator('iframe[title="Message body"]').first(),
    ).toBeVisible({ timeout: 15_000 });
    await page.getByRole('button', { name: 'More' }).click();
    await page.getByRole('menuitem', { name: /Share or transfer/ }).click();
    await expect(page.getByText('Bring the team in')).toBeVisible();
    await page.screenshot({ path: 'tests/e2e/screenshots/share-dialog.png' });
    await page.getByRole('button', { name: 'Sales' }).click();
    await page.getByRole('button', { name: 'Share with team' }).click();
    await expect(page.getByText('+1 shared')).toBeVisible({ timeout: 10_000 });
});
