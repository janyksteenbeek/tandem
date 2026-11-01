import { router } from '@inertiajs/vue3';
import { useMediaQuery, useOnline } from '@vueuse/core';
import { computed, readonly, ref, shallowRef } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Everything the app needs from the browser's installed-app machinery: the
 * service worker lifecycle, the install prompt and the network state.
 *
 * State lives at module level because the install prompt fires once, early,
 * and must be caught whether or not a component is listening yet.
 */

type BeforeInstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

const installPrompt = shallowRef<BeforeInstallPromptEvent | null>(null);
const installed = ref(false);
const isBrowser = typeof window !== 'undefined';

if (isBrowser) {
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installPrompt.value = event as BeforeInstallPromptEvent;
    });

    window.addEventListener('appinstalled', () => {
        installPrompt.value = null;
        installed.value = true;
    });
}

/**
 * Register the worker and keep it out of the way: new builds take over
 * silently (assets are hashed, so the open page keeps working and Inertia's
 * own version check reloads on the next visit), visits that fail for want
 * of a connection get a quiet toast instead of Inertia's modal, and the
 * caches are purged whenever a sign-in screen is reached so nothing from
 * the previous session lingers.
 */
export function registerServiceWorker(): void {
    if (!isBrowser || !('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .then((registration) => watchForUpdates(registration))
            .catch(() => undefined);
    });

    router.on('navigate', (event) => {
        if (event.detail.page.component.startsWith('auth/')) {
            navigator.serviceWorker.controller?.postMessage({
                type: 'CLEAR_PAGES',
            });
        }
    });

    router.on('networkError', () => {
        if (navigator.onLine) {
            return;
        }

        toast.error("You're offline", {
            description:
                'That needs a connection. Try again once you are back online.',
        });

        return false;
    });
}

/**
 * A freshly installed worker waits by default until every tab closes. Tell
 * it to take over straight away instead; nothing on screen depends on it.
 */
function watchForUpdates(registration: ServiceWorkerRegistration): void {
    registration.waiting?.postMessage({ type: 'SKIP_WAITING' });

    registration.addEventListener('updatefound', () => {
        const installing = registration.installing;

        installing?.addEventListener('statechange', () => {
            if (installing.state === 'installed') {
                installing.postMessage({ type: 'SKIP_WAITING' });
            }
        });
    });
}

/**
 * Install state for the UI. `canInstall` is only ever true on browsers that
 * expose the prompt; elsewhere the caller may show how to add it by hand.
 */
export function useInstallPrompt() {
    const isStandalone = useMediaQuery('(display-mode: standalone)');

    const canInstall = computed(
        () => installPrompt.value !== null && !installed.value,
    );
    const isInstalled = computed(() => installed.value || isStandalone.value);

    const install = async (): Promise<boolean> => {
        const prompt = installPrompt.value;

        if (!prompt) {
            return false;
        }

        await prompt.prompt();
        const { outcome } = await prompt.userChoice;

        installPrompt.value = null;

        return outcome === 'accepted';
    };

    return { canInstall, isInstalled: readonly(isInstalled), install };
}

export function useNetworkStatus() {
    const isOnline = useOnline();

    return { isOnline };
}
