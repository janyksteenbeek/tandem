<script setup lang="ts">
import { useBranding } from '@/composables/useBranding';

withDefaults(defineProps<{ size?: number }>(), { size: 28 });

const branding = useBranding();
</script>

<template>
    <template v-if="branding.logo">
        <img
            :src="branding.logo"
            :width="size"
            :height="size"
            :alt="branding.name"
            :class="{ 'dark:hidden': branding.logo_dark }"
            class="shrink-0 rounded-lg object-contain"
            :style="{ width: `${size}px`, height: `${size}px` }"
        />
        <img
            v-if="branding.logo_dark"
            :src="branding.logo_dark"
            :width="size"
            :height="size"
            :alt="branding.name"
            class="hidden shrink-0 rounded-lg object-contain dark:block"
            :style="{ width: `${size}px`, height: `${size}px` }"
        />
    </template>
    <svg
        v-else
        :width="size"
        :height="size"
        viewBox="0 0 32 32"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        aria-hidden="true"
    >
        <rect width="32" height="32" rx="9" class="fill-brand" />
        <path
            d="M7.5 11.5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2v-9Z"
            class="fill-brand-foreground"
            opacity="0.18"
        />
        <path
            d="M8 11l8 6 8-6"
            class="stroke-brand-foreground"
            stroke-width="2.2"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <path
            d="M8 20.5l5.5-4.5M24 20.5l-5.5-4.5"
            class="stroke-brand-foreground"
            stroke-width="2.2"
            stroke-linecap="round"
            opacity="0.7"
        />
        <circle cx="24.5" cy="9.5" r="3" class="fill-highlight" />
    </svg>
</template>
