import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

const routerListeners: Record<string, (event: never) => unknown> = {};

vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: vi.fn((name: string, handler: (event: never) => unknown) => {
            routerListeners[name] = handler;

            return () => undefined;
        }),
    },
}));

const toast = Object.assign(vi.fn(), { error: vi.fn() });

vi.mock('vue-sonner', () => ({ toast }));

function installPromptEvent(outcome: 'accepted' | 'dismissed') {
    const event = new Event('beforeinstallprompt', { cancelable: true });

    return Object.assign(event, {
        prompt: vi.fn().mockResolvedValue(undefined),
        userChoice: Promise.resolve({ outcome }),
    });
}

describe('usePwa', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('holds the install prompt until asked and reports the outcome', async () => {
        const { useInstallPrompt } = await import('@/composables/usePwa');
        const { canInstall, install } = useInstallPrompt();

        expect(canInstall.value).toBe(false);
        expect(await install()).toBe(false);

        const event = installPromptEvent('accepted');
        window.dispatchEvent(event);
        await nextTick();

        expect(event.defaultPrevented).toBe(true);
        expect(canInstall.value).toBe(true);

        expect(await install()).toBe(true);
        expect(event.prompt).toHaveBeenCalledOnce();
        expect(canInstall.value).toBe(false);
    });

    it('stops offering the prompt once the app is installed', async () => {
        const { useInstallPrompt } = await import('@/composables/usePwa');
        const { canInstall, isInstalled } = useInstallPrompt();

        window.dispatchEvent(installPromptEvent('dismissed'));
        window.dispatchEvent(new Event('appinstalled'));
        await nextTick();

        expect(canInstall.value).toBe(false);
        expect(isInstalled.value).toBe(true);
    });

    describe('with a service worker', () => {
        const postMessage = vi.fn();

        beforeEach(() => {
            vi.stubGlobal('navigator', {
                onLine: true,
                serviceWorker: {
                    register: vi.fn().mockResolvedValue({
                        waiting: null,
                        addEventListener: vi.fn(),
                    }),
                    addEventListener: vi.fn(),
                    controller: { postMessage },
                },
            });
        });

        it('purges cached pages when a sign-in screen is reached', async () => {
            const { registerServiceWorker } =
                await import('@/composables/usePwa');
            registerServiceWorker();

            const navigate = routerListeners.navigate;
            navigate({
                detail: { page: { component: 'mail/Index' } },
            } as never);
            expect(postMessage).not.toHaveBeenCalled();

            navigate({
                detail: { page: { component: 'auth/Login' } },
            } as never);
            expect(postMessage).toHaveBeenCalledWith({ type: 'CLEAR_PAGES' });
        });

        it('replaces the network error modal with a toast only while offline', async () => {
            const { registerServiceWorker } =
                await import('@/composables/usePwa');
            registerServiceWorker();

            const networkError = routerListeners.networkError;

            expect(networkError({} as never)).toBeUndefined();
            expect(toast.error).not.toHaveBeenCalled();

            vi.stubGlobal('navigator', { onLine: false });

            expect(networkError({} as never)).toBe(false);
            expect(toast.error).toHaveBeenCalledOnce();
        });
    });
});
