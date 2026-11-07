<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Clock } from '@lucide/vue';
import { computed } from 'vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import { cn } from '@/lib/utils';
import type { CycleSummary, Team } from '@/types';

const props = defineProps<{
    team: Team;
    cycles: CycleSummary[] | null | undefined;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(() => `/${slug.value}/teams/${props.team.slug}`);

const STATUS: Record<CycleSummary['status'], string> = {
    upcoming: 'bg-muted text-muted-foreground',
    active: 'bg-brand-soft text-brand-soft-foreground',
    completed: 'bg-success/12 text-success',
};
</script>

<template>
    <div class="max-w-3xl">
        <div v-if="cycles === undefined || cycles === null" class="space-y-2">
            <div v-for="i in 3" :key="i" class="h-10 shimmer rounded-lg" />
        </div>
        <div
            v-else-if="!team.cycle_settings.enabled && cycles.length === 0"
            class="flex flex-col items-center rounded-2xl border border-dashed bg-surface px-6 py-10 text-center"
        >
            <span
                class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                ><Clock class="size-5"
            /></span>
            <p class="font-display text-2xl">Cycles are off.</p>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Time-boxed planning for {{ team.name }}. Each team's cycles are
                its own, even on shared issues.
            </p>
            <Link
                v-if="team.can.lead"
                :href="`${base}/settings`"
                class="mt-4 text-sm font-medium text-brand hover:underline"
                >Enable cycles in settings</Link
            >
        </div>
        <div
            v-else-if="cycles.length === 0"
            class="rounded-lg border border-dashed px-3 py-6 text-center text-[12.5px] text-muted-foreground"
        >
            No cycles yet. The scheduler creates upcoming cycles automatically.
        </div>
        <ul v-else class="space-y-px">
            <li v-for="cycle in cycles" :key="cycle.uuid">
                <Link
                    :href="`${base}/cycles/${cycle.uuid}`"
                    class="-mx-2 issue-row h-11"
                >
                    <ProgressRing
                        :value="cycle.progress"
                        :size="18"
                        :tone="
                            cycle.status === 'completed' ? 'success' : 'brand'
                        "
                    />
                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate text-[13.5px] font-medium"
                            >{{ cycle.name }}</span
                        >
                        <span class="block text-[11.5px] text-muted-foreground"
                            >{{ cycle.starts_at }} → {{ cycle.ends_at
                            }}<template v-if="cycle.goals">
                                · {{ cycle.goals }}</template
                            ></span
                        >
                    </span>
                    <span
                        class="text-[11.5px] text-muted-foreground tabular-nums"
                        >{{ cycle.completed_workstreams_count }}/{{
                            cycle.workstreams_count
                        }}</span
                    >
                    <span
                        :class="
                            cn(
                                'rounded-full px-2 py-0.5 text-[11px] font-medium capitalize',
                                STATUS[cycle.status],
                            )
                        "
                        >{{ cycle.status }}</span
                    >
                </Link>
            </li>
        </ul>
    </div>
</template>
