import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import type { Plugin } from 'vite';
import { defineConfig, lazyPlugins } from 'vite-plus';

/**
 * The face tracker behind camera effects (MediaPipe Tasks) reports usage and performance
 * metrics to Google from every browser that runs it, and has no setting to turn that off.
 * Tandem sends nothing to third parties, so the address it reports to is taken out here: the
 * reporter's first attempt then fails without touching the network and it gives up for good.
 *
 * If the address is no longer in the package the build stops, so an upgrade cannot bring the
 * reporting back unnoticed.
 */
function withoutMediapipeMetrics(): Plugin {
    const endpoint = '"https://odml.pa.googleapis.com/v1/log"';

    return {
        name: 'tandem:without-mediapipe-metrics',
        enforce: 'pre',
        transform(code, id) {
            if (
                !/@mediapipe[\\/]tasks-vision[\\/]vision_bundle\.m?js/.test(id)
            ) {
                return null;
            }

            if (!code.includes(endpoint)) {
                this.error(
                    'MediaPipe no longer reports metrics to the address this build removes. Check where it reports to now before building with this version.',
                );
            }

            return {
                code: code.replaceAll(endpoint, '"about:blank"'),
                map: null,
            };
        },
    };
}

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Figtree', {
                    weights: [400, 500, 600, 700],
                    styles: ['normal', 'italic'],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
        withoutMediapipeMetrics(),
    ]),
    optimizeDeps: {
        // Pre-bundled dependencies skip plugins, and with them the one above.
        exclude: ['@mediapipe/tasks-vision'],
    },
    ssr: {
        // The SSR bundle runs in a container without node_modules (the Dockerfile removes them
        // after the build), so every dependency has to be inlined into bootstrap/ssr/app.js.
        noExternal: true,
    },
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                // Runtime data (logs, uploads, recordings, Inertia's dev SSR error dumps): a write
                // here must never reload the page, or a dev-mode SSR error loops forever.
                '**/storage/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'deploy/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            // Kubernetes manifests keep the cluster's two-space style.
            'deploy/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
