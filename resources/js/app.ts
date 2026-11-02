import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { registerServiceWorker } from '@/composables/usePwa';
import { initializeFlashToast } from '@/lib/flashToast';
import { visitOptions } from '@/lib/viewTransitions';
import { configureEcho } from '@laravel/echo-vue';
import { realtimeOptions } from '@/lib/realtime';
import type { ReverbConnection } from '@/types';

// The same entry renders on the Inertia SSR server, where there is no DOM.
const isBrowser = typeof document !== 'undefined';

// Set from the first page's props in withApp, which runs before the first
// render on both the browser and the SSR server.
let appName = import.meta.env.VITE_APP_NAME || 'Tandem';

type SharedBootProps = {
    branding?: { name?: string };
    reverb?: ReverbConnection | null;
};

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
    withApp: (app, { page }) => {
        const props = page.props as SharedBootProps;

        appName = props.branding?.name || appName;

        // Reverb's address comes from the server with the first page, not from
        // the build, so one bundle serves every host. Echo itself is only
        // created on first use, which never happens during SSR.
        configureEcho(
            realtimeOptions(
                props.reverb,
                isBrowser ? window.location.hostname : '',
            ),
        );

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
