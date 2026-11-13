import { expect, test } from '@playwright/test';
import type { BrowserContext, Page } from '@playwright/test';

/**
 * A real meeting between two browsers: a signed-in host and a guest without an account, with
 * camera and microphone (Chromium's fake devices), chat, a server-side recording, and the
 * history afterwards.
 *
 * Needs the whole local stack: `php artisan serve`, `reverb:start`, `horizon`, and the media
 * server with recording enabled (`media/bin/tandem-media`, see docs/calls.md).
 */
test.use({
    launchOptions: {
        args: [
            '--use-fake-ui-for-media-stream',
            '--use-fake-device-for-media-stream',
        ],
    },
    permissions: ['camera', 'microphone'],
});

test.setTimeout(180_000);

/** With the Vite dev server running, a file save elsewhere must not reload the call. */
const withoutHotReload = async (context: BrowserContext) => {
    await context.routeWebSocket(/:5173/, () => {});
};

/** How many video elements on the page are showing live frames. */
const liveVideos = (page: Page) =>
    page.evaluate(
        () =>
            [...document.querySelectorAll('video')].filter(
                (video) => video.videoWidth > 0 && !video.paused,
            ).length,
    );

test('a host and a guest meet, chat, record and find it all in the history', async ({
    browser,
}) => {
    const hostContext = await browser.newContext({
        storageState: 'test-results/.auth/demo.json',
    });
    const guestContext = await browser.newContext({
        storageState: { cookies: [], origins: [] },
    });
    await withoutHotReload(hostContext);
    await withoutHotReload(guestContext);

    const host = await hostContext.newPage();
    const guest = await guestContext.newPage();

    // --- The host starts a meeting with one click and lands on the pre-join screen -------
    await host.goto('/northwind-labs/calls');
    await host
        .getByRole('main')
        .getByRole('button', { name: 'Start meeting', exact: true })
        .click();
    await host.waitForURL(/\/meet\/[a-z]{3}-[a-z]{4}-[a-z]{3}$/);
    const code = host.url().split('/meet/')[1];

    await host.getByRole('button', { name: 'Join now' }).click();
    await expect(host.getByLabel('Meeting controls')).toBeVisible({
        timeout: 30_000,
    });

    // --- The guest opens the link, gives a name and waits to be let in -----------------
    await guest.goto(`/meet/${code}`);
    await guest.getByLabel('Your name').fill('Grace Guest');
    await guest.getByRole('button', { name: 'Ask to join' }).click();
    await expect(
        guest.getByRole('heading', { name: 'Waiting to be let in' }),
    ).toBeVisible();

    await host
        .getByRole('button', { name: /^Admit/ })
        .first()
        .click();
    await expect(guest.getByLabel('Meeting controls')).toBeVisible({
        timeout: 30_000,
    });

    // --- Both see their own camera and the other person's --------------------------------
    await expect.poll(() => liveVideos(host), { timeout: 30_000 }).toBe(2);
    await expect.poll(() => liveVideos(guest), { timeout: 30_000 }).toBe(2);
    await expect(host.getByText('Grace Guest').first()).toBeVisible();

    // --- Chat arrives in realtime, in both directions ------------------------------------
    await host.getByRole('button', { name: 'Open chat' }).click();
    await guest.getByRole('button', { name: 'Open chat' }).click();

    await guest
        .getByLabel('Message', { exact: true })
        .fill('Hello from the guest');
    await guest.getByLabel('Message', { exact: true }).press('Enter');
    await expect(host.getByText('Hello from the guest')).toBeVisible({
        timeout: 15_000,
    });

    await host
        .getByLabel('Message', { exact: true })
        .fill('Welcome, the notes are in the chat');
    await host.getByLabel('Message', { exact: true }).press('Enter');
    await expect(
        guest.getByText('Welcome, the notes are in the chat'),
    ).toBeVisible({ timeout: 15_000 });

    // --- Recording runs on the server; the guest is told and has to choose ---------------
    await host.getByRole('button', { name: 'Record' }).click();
    await host.getByRole('menuitem', { name: /Full meeting/ }).click();

    await expect(
        guest.getByText('This meeting is being recorded').first(),
    ).toBeVisible({ timeout: 20_000 });
    await guest.getByRole('button', { name: 'Stay in the meeting' }).click();
    await expect(host.getByTestId('recording-indicator')).toBeVisible({
        timeout: 20_000,
    });
    await expect(guest.getByTestId('recording-indicator')).toBeVisible();

    // Give the recorder something to capture.
    await host.waitForTimeout(8_000);

    await host.getByRole('button', { name: /Recording/ }).click();
    await host.getByRole('menuitem', { name: 'Stop recording' }).click();
    await expect(host.getByTestId('recording-indicator')).toBeHidden({
        timeout: 20_000,
    });

    // --- The host ends the meeting for everyone ------------------------------------------
    await host.getByLabel('Leave or end the meeting').click();
    await host
        .getByRole('menuitem', { name: 'End meeting for everyone' })
        .click();
    const confirm = host.getByRole('button', { name: /End meeting/ });

    if (await confirm.isVisible().catch(() => false)) {
        await confirm.click();
    }

    await expect(guest.getByText(/meeting has ended/i)).toBeVisible({
        timeout: 20_000,
    });

    // --- Afterwards: chat, attendees and the finished recording are in the history -------
    await host.goto('/northwind-labs/calls');
    await host.getByText(`Meeting ${code}`).first().click();
    await host.waitForURL(/\/calls\/[0-9a-f-]{36}$/);

    await expect(host.getByText('Hello from the guest')).toBeVisible();
    await expect(host.getByText('Grace Guest').first()).toBeVisible();

    await expect(async () => {
        await host.reload();
        await expect(host.getByText('Ready', { exact: true })).toBeVisible({
            timeout: 2_000,
        });
    }).toPass({ timeout: 60_000 });

    // Two microphones and two cameras, each captured as its own track.
    await expect(host.getByText(/4 tracks/)).toBeVisible();

    // --- The recording plays back --------------------------------------------------------
    await host.getByRole('link', { name: 'Play', exact: true }).first().click();
    await host.waitForURL(/\/recordings\/[0-9a-f-]{36}$/);
    await host.getByRole('button', { name: 'Play', exact: true }).click();

    await expect
        .poll(
            () =>
                host.evaluate(
                    () =>
                        [...document.querySelectorAll('video, audio')].filter(
                            (media) =>
                                (media as HTMLMediaElement).currentTime > 1,
                        ).length,
                ),
            { timeout: 30_000 },
        )
        .toBeGreaterThanOrEqual(2);

    await hostContext.close();
    await guestContext.close();
});
