import { expect, test } from '@playwright/test';

test.describe('projects workspace', () => {
    test('products, projects and teams render with the seeded data', async ({
        page,
    }) => {
        await page.goto('/northwind-labs/products');
        await expect(
            page.getByRole('link', { name: /Portivio Frontier/ }).first(),
        ).toBeVisible();
        await expect(page.getByText('FRONT').first()).toBeVisible();

        await page
            .getByRole('link', { name: /Portivio Frontier/ })
            .first()
            .click();
        await page.waitForURL(/\/products\/portivio-frontier/);
        await expect(
            page.getByRole('heading', { name: 'Portivio Frontier' }),
        ).toBeVisible();
        await page.screenshot({
            path: 'tests/e2e/screenshots/product-overview.png',
        });

        await page.goto('/northwind-labs/projects');
        await expect(
            page.getByText('Reporting Redesign').first(),
        ).toBeVisible();
        await expect(page.getByText('Gmail Integration').first()).toBeVisible();

        await page.goto('/northwind-labs/teams');
        await expect(page.getByText('Engineering').first()).toBeVisible();
        await expect(page.getByText('QA').first()).toBeVisible();
    });

    test('the issue list opens the showcase issue with three workstreams', async ({
        page,
    }) => {
        await page.goto('/northwind-labs/issues');
        await expect(page.getByText('FRONT-1').first()).toBeVisible({
            timeout: 15_000,
        });
        await page
            .getByRole('link', { name: /Saved reports: pin, rename/ })
            .first()
            .click();
        await page.waitForURL(/\/issues\/FRONT-1/);

        await expect(
            page
                .getByText(/Saved reports: pin, rename and share a report/)
                .first(),
        ).toBeVisible();
        await expect(page.getByText('Engineering').first()).toBeVisible();
        await expect(page.getByText('Design').first()).toBeVisible();
        await expect(page.getByText('QA').first()).toBeVisible();
        await page.screenshot({
            path: 'tests/e2e/screenshots/issue-detail.png',
        });
    });

    test('an overridden completion and an org-level issue are reachable by identifier', async ({
        page,
    }) => {
        await page.goto('/northwind-labs/issues/FRONT-5');
        await expect(
            page.getByText(/Invoice PDF attached twice/).first(),
        ).toBeVisible();

        await page.goto('/northwind-labs/issues/NWL-1');
        await expect(
            page.getByText(/Office move checklist/).first(),
        ).toBeVisible();
    });

    test('a new issue can be created from the keyboard', async ({ page }) => {
        await page.goto('/northwind-labs/issues');
        await expect(page.getByText('FRONT-1').first()).toBeVisible({
            timeout: 15_000,
        });
        await page.keyboard.press('c');
        await expect(page.getByRole('dialog')).toBeVisible();
        await page.screenshot({
            path: 'tests/e2e/screenshots/create-issue.png',
        });
        await page.keyboard.type('Playwright created this issue');
        await page.keyboard.press('Meta+Enter');
        await expect(page.getByText(/[A-Z]+-\d+ created/).first()).toBeVisible({
            timeout: 15_000,
        });
    });

    test('the board, calendar and timeline modes render for a project', async ({
        page,
    }) => {
        await page.goto('/northwind-labs/projects/reporting-redesign/board');
        await expect(page.getByText(/In Progress/).first()).toBeVisible({
            timeout: 15_000,
        });
        await page.screenshot({
            path: 'tests/e2e/screenshots/project-board.png',
        });

        await page.goto('/northwind-labs/projects/reporting-redesign/timeline');
        await expect(
            page.getByText('Reporting Redesign').first(),
        ).toBeVisible();
        await page.screenshot({
            path: 'tests/e2e/screenshots/project-timeline.png',
        });

        await page.goto('/northwind-labs/issues?mode=calendar');
        await expect(page.getByText(/Today|Mon|Tue/).first()).toBeVisible({
            timeout: 15_000,
        });
    });

    test('cycles and reports pages render', async ({ page }) => {
        await page.goto('/northwind-labs/teams/engineering/cycles');
        await expect(page.getByText('Dashboards beta').first()).toBeVisible({
            timeout: 15_000,
        });
        await page.screenshot({ path: 'tests/e2e/screenshots/cycles.png' });

        await page.goto('/northwind-labs/reports');
        await expect(page.getByText(/Completed/).first()).toBeVisible({
            timeout: 15_000,
        });
        await page.screenshot({ path: 'tests/e2e/screenshots/reports.png' });
    });

    test('the command palette finds issues', async ({ page }) => {
        await page.goto('/northwind-labs/issues');
        await page.keyboard.press('Meta+k');
        await page.keyboard.type('dashboard grid');
        await expect(page.getByText(/Dashboard grid/).first()).toBeVisible({
            timeout: 10_000,
        });
        await page.keyboard.press('Escape');
    });
});
