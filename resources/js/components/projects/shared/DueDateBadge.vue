<script setup lang="ts">
import { CalendarClock } from '@lucide/vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { StatusCategory } from '@/types';

const props = defineProps<{
    date: string | null;
    category?: StatusCategory;
    compact?: boolean;
}>();

const today = new Date().toISOString().slice(0, 10);
const terminal = computed(
    () => props.category === 'completed' || props.category === 'canceled',
);
const overdue = computed(
    () => Boolean(props.date) && props.date! < today && !terminal.value,
);
const soon = computed(
    () =>
        Boolean(props.date) &&
        !overdue.value &&
        !terminal.value &&
        props.date! <= addDays(today, 3),
);

const label = computed(() => {
    if (!props.date) {
        return '';
    }

    if (props.date === today) {
        return 'Today';
    }

    if (props.date === addDays(today, 1)) {
        return 'Tomorrow';
    }

    const d = new Date(props.date + 'T00:00:00');
    const sameYear = d.getFullYear() === new Date().getFullYear();

    return d.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        ...(sameYear ? {} : { year: 'numeric' }),
    });
});

function addDays(iso: string, days: number): string {
    const d = new Date(iso + 'T00:00:00');
    d.setDate(d.getDate() + days);

    return d.toISOString().slice(0, 10);
}
</script>

<template>
    <span
        v-if="date"
        :class="
            cn(
                'inline-flex items-center gap-1 text-[11px] tabular-nums',
                overdue
                    ? 'font-medium text-destructive'
                    : soon
                      ? 'text-warning'
                      : 'text-muted-foreground',
            )
        "
        :title="date"
    >
        <CalendarClock v-if="!compact" class="size-3" />
        {{ label }}
    </span>
</template>
