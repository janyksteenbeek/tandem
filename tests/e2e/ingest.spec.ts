import { expect, test } from '@playwright/test';
import { execSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';

/**
 * A message delivered through the Cloudflare forwarding path appears in the open
 * shared inbox without a reload (webhook → queue → ingestor → Reverb).
 */
test('forwarded mail shows up in the list in real time', async ({ page }) => {
    await page.goto('/northwind-labs/mail');
    await page.waitForURL(/\/mail\//);
    await page.getByRole('link', { name: 'Sales' }).first().click();
    await page.waitForURL(/\/open/);
    await expect(page.locator('article').first()).toBeVisible();

    const address = execSync(
        `mysql -u tandem -ptandem tandem -N -e "SELECT ma.email_address FROM mail_accounts ma JOIN inboxes i ON i.id = ma.inbox_id WHERE ma.provider = 'forwarding' AND i.name = 'Sales' LIMIT 1"`,
        { cwd: process.cwd(), encoding: 'utf8' },
    ).trim();

    const subject = `Realtime forward ${Date.now()}`;
    const eml = `From: "Hedy Lamarr" <hedy@frequency.test>\r\nTo: ${address}\r\nSubject: ${subject}\r\nDate: ${new Date().toUTCString()}\r\nMessage-ID: <rt-${Date.now()}@frequency.test>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nSpread spectrum pricing, please.\r\n`;
    const file = `test-results/realtime-${Date.now()}.eml`;
    writeFileSync(file, eml);

    execSync(`php artisan mail:forwarding:simulate ${address} ${file}`, { cwd: process.cwd(), stdio: 'pipe' });

    await expect(page.locator('article', { hasText: subject })).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('article', { hasText: subject })).toContainText('Hedy Lamarr');
});
