import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { registerServiceWorker } from '@/composables/usePwa';
import { initializeFlashToast } from '@/lib/flashToast';
import { visitOptions } from '@/lib/viewTransitions';
import { configureEcho } from '@laravel/echo-vue';
import type { ReverbConnection } from '@/types';

// The same entry renders on the Inertia SSR server, where there is no DOM.
const isBrowser = typeof document !== 'undefined';

const initialPage = (
    isBrowser
        ? JSON.parse(document.getElementById('app')?.dataset.page ?? '{}')
        : {}
) as {
    props?: { branding?: { name?: string }; reverb?: ReverbConnection | null };
};
const appName =
    initialPage.props?.branding?.name ||
    import.meta.env.VITE_APP_NAME ||
    'Tandem';

// Reverb's address comes from the server with the first page, not from the
// build, so one bundle serves every host. Echo itself is only created on first
// use, which never happens during SSR.
const reverb = initialPage.props?.reverb;
const reverbScheme =
    reverb?.scheme ??
    (isBrowser && window.location.protocol === 'http:' ? 'http' : 'https');
const reverbPort = reverb?.port ?? (reverbScheme === 'https' ? 443 : 80);

if (reverb?.key) {
    configureEcho({
        broadcaster: 'reverb',
        key: reverb.key,
        wsHost:
            reverb.host ?? (isBrowser ? window.location.hostname : undefined),
        wsPort: reverbPort,
        wssPort: reverbPort,
        forceTLS: reverbScheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
} else {
    // Without a key (BROADCAST_CONNECTION is not reverb, or REVERB_APP_KEY is
    // empty on the web pods) realtime is off; everything else keeps working.
    configureEcho({ broadcaster: 'null' });

    if (isBrowser && import.meta.env.DEV) {
        console.warn(
            'Realtime is disabled: the server shared no Reverb app key.',
        );
    }
}

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
            case name.startsWith('organizations/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#603df3',
    },
    defaults: {
        visitOptions,
    },
});

if (isBrowser) {
    // This will set light / dark mode on page load...
    initializeTheme();

    // This will listen for flash toast data from the server...
    initializeFlashToast();

    // Installable app: offline fallback, update toasts, cache purge on sign-out.
    // Skipped in dev so the Vite dev server is never served from a cache.
    if (import.meta.env.PROD) {
        registerServiceWorker();
    }
}
