import { expect, test } from '@playwright/test';

test.describe('navigation shell', () => {
    test('the rail switches between mail, projects, calls and settings', async ({
        page,
    }) => {
        await page.goto('/northwind-labs/mail');
        await page.waitForURL(/\/mail\//);

        const rail = page.getByRole('navigation', { name: 'Primary' });
        await expect(rail.getByRole('link', { name: 'Mail' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        // Home, Mail, Projects, Calls and Settings.
        await expect(rail.getByRole('link')).toHaveCount(5);

        await rail.getByRole('link', { name: 'Projects' }).click();
        await page.waitForURL(/\/my-issues$/);
        await expect(
            rail.getByRole('link', { name: 'Projects' }),
        ).toHaveAttribute('aria-current', 'page');
        await expect(
            page.getByRole('heading', { name: 'My issues' }),
        ).toBeVisible();
        await page.screenshot({
            path: 'tests/e2e/screenshots/nav-projects.png',
        });

        await rail.getByRole('link', { name: 'Calls' }).click();
        await page.waitForURL(/\/calls$/);
        await expect(rail.getByRole('link', { name: 'Calls' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        await expect(
            page
                .getByRole('main')
                .getByRole('button', { name: 'Start meeting', exact: true }),
        ).toBeVisible();

        await rail.getByRole('link', { name: 'Settings' }).click();
        await page.waitForURL(/\/settings\/profile$/);
        await expect(
            rail.getByRole('link', { name: 'Settings' }),
        ).toHaveAttribute('aria-current', 'page');
        await expect(page.getByLabel('Find a setting')).toBeVisible();

        await rail.getByRole('link', { name: 'Mail' }).click();
        await page.waitForURL(/\/mail\//);
        await expect(page.getByText('Shared')).toBeVisible();
    });

    test('the projects rail item returns to the page you left', async ({
        page,
    }) => {
        await page.goto('/northwind-labs/teams/engineering/board');
        await expect(
            page.getByRole('heading', { name: 'Engineering' }),
        ).toBeVisible();

        const rail = page.getByRole('navigation', { name: 'Primary' });
        await rail.getByRole('link', { name: 'Mail' }).click();
        await page.waitForURL(/\/mail\//);
        await rail.getByRole('link', { name: 'Projects' }).click();
        await page.waitForURL(/\/teams\/engineering\/board$/);
    });

    test('the sidebar lists each entity once and only project teams', async ({
        page,
    }) => {
        await page.goto('/northwind-labs/products/portivio-frontier');
        const sidebar = page.getByRole('navigation', { name: 'Projects' });

        await expect(
            sidebar.locator(
                'a[href="/northwind-labs/products/portivio-frontier"]',
            ),
        ).toHaveCount(2);
        await expect(sidebar.getByText('Your teams')).toBeVisible();
        await expect(
            sidebar.getByRole('link', { name: 'Design', exact: true }),
        ).toBeVisible();
        await expect(
            sidebar.getByRole('link', { name: 'Finance', exact: true }),
        ).toHaveCount(0);
        await expect(
            sidebar.getByRole('link', { name: 'Sales', exact: true }),
        ).toHaveCount(0);

        await page.getByRole('button', { name: 'Switch organization' }).click();
        await expect(
            page.getByRole('menuitem', { name: /Northwind Labs/ }),
        ).toBeVisible();
        await expect(
            page.getByRole('menuitem', { name: 'Organization settings' }),
        ).toBeVisible();
        await page.keyboard.press('Escape');
    });

    test('the sidebar collapses with the keyboard and the rail toggle', async ({
        page,
    }) => {
        await page.goto('/northwind-labs/my-issues');
        const column = page.locator('#app-shell-column');
        await expect(column).toBeVisible();

        await page.keyboard.press('Meta+\\');
        await expect(column).toHaveCount(0);
        await page.screenshot({
            path: 'tests/e2e/screenshots/nav-collapsed.png',
        });

        await page.getByRole('button', { name: 'Show sidebar' }).click();
        await expect(column).toBeVisible();
    });

    test('breadcrumbs start at the section on every page', async ({ page }) => {
        await page.goto('/northwind-labs/issues/FRONT-1');
        const crumbs = page.getByRole('navigation', { name: 'breadcrumb' });
        await expect(
            crumbs.getByRole('link', { name: 'Projects' }),
        ).toBeVisible();
        await expect(
            crumbs.getByRole('link', { name: 'Reporting Redesign' }),
        ).toBeVisible();
        await expect(crumbs.getByText('FRONT-1')).toBeVisible();
        await expect(
            page
                .getByRole('navigation', { name: 'Primary' })
                .getByRole('link', { name: 'Projects' }),
        ).toHaveAttribute('aria-current', 'page');

        await page.goto('/northwind-labs/projects/reporting-redesign');
        await expect(
            crumbs.getByRole('link', { name: 'Projects' }),
        ).toBeVisible();

        await page.goto(
            '/northwind-labs/products/portivio-frontier/modules/reporting',
        );
        await expect(
            crumbs.getByRole('link', { name: 'Products' }),
        ).toBeVisible();
        await expect(
            crumbs.getByRole('link', { name: 'Modules' }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'Reporting' }),
        ).toBeVisible();
    });

    test('the mobile sheet opens the full navigation', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/northwind-labs/issues');
        await page.getByRole('button', { name: 'Open navigation' }).click();

        const sheet = page.getByRole('dialog');
        await expect(
            sheet.getByRole('button', { name: 'Switch organization' }),
        ).toBeVisible();
        await expect(
            sheet.getByRole('link', { name: 'My issues' }),
        ).toBeVisible();
        await page.screenshot({ path: 'tests/e2e/screenshots/nav-mobile.png' });
    });
});
