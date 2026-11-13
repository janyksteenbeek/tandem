<script setup lang="ts">
import { computed } from 'vue';
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type { StatusCategory, WorkflowStatus } from '@/types';

/**
 * The Linear-style status glyph: an empty ring for backlog/todo, a part-filled ring while
 * started, a filled check for done and a crossed ring for canceled. Colour follows the status.
 */
const props = withDefaults(
    defineProps<{
        status?: Pick<WorkflowStatus, 'category' | 'color'> | null;
        category?: StatusCategory;
        color?: string | null;
        size?: number;
        /** 0..1 fill for started statuses (e.g. progress of sub issues). */
        progress?: number | null;
    }>(),
    { status: null, size: 14, progress: null },
);

const category = computed<StatusCategory>(
    () => props.status?.category ?? props.category ?? 'unstarted',
);
const colorClass = computed(() =>
    chipClass(props.status?.color ?? props.color ?? null),
);
const fill = computed(() =>
    props.progress === null ? 0.4 : Math.min(Math.max(props.progress, 0.08), 1),
);
const r = 6;
const circumference = 2 * Math.PI * r;
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 16 16"
        :class="cn('shrink-0', colorClass)"
        :style="{
            color:
                category === 'backlog'
                    ? 'var(--muted-foreground)'
                    : 'var(--chip)',
        }"
        aria-hidden="true"
    >
        <template v-if="category === 'backlog'">
            <circle
                cx="8"
                cy="8"
                :r="r"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-dasharray="2 2"
                opacity="0.7"
            />
        </template>
        <template v-else-if="category === 'unstarted'">
            <circle
                cx="8"
                cy="8"
                :r="r"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
            />
        </template>
        <template v-else-if="category === 'started'">
            <circle
                cx="8"
                cy="8"
                :r="r"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
            />
            <circle
                cx="8"
                cy="8"
                r="3.5"
                fill="none"
                stroke="currentColor"
                stroke-width="7"
                :stroke-dasharray="`${fill * 2 * Math.PI * 3.5} ${2 * Math.PI * 3.5}`"
                transform="rotate(-90 8 8)"
                opacity="0.95"
            />
        </template>
        <template v-else-if="category === 'completed'">
            <circle cx="8" cy="8" :r="r + 1" fill="currentColor" />
            <path
                d="M5 8.2l2 2 4-4.4"
                fill="none"
                stroke="var(--background)"
                stroke-width="1.7"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </template>
        <template v-else>
            <circle
                cx="8"
                cy="8"
                :r="r"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                opacity="0.8"
            />
            <path
                d="M5.5 5.5l5 5M10.5 5.5l-5 5"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
            />
        </template>
        <circle v-if="false" :r="circumference" />
    </svg>
</template>
