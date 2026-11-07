<script setup lang="ts">
import { computed } from 'vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import StatPill from '@/components/projects/workspace/StatPill.vue';
import { formatEstimate } from '@/lib/projects/meta';

const props = defineProps<{
    summary: {
        total: number;
        completed: number;
        started: number;
        canceled: number;
        remaining: number;
        points_total: number;
        points_completed: number;
        days_total: number;
        days_left: number;
    };
    status: 'upcoming' | 'active' | 'completed';
}>();

const progress = computed(() => {
    const counted = props.summary.total - props.summary.canceled;

    return counted > 0 ? props.summary.completed / counted : 0;
});

const elapsed = computed(() => {
    if (props.status === 'completed') {
        return 1;
    }

    if (props.status === 'upcoming') {
        return 0;
    }

    return Math.min(
        1,
        Math.max(
            0,
            1 - props.summary.days_left / Math.max(props.summary.days_total, 1),
        ),
    );
});
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-x-5 gap-y-2 rounded-lg border bg-surface px-4 py-2.5"
    >
        <span class="flex items-center gap-2 text-[12.5px]">
            <ProgressRing
                :value="progress"
                :size="20"
                :tone="status === 'completed' ? 'success' : 'brand'"
            />
            <span class="font-semibold tabular-nums"
                >{{ Math.round(progress * 100) }}%</span
            >
            <span class="text-muted-foreground">done</span>
        </span>
        <StatPill
            :items="[
                { label: 'done', value: summary.completed, tone: 'success' },
                {
                    label: 'in progress',
                    value: summary.started,
                    tone: 'warning',
                },
                { label: 'remaining', value: summary.remaining },
                ...(summary.canceled > 0
                    ? [{ label: 'canceled', value: summary.canceled }]
                    : []),
            ]"
        />
        <span
            v-if="summary.points_total > 0"
            class="text-[12.5px] text-muted-foreground"
        >
            <span class="font-semibold text-foreground tabular-nums">{{
                formatEstimate(summary.points_completed)
            }}</span>
            of
            <span class="tabular-nums">{{
                formatEstimate(summary.points_total)
            }}</span>
            points
        </span>
        <span
            class="ml-auto flex items-center gap-2 text-[12px] text-muted-foreground"
        >
            <span
                class="h-1.5 w-24 overflow-hidden rounded-full bg-muted"
                role="img"
                :aria-label="`${Math.round(elapsed * 100)}% of the time elapsed`"
            >
                <span
                    class="block h-full rounded-full bg-highlight transition-[width]"
                    :style="{ width: `${elapsed * 100}%` }"
                />
            </span>
            <template v-if="status === 'active'"
                >{{ summary.days_left }} of {{ summary.days_total }} days
                left</template
            >
            <template v-else-if="status === 'completed'"
                >{{ summary.days_total }} days</template
            >
            <template v-else
                >Starts in
                {{ summary.days_left - summary.days_total }} days</template
            >
        </span>
    </div>
</template>
