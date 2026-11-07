<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Play, Square } from '@lucide/vue';
import { computed } from 'vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import {
    CYCLE_STATUS,
    cycleDates,
    daysLeft,
} from '@/components/projects/cycles/cycleMeta';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { CycleSummary } from '@/types';

const props = withDefaults(
    defineProps<{
        cycle: CycleSummary;
        teamSlug: string;
        canLead?: boolean;
        active?: boolean;
    }>(),
    { canLead: false, active: false },
);

const emit = defineEmits<{
    start: [cycle: CycleSummary];
    complete: [cycle: CycleSummary];
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const href = computed(
    () => `/${slug.value}/teams/${props.teamSlug}/cycles/${props.cycle.uuid}`,
);
const left = computed(() => daysLeft(props.cycle));
</script>

<template>
    <div
        :class="
            cn(
                'group/cycle flex items-center gap-3 rounded-lg border bg-card px-3 py-2.5 shadow-pane transition-colors hover:bg-accent/40',
                active && 'border-brand/30 bg-brand-soft/20',
            )
        "
    >
        <ProgressRing
            :value="cycle.progress"
            :size="22"
            :tone="cycle.status === 'completed' ? 'success' : 'brand'"
        />
        <Link :href="href" class="min-w-0 flex-1">
            <span class="flex items-center gap-2">
                <span class="truncate text-[13.5px] font-medium">{{
                    cycle.name
                }}</span>
                <span
                    :class="
                        cn(
                            'rounded-full px-1.5 py-px text-[10.5px] font-medium',
                            CYCLE_STATUS[cycle.status].className,
                        )
                    "
                    >{{ CYCLE_STATUS[cycle.status].label }}</span
                >
            </span>
            <span class="block truncate text-[11.5px] text-muted-foreground">
                {{ cycleDates(cycle) }}
                <template v-if="cycle.status === 'active'">
                    · {{ left }} day{{ left === 1 ? '' : 's' }} left</template
                >
                <template v-if="cycle.goals"> · {{ cycle.goals }}</template>
            </span>
        </Link>
        <span class="text-[11.5px] text-muted-foreground tabular-nums">
            {{ cycle.completed_workstreams_count }}/{{
                cycle.workstreams_count
            }}
            <template v-if="cycle.carried_over_count > 0">
                · {{ cycle.carried_over_count }} carried</template
            >
        </span>
        <template v-if="canLead">
            <Button
                v-if="cycle.status === 'upcoming'"
                variant="outline"
                size="sm"
                class="h-7 gap-1 text-xs opacity-0 transition-opacity group-hover/cycle:opacity-100 focus-visible:opacity-100"
                @click.prevent="emit('start', cycle)"
                ><Play class="size-3" /> Start</Button
            >
            <Button
                v-else-if="cycle.status === 'active'"
                variant="outline"
                size="sm"
                class="h-7 gap-1 text-xs"
                @click.prevent="emit('complete', cycle)"
                ><Square class="size-3" /> Complete</Button
            >
        </template>
    </div>
</template>
