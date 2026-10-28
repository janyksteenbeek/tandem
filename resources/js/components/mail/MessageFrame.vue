<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useAppearance } from '@/composables/useAppearance';

/**
 * Renders provider HTML inside a sandboxed, script-less iframe so email CSS can never
 * leak into the app and nothing in the message can run code.
 */
const props = defineProps<{ html: string | null; text: string | null; allowRemoteImages?: boolean }>();

const frame = ref<HTMLIFrameElement | null>(null);
const height = ref(80);
const { resolvedAppearance } = useAppearance();
let observer: ResizeObserver | null = null;

const escape = (value: string) =>
    value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const body = computed(() => {
    if (props.html) {
        return props.allowRemoteImages === false ? props.html.replace(/(<img\b[^>]*\bsrc=)(["'])(https?:)/gi, '$1$2data:,blocked#$3') : props.html;
    }

    return `<pre class="plain">${escape(props.text ?? '')}</pre>`;
});

const srcdoc = computed(() => {
    const dark = resolvedAppearance.value === 'dark';

    return `<!doctype html><html><head><meta charset="utf-8"><base target="_blank"><style>
        :root { color-scheme: ${dark ? 'dark' : 'light'}; }
        html, body { margin: 0; padding: 0; }
        body { font: 15px/1.55 'Instrument Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: ${dark ? '#ECE9E4' : '#2B2825'}; background: transparent; word-wrap: break-word; overflow-wrap: anywhere; }
        img { max-width: 100% !important; height: auto; }
        table { max-width: 100%; }
        a { color: ${dark ? '#E39BD6' : '#8A2C7A'}; }
        blockquote { border-left: 2px solid ${dark ? '#444' : '#ddd'}; margin: 8px 0; padding-left: 12px; color: ${dark ? '#B5B0A9' : '#6B6660'}; }
        pre.plain { white-space: pre-wrap; font: inherit; margin: 0; }
        .tandem-quote, .gmail_quote, .yahoo_quoted, blockquote[type=cite] { opacity: .85; }
    </style></head><body>${body.value}</body></html>`;
});

const measure = () => {
    const doc = frame.value?.contentDocument;

    if (!doc?.body) {
        return;
    }

    height.value = Math.min(Math.max(doc.documentElement.scrollHeight, doc.body.scrollHeight, 40) + 8, 20000);
};

const attach = () => {
    const doc = frame.value?.contentDocument;

    observer?.disconnect();

    if (!doc?.body) {
        return;
    }

    measure();
    observer = new ResizeObserver(() => measure());
    observer.observe(doc.body);
    doc.querySelectorAll('img').forEach((img) => img.addEventListener('load', measure));
};

watch(srcdoc, () => setTimeout(attach, 30));
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <iframe
        ref="frame"
        :srcdoc="srcdoc"
        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
        referrerpolicy="no-referrer"
        class="block w-full border-0 bg-transparent"
        :style="{ height: `${height}px` }"
        title="Message body"
        @load="attach"
    />
</template>
