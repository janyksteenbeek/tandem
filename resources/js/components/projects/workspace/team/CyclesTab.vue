<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { CalendarPlus, Clock, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Sparkline from '@/components/projects/charts/Sparkline.vue';
import CarryOverDialog from '@/components/projects/cycles/CarryOverDialog.vue';
import CycleCard from '@/components/projects/cycles/CycleCard.vue';
import CycleDialog from '@/components/projects/dialogs/CycleDialog.vue';
import OverviewSection from '@/components/projects/workspace/OverviewSection.vue';
import { Button } from '@/components/ui/button';
import { api, reportError } from '@/lib/http';
import type { CycleSummary, IssueListItem, Team } from '@/types';

const props = defineProps<{
    team: Team;
    cycles: CycleSummary[] | null | undefined;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(() => `/${slug.value}/teams/${props.team.slug}`);
const apiBase = computed(() => `${base.value}/cycles`);

const list = ref<CycleSummary[]>(props.cycles ?? []);
watch(
    () => props.cycles,
    (value) => (list.value = value ?? []),
);

const active = computed(() => list.value.filter((c) => c.status === 'active'));
const upcoming = computed(() =>
    list.value
        .filter((c) => c.status === 'upcoming')
        .sort((a, b) => a.starts_at.localeCompare(b.starts_at)),
);
const completed = computed(() =>
    list.value
        .filter((c) => c.status === 'completed')
        .sort((a, b) => b.number - a.number),
);
const velocity = computed(() =>
    [...completed.value]
        .reverse()
        .slice(-8)
        .map((c) => c.completed_workstreams_count),
);
const latest = computed(
    () =>
        [...list.value].sort((a, b) => b.ends_at.localeCompare(a.ends_at))[0] ??
        null,
);

const createOpen = ref(false);
const completing = ref<CycleSummary | null>(null);
const openIssues = ref<IssueListItem[]>([]);
const busy = ref(false);

const replace = (cycle: CycleSummary) => {
    const index = list.value.findIndex((c) => c.uuid === cycle.uuid);

    if (index === -1) {
        list.value = [cycle, ...list.value];
    } else {
        list.value.splice(index, 1, cycle);
    }
};

const refresh = () =>
    router.reload({ only: ['cycles', 'team', 'projectsNav'] });

const start = async (cycle: CycleSummary) => {
    try {
        const data = await api.post<{ cycle: CycleSummary }>(
            `${apiBase.value}/${cycle.uuid}/start`,
        );
        replace(data.cycle);
        toast.success(`${data.cycle.name} started`);
        refresh();
    } catch (error) {
        reportError(error);
    }
};

const askComplete = async (cycle: CycleSummary) => {
    completing.value = cycle;
    openIssues.value = [];

    try {
        const data = await api.get<{ issues: IssueListItem[] }>(
            `/${slug.value}/issues/search`,
            {
                q: '',
                limit: 25,
                cycle: cycle.id,
            },
        );
        openIssues.value = data.issues.filter(
            (i) =>
                i.workstream?.cycle?.id === cycle.id &&
                !['completed', 'canceled'].includes(
                    i.workstream.status.category,
                ),
        );
    } catch {
        openIssues.value = [];
    }
};

const complete = async (carryOver: boolean) => {
    if (!completing.value) {
        return;
    }

    busy.value = true;

    try {
        const data = await api.post<{ cycle: CycleSummary }>(
            `${apiBase.value}/${completing.value.uuid}/complete`,
            { carry_over: carryOver },
        );
        replace(data.cycle);
        toast.success(
            `${data.cycle.name} completed${data.cycle.carried_over_count ? ` · ${data.cycle.carried_over_count} carried over` : ''}`,
        );
        completing.value = null;
        refresh();
    } catch (error) {
        reportError(error);
    } finally {
        busy.value = false;
    }
};

const schedule = async () => {
    try {
        const data = await api.post<{ cycles: CycleSummary[] }>(
            `${apiBase.value}/schedule`,
        );
        data.cycles.forEach(replace);
        toast.success(
            data.cycles.length
                ? `${data.cycles.length} upcoming cycle${data.cycles.length === 1 ? '' : 's'} scheduled`
                : 'Upcoming cycles are already scheduled',
        );
        refresh();
    } catch (error) {
        reportError(error);
    }
};
</script>

<template>
    <div class="max-w-3xl space-y-8">
        <div v-if="cycles === undefined || cycles === null" class="space-y-2">
            <div v-for="i in 3" :key="i" class="h-14 shimmer rounded-lg" />
        </div>

        <div
            v-else-if="!team.cycle_settings.enabled && list.length === 0"
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
            <div class="mt-4 flex items-center gap-3">
                <Link
                    v-if="team.can.lead"
                    :href="`${base}/settings`"
                    class="text-sm font-medium text-brand hover:underline"
                    >Enable cycles in settings</Link
                >
                <Button
                    v-if="team.can.lead"
                    variant="outline"
                    size="sm"
                    @click="createOpen = true"
                    >Create one manually</Button
                >
            </div>
        </div>

        <template v-else>
            <div
                v-if="team.can.lead"
                class="flex items-center justify-end gap-2"
            >
                <Button
                    v-if="team.cycle_settings.enabled"
                    variant="ghost"
                    size="sm"
                    class="h-8 gap-1.5"
                    @click="schedule"
                    ><CalendarPlus class="size-3.5" /> Schedule upcoming</Button
                >
                <Button size="sm" class="h-8 gap-1.5" @click="createOpen = true"
                    ><Plus class="size-3.5" /> New cycle</Button
                >
            </div>

            <OverviewSection label="Active">
                <div
                    v-if="active.length === 0"
                    class="rounded-lg border border-dashed px-3 py-5 text-center text-[12.5px] text-muted-foreground"
                >
                    No cycle is running.
                    {{
                        upcoming.length
                            ? 'Start the next one when you are ready.'
                            : 'Schedule one to begin.'
                    }}
                </div>
                <div v-else class="space-y-2">
                    <CycleCard
                        v-for="cycle in active"
                        :key="cycle.uuid"
                        :cycle="cycle"
                        :team-slug="team.slug"
                        :can-lead="team.can.lead"
                        active
                        @complete="askComplete"
                    />
                </div>
            </OverviewSection>

            <OverviewSection
                label="Upcoming"
                :hint="upcoming.length ? `${upcoming.length}` : null"
            >
                <div
                    v-if="upcoming.length === 0"
                    class="rounded-lg border border-dashed px-3 py-5 text-center text-[12.5px] text-muted-foreground"
                >
                    Nothing scheduled yet.
                </div>
                <div v-else class="space-y-2">
                    <CycleCard
                        v-for="cycle in upcoming"
                        :key="cycle.uuid"
                        :cycle="cycle"
                        :team-slug="team.slug"
                        :can-lead="team.can.lead"
                        @start="start"
                    />
                </div>
            </OverviewSection>

            <OverviewSection
                label="Completed"
                :hint="completed.length ? `${completed.length}` : null"
            >
                <template v-if="velocity.length > 1" #action>
                    <span
                        class="flex items-center gap-2 text-[11px] text-muted-foreground"
                        ><Sparkline :values="velocity" tone="success" />
                        velocity</span
                    >
                </template>
                <div
                    v-if="completed.length === 0"
                    class="rounded-lg border border-dashed px-3 py-5 text-center text-[12.5px] text-muted-foreground"
                >
                    No completed cycles yet.
                </div>
                <div v-else class="space-y-2">
                    <CycleCard
                        v-for="cycle in completed"
                        :key="cycle.uuid"
                        :cycle="cycle"
                        :team-slug="team.slug"
                    />
                </div>
            </OverviewSection>
        </template>

        <CycleDialog
            v-model:open="createOpen"
            :team-slug="team.slug"
            :cycle-settings="team.cycle_settings"
            :last-cycle="latest"
            @saved="
                (c) => {
                    replace(c);
                    refresh();
                }
            "
        />
        <CarryOverDialog
            v-if="completing"
            :open="completing !== null"
            :cycle="completing"
            :open_issues="openIssues"
            :team-id="team.id"
            :default-carry-over="team.cycle_settings.auto_carry_over"
            :busy="busy"
            @update:open="(v) => !v && (completing = null)"
            @confirm="complete"
        />
    </div>
</template>
