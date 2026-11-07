<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

/** A small ring showing 0..1 progress; used in rows, headers and the sidebar. */
const props = withDefaults(
    defineProps<{
        value: number;
        size?: number;
        stroke?: number;
        tone?: 'brand' | 'success' | 'warning' | 'muted';
        class?: string;
    }>(),
    {
        size: 16,
        stroke: 2.5,
        tone: 'brand',
    },
);

const r = computed(() => (props.size - props.stroke) / 2);
const circumference = computed(() => 2 * Math.PI * r.value);
const offset = computed(
    () => circumference.value * (1 - Math.min(Math.max(props.value, 0), 1)),
);
const color = computed(
    () =>
        ({
            brand: 'var(--brand)',
            success: 'var(--success)',
            warning: 'var(--warning)',
            muted: 'var(--muted-foreground)',
        })[props.tone],
);
</script>

<template>
    <svg
        :width="size"
        :height="size"
        :viewBox="`0 0 ${size} ${size}`"
        :class="cn('shrink-0', $props.class)"
        role="img"
        :aria-label="`${Math.round(value * 100)}% complete`"
    >
        <circle
            :cx="size / 2"
            :cy="size / 2"
            :r="r"
            fill="none"
            stroke="currentColor"
            :stroke-width="stroke"
            class="text-border"
        />
        <circle
            :cx="size / 2"
            :cy="size / 2"
            :r="r"
            fill="none"
            :stroke="color"
            :stroke-width="stroke"
            stroke-linecap="round"
            :stroke-dasharray="circumference"
            :stroke-dashoffset="offset"
            :transform="`rotate(-90 ${size / 2} ${size / 2})`"
            class="transition-[stroke-dashoffset] duration-300"
        />
    </svg>
</template>
