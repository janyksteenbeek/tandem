<script setup lang="ts">
import { computed } from 'vue';
import { EMPTY_COPY } from '@/lib/mail';

const props = defineProps<{
    view: string;
    title?: string;
    body?: string;
    compact?: boolean;
}>();

const copy = computed(() => ({
    title: props.title ?? EMPTY_COPY[props.view]?.title ?? 'Nothing here.',
    body: props.body ?? EMPTY_COPY[props.view]?.body ?? '',
}));
</script>

<template>
    <div
        class="flex h-full flex-col items-center justify-center px-8 text-center"
        :class="compact ? 'py-10' : 'py-16'"
    >
        <svg
            width="120"
            height="88"
            viewBox="0 0 120 88"
            fill="none"
            class="mb-5 animate-rise"
            aria-hidden="true"
        >
            <path
                d="M12 30 60 8l48 22v36a6 6 0 0 1-6 6H18a6 6 0 0 1-6-6V30Z"
                class="fill-brand-soft stroke-brand/40"
                stroke-width="2"
            />
            <path
                d="M12 30l48 28 48-28"
                class="stroke-brand/60"
                stroke-width="2"
                stroke-linejoin="round"
            />
            <path
                d="M12 66l30-24M108 66 78 42"
                class="stroke-brand/40"
                stroke-width="2"
            />
            <circle cx="96" cy="18" r="6" class="fill-highlight" />
            <path
                d="M93 18.5l2 2 4-4.5"
                class="stroke-highlight-foreground"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
        <p class="font-display text-2xl text-foreground">{{ copy.title }}</p>
        <p v-if="copy.body" class="mt-1 max-w-xs text-sm text-muted-foreground">
            {{ copy.body }}
        </p>
        <slot />
    </div>
</template>
