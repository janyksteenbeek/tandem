import { expect, test } from '@playwright/test';

/**
 * Settings smoke test against the seeded local instance (php artisan migrate:fresh --seed).
 * Screenshots land in tests/e2e/screenshots/settings-*.png for a visual once-over.
 */
const ORG = 'northwind-labs';

test('mail accounts page lists personal accounts and managed inboxes', async ({
    page,
}) => {
    await page.goto('/settings/mail-accounts');

    await expect(
        page.getByRole('heading', { name: 'Personal accounts' }),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', { name: 'Shared inboxes you manage' }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Connect account' }),
    ).toBeVisible();
    await page.screenshot({
        path: 'tests/e2e/screenshots/settings-mail-accounts.png',
        fullPage: true,
    });

    await page.getByRole('button', { name: 'Connect account' }).click();
    await expect(page.getByRole('dialog')).toContainText(
        'Gmail or Google Workspace',
    );
    await page.getByRole('button', { name: /IMAP \/ SMTP/ }).click();
    await expect(page.getByLabel('Email address')).toBeVisible();
    await page.waitForTimeout(400);
    await page.screenshot({
        path: 'tests/e2e/screenshots/settings-connect-imap.png',
    });
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: 'Health' }).first().click();
    await expect(page.getByText('Recent sync runs')).toBeVisible();
    await page.waitForTimeout(700);
    await page.screenshot({
        path: 'tests/e2e/screenshots/settings-health-sheet.png',
    });
});

test('inboxes page shows cards and the access dialog', async ({ page }) => {
    await page.goto(`/settings/organizations/${ORG}/inboxes`);

    await expect(
        page.getByRole('heading', { name: 'Shared inboxes' }),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: 'New inbox' })).toBeVisible();
    await page.screenshot({
        path: 'tests/e2e/screenshots/settings-inboxes.png',
        fullPage: true,
    });

    await page.getByRole('button', { name: 'Access' }).first().click();
    await expect(page.getByRole('dialog')).toContainText('Who can see');
    await page.waitForTimeout(400);
    await page.screenshot({
        path: 'tests/e2e/screenshots/settings-inbox-access.png',
    });
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: 'New inbox' }).click();
    await expect(page.getByRole('dialog')).toContainText('New shared inbox');
    await page.waitForTimeout(400);
    await page.screenshot({
        path: 'tests/e2e/screenshots/settings-inbox-create.png',
    });
});

test('teams page shows cards and the team dialog', async ({ page }) => {
    await page.goto(`/settings/organizations/${ORG}/teams`);

    await expect(
        page.getByRole('heading', { name: 'Teams', exact: true }),
    ).toBeVisible();
    await page.screenshot({
        path: 'tests/e2e/screenshots/settings-teams.png',
        fullPage: true,
    });

    await page.getByRole('button', { name: 'Edit team' }).first().click();
    await expect(page.getByRole('dialog')).toContainText('Members');
    await page.waitForTimeout(400);
    await page.screenshot({
        path: 'tests/e2e/screenshots/settings-team-dialog.png',
    });
});

test('integrations page renders the three cards', async ({ page }) => {
    await page.goto(`/settings/organizations/${ORG}/integrations`);

    await expect(
        page.getByRole('heading', { name: 'Google (Gmail)' }),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', { name: 'Business hours' }),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', { name: 'Outbound SMTP' }),
    ).toBeVisible();
    await page.screenshot({
        path: 'tests/e2e/screenshots/settings-integrations.png',
        fullPage: true,
    });
});

test('settings pages stack on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });

    await page.goto('/settings/mail-accounts');
    await expect(
        page.getByRole('heading', { name: 'Personal accounts' }),
    ).toBeVisible();
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
    await page.screenshot({
        path: 'tests/e2e/screenshots/settings-mail-accounts-mobile.png',
        fullPage: true,
    });

    await page.goto(`/settings/organizations/${ORG}/integrations`);
    await expect(
        page.getByRole('heading', { name: 'Google (Gmail)' }),
    ).toBeVisible();
    await page.screenshot({
        path: 'tests/e2e/screenshots/settings-integrations-mobile.png',
        fullPage: true,
    });
});

test('settings pages hold up in dark mode', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('appearance', 'dark'));
    await page.emulateMedia({ colorScheme: 'dark' });

    await page.goto('/settings/mail-accounts');
    await expect(
        page.getByRole('heading', { name: 'Personal accounts' }),
    ).toBeVisible();
    await page.screenshot({
        path: 'tests/e2e/screenshots/settings-mail-accounts-dark.png',
        fullPage: true,
    });

    await page.goto(`/settings/organizations/${ORG}/inboxes`);
    await expect(
        page.getByRole('heading', { name: 'Shared inboxes' }),
    ).toBeVisible();
    await page.screenshot({
        path: 'tests/e2e/screenshots/settings-inboxes-dark.png',
        fullPage: true,
    });
});
