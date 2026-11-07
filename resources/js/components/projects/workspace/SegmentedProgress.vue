<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

/** One bar, five segments: completed, started, unstarted, backlog, canceled. */
const props = withDefaults(
    defineProps<{
        completed: number;
        started: number;
        unstarted: number;
        backlog: number;
        canceled?: number;
        legend?: boolean;
        class?: string;
    }>(),
    { canceled: 0, legend: true },
);

const segments = computed(() => {
    const total =
        props.completed +
        props.started +
        props.unstarted +
        props.backlog +
        props.canceled;
    const pct = (n: number) => (total > 0 ? (n / total) * 100 : 0);

    return [
        {
            key: 'completed',
            label: 'Done',
            value: props.completed,
            width: pct(props.completed),
            className: 'bg-status-completed',
        },
        {
            key: 'started',
            label: 'In progress',
            value: props.started,
            width: pct(props.started),
            className: 'bg-status-started',
        },
        {
            key: 'unstarted',
            label: 'Todo',
            value: props.unstarted,
            width: pct(props.unstarted),
            className: 'bg-status-unstarted/60',
        },
        {
            key: 'backlog',
            label: 'Backlog',
            value: props.backlog,
            width: pct(props.backlog),
            className: 'bg-status-backlog/50',
        },
        {
            key: 'canceled',
            label: 'Canceled',
            value: props.canceled,
            width: pct(props.canceled),
            className: 'bg-status-canceled/40',
        },
    ];
});
</script>

<template>
    <div :class="cn('space-y-1.5', $props.class)">
        <div
            class="flex h-2 w-full overflow-hidden rounded-full bg-muted"
            role="img"
            aria-label="Progress by status"
        >
            <div
                v-for="segment in segments"
                :key="segment.key"
                :class="
                    cn(
                        'h-full transition-[width] duration-300',
                        segment.className,
                    )
                "
                :style="{ width: `${segment.width}%` }"
            />
        </div>
        <div
            v-if="legend"
            class="flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-muted-foreground"
        >
            <span
                v-for="segment in segments.filter((s) => s.value > 0)"
                :key="segment.key"
                class="flex items-center gap-1"
            >
                <span :class="cn('size-2 rounded-full', segment.className)" />
                {{ segment.value }} {{ segment.label.toLowerCase() }}
            </span>
        </div>
    </div>
</template>
