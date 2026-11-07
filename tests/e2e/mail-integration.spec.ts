import { expect, test } from "@playwright/test";

test("a support conversation shows its linked issue and can create a new one", async ({
    page,
}) => {
    await page.goto("/northwind-labs/mail");
    await page.waitForURL(/\/mail\//);
    await page.getByRole("link", { name: "Support" }).first().click();
    await page
        .locator("article", { hasText: "Export to CSV silently drops" })
        .first()
        .click();
    await expect(
        page.locator('iframe[title="Message body"]').first(),
    ).toBeVisible({ timeout: 15_000 });

    await page.getByRole("button", { name: "More" }).click();
    await page.getByRole("menuitem", { name: /Show context/ }).click();
    await expect(page.getByText("Linked issues")).toBeVisible();
    await expect(page.getByText("FRONT-3").first()).toBeVisible();
    await page.screenshot({
        path: "tests/e2e/screenshots/mail-linked-issues.png",
    });

    await page.keyboard.press("Shift+I");
    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible();
    await expect(
        dialog.getByText(/Export to CSV silently drops/).first(),
    ).toBeVisible();
    await page.screenshot({
        path: "tests/e2e/screenshots/mail-create-issue.png",
    });
    await page.keyboard.press("Escape");
});

test("an issue created from email lists the conversation and the shared excerpt", async ({
    page,
}) => {
    await page.goto("/northwind-labs/issues/FRONT-3");
    await expect(
        page.getByText(/CSV export drops the last row/).first(),
    ).toBeVisible();
    await expect(
        page.getByText(/Export to CSV silently drops the last row/).first(),
    ).toBeVisible();
    await expect(page.getByText(/Shared from email/i).first()).toBeVisible();
});

test("an issue automation rule can be started from the editor", async ({
    page,
}) => {
    await page.goto("/northwind-labs/settings/automations");
    await expect(page.getByText("QA follows Engineering")).toBeVisible();
    await expect(
        page.locator('[data-test="subject-badge-issue"]').first(),
    ).toBeVisible();

    await page.goto(
        "/northwind-labs/settings/automations/create?subject=issue",
    );
    await expect(page.locator('[data-test="subject-issue"]')).toBeVisible();
    await page.screenshot({
        path: "tests/e2e/screenshots/automation-issue-rule.png",
    });
});
