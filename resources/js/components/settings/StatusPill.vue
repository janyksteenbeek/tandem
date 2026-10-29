<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { MailAccountStatus } from '@/types';

const props = withDefaults(
    defineProps<{
        status: MailAccountStatus | string;
        label?: string;
        size?: 'sm' | 'md';
        class?: string;
    }>(),
    { size: 'md' },
);

const META: Record<
    string,
    { label: string; pill: string; dot: string; pulse?: boolean }
> = {
    connected: {
        label: 'Connected',
        pill: 'bg-success/12 text-success',
        dot: 'bg-success',
    },
    syncing: {
        label: 'Syncing',
        pill: 'bg-info/12 text-info',
        dot: 'bg-info',
        pulse: true,
    },
    pending: {
        label: 'Pending',
        pill: 'bg-highlight/20 text-highlight-foreground',
        dot: 'bg-highlight',
        pulse: true,
    },
    error: {
        label: 'Error',
        pill: 'bg-destructive/12 text-destructive',
        dot: 'bg-destructive',
    },
    needs_reauth: {
        label: 'Needs reconnect',
        pill: 'bg-warning/20 text-warning-foreground',
        dot: 'bg-warning',
    },
    disconnected: {
        label: 'Disconnected',
        pill: 'bg-muted text-muted-foreground',
        dot: 'bg-muted-foreground',
    },
    completed: {
        label: 'Completed',
        pill: 'bg-success/12 text-success',
        dot: 'bg-success',
    },
    running: {
        label: 'Running',
        pill: 'bg-info/12 text-info',
        dot: 'bg-info',
        pulse: true,
    },
    failed: {
        label: 'Failed',
        pill: 'bg-destructive/12 text-destructive',
        dot: 'bg-destructive',
    },
};

const meta = computed(
    () =>
        META[props.status] ?? {
            label: props.status,
            pill: 'bg-muted text-muted-foreground',
            dot: 'bg-muted-foreground',
        },
);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex shrink-0 items-center gap-1.5 rounded-full font-medium whitespace-nowrap',
                size === 'sm'
                    ? 'px-2 py-0.5 text-[11px]'
                    : 'px-2.5 py-0.5 text-xs',
                meta.pill,
                props.class,
            )
        "
    >
        <span
            :class="
                cn(
                    'size-1.5 rounded-full',
                    meta.dot,
                    meta.pulse && 'animate-pulse',
                )
            "
        />
        {{ label ?? meta.label }}
    </span>
</template>
