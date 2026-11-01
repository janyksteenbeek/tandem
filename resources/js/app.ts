import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { registerServiceWorker } from '@/composables/usePwa';
import { initializeFlashToast } from '@/lib/flashToast';
import { configureEcho } from '@laravel/echo-vue';

configureEcho({
    broadcaster: 'reverb',
});

// The same entry renders on the Inertia SSR server, where there is no DOM.
const isBrowser = typeof document !== 'undefined';

const initialPage = (
    isBrowser
        ? JSON.parse(document.getElementById('app')?.dataset.page ?? '{}')
        : {}
) as { props?: { branding?: { name?: string } } };
const appName =
    initialPage.props?.branding?.name ||
    import.meta.env.VITE_APP_NAME ||
    'Tandem';

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
