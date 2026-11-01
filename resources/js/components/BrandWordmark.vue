<script setup lang="ts">
import { computed } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import { useBranding } from '@/composables/useBranding';

/**
 * Mark plus name, for headers and the sign-in panel. Uses the configured
 * wordmark image when there is one, otherwise the mark with the name as
 * text. `onDark` picks the light-text variant regardless of the colour
 * scheme, for surfaces that are always dark such as the brand panel.
 */
const props = withDefaults(
    defineProps<{ height?: number; onDark?: boolean }>(),
    { height: 28, onDark: false },
);

const branding = useBranding();

const light = computed(() => branding.wordmark);
const dark = computed(() => branding.wordmark_dark ?? branding.wordmark);
const markSize = computed(() => Math.round(props.height * 1.3));
</script>

<template>
    <span class="inline-flex shrink-0 items-center">
        <template v-if="light">
            <img
                v-if="onDark"
                :src="dark ?? light"
                :height="height"
                :alt="branding.name"
                class="w-auto"
                :style="{ height: `${height}px` }"
            />
            <template v-else>
                <img
                    :src="light"
                    :height="height"
                    :alt="branding.name"
                    :class="{ 'dark:hidden': branding.wordmark_dark }"
                    class="w-auto"
                    :style="{ height: `${height}px` }"
                />
                <img
                    v-if="branding.wordmark_dark"
                    :src="branding.wordmark_dark"
                    :height="height"
                    :alt="branding.name"
                    class="hidden w-auto dark:block"
                    :style="{ height: `${height}px` }"
                />
            </template>
        </template>
        <span v-else class="inline-flex items-center gap-2.5">
            <BrandMark :size="markSize" />
            <span class="font-semibold tracking-tight">{{
                branding.name
            }}</span>
        </span>
    </span>
</template>
