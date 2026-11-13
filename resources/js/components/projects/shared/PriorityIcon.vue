<script setup lang="ts">
import { PRIORITY_META } from '@/lib/projects/meta';
import { cn } from '@/lib/utils';
import type { IssuePriority } from '@/types';

/** Signal bars for low/medium/high, a filled badge for urgent, dashes for none. */
withDefaults(
    defineProps<{ priority: IssuePriority; size?: number; class?: string }>(),
    { size: 14 },
);

const bars = (priority: IssuePriority) =>
    ({ none: 0, low: 1, medium: 2, high: 3, urgent: 3 })[priority];
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 16 16"
        :class="cn('shrink-0', PRIORITY_META[priority].className, $props.class)"
        :aria-label="PRIORITY_META[priority].label"
        role="img"
    >
        <template v-if="priority === 'urgent'">
            <rect
                x="1"
                y="1"
                width="14"
                height="14"
                rx="3"
                fill="currentColor"
            />
            <path
                d="M8 4.2v4.6"
                stroke="var(--background)"
                stroke-width="1.8"
                stroke-linecap="round"
            />
            <circle cx="8" cy="11.6" r="1" fill="var(--background)" />
        </template>
        <template v-else-if="priority === 'none'">
            <rect
                x="2"
                y="7.25"
                width="3"
                height="1.5"
                rx="0.75"
                fill="currentColor"
                opacity="0.6"
            />
            <rect
                x="6.5"
                y="7.25"
                width="3"
                height="1.5"
                rx="0.75"
                fill="currentColor"
                opacity="0.6"
            />
            <rect
                x="11"
                y="7.25"
                width="3"
                height="1.5"
                rx="0.75"
                fill="currentColor"
                opacity="0.6"
            />
        </template>
        <template v-else>
            <rect
                x="2"
                y="9"
                width="3"
                height="5"
                rx="1"
                fill="currentColor"
                :opacity="bars(priority) >= 1 ? 1 : 0.25"
            />
            <rect
                x="6.5"
                y="6"
                width="3"
                height="8"
                rx="1"
                fill="currentColor"
                :opacity="bars(priority) >= 2 ? 1 : 0.25"
            />
            <rect
                x="11"
                y="2.5"
                width="3"
                height="11.5"
                rx="1"
                fill="currentColor"
                :opacity="bars(priority) >= 3 ? 1 : 0.25"
            />
        </template>
    </svg>
</template>
