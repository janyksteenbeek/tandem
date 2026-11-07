<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    MoreHorizontal,
    Play,
    Plus,
    Square,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import IssueWorkspaceBody from '@/components/projects/IssueWorkspaceBody.vue';
import Burndown from '@/components/projects/charts/Burndown.vue';
import VelocityBars from '@/components/projects/charts/VelocityBars.vue';
import AddToCycleSheet from '@/components/projects/cycles/AddToCycleSheet.vue';
import CarryOverDialog from '@/components/projects/cycles/CarryOverDialog.vue';
import CycleProgressStrip from '@/components/projects/cycles/CycleProgressStrip.vue';
import {
    CYCLE_STATUS,
    cycleDates,
    daysLeft,
} from '@/components/projects/cycles/cycleMeta';
import ConfirmDialog from '@/components/projects/dialogs/ConfirmDialog.vue';
import CycleDialog from '@/components/projects/dialogs/CycleDialog.vue';
import Breadcrumbs from '@/components/projects/workspace/Breadcrumbs.vue';
import OverviewSection from '@/components/projects/workspace/OverviewSection.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Textarea } from '@/components/ui/textarea';
import { useIssueActions } from '@/composables/projects/useIssueActions';
import { useIssueLookups } from '@/composables/projects/useIssueLookups';
import { api, reportError } from '@/lib/http';
import { cn } from '@/lib/utils';
import type {
    BoardColumn,
    CursorPage,
    CycleSummary,
    IssueDisplay,
    IssueFilters,
    IssueListItem,
    IssueLookups,
    TeamSummary,
    Workflow,
} from '@/types';

type Summary = {
    total: number;
    completed: number;
    started: number;
    canceled: number;
    remaining: number;
    points_total: number;
    points_completed: number;
    days_total: number;
    days_left: number;
    burndown: { date: string; scope: number; remaining: number }[];
};

type Velocity = {
    cycle: { id: number; uuid: string; number: number; name: string };
    committed: number;
    completed: number;
    points: number;
};

const props = defineProps<{
    team: TeamSummary & { can: { lead: boolean } };
    cycle: CycleSummary;
    summary: Summary;
    velocity: Velocity[];
    siblings: CycleSummary[];
    workflow: Workflow;
    lookups: IssueLookups;
    filters: IssueFilters;
    display: IssueDisplay;
    issues: CursorPage<IssueListItem>;
    board?: BoardColumn[] | null;
    backlog?: IssueListItem[] | null;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const teamBase = computed(() => `/${slug.value}/teams/${props.team.slug}`);
const apiBase = computed(() => `${teamBase.value}/cycles/${props.cycle.uuid}`);
const { lookups } = useIssueLookups(() => props.lookups);
const actions = useIssueActions();

const cycle = ref<CycleSummary>(props.cycle);
watch(
    () => props.cycle,
    (value) => (cycle.value = value),
);

const left = computed(() => daysLeft(cycle.value));
const ordered = computed(() =>
    [...props.siblings].sort((a, b) => a.number - b.number),
);
const index = computed(() =>
    ordered.value.findIndex((c) => c.uuid === cycle.value.uuid),
);
const previous = computed(() =>
    index.value > 0 ? ordered.value[index.value - 1] : null,
);
const next = computed(() =>
    index.value >= 0 && index.value < ordered.value.length - 1
        ? ordered.value[index.value + 1]
        : null,
);
const cycleUrl = (c: CycleSummary) => `${teamBase.value}/cycles/${c.uuid}`;

const goals = ref(cycle.value.goals ?? '');
const editingGoals = ref(false);
const saveGoals = async () => {
    editingGoals.value = false;

    if ((goals.value.trim() || null) === (cycle.value.goals ?? null)) {
        return;
    }

    try {
        const data = await api.patch<{ cycle: CycleSummary }>(apiBase.value, {
            goals: goals.value.trim() || null,
        });
        cycle.value = data.cycle;
    } catch (error) {
        reportError(error);
    }
};

const editOpen = ref(false);
const deleteOpen = ref(false);
const completeOpen = ref(false);
const sheetOpen = ref(false);
const busy = ref(false);
const adding = ref(new Set<string>());

const reload = () =>
    router.reload({
        only: [
            'cycle',
            'summary',
            'velocity',
            'siblings',
            'issues',
            'board',
            'projectsNav',
        ],
    });

const start = async () => {
    try {
        const data = await api.post<{ cycle: CycleSummary }>(
            `${apiBase.value}/start`,
        );
        cycle.value = data.cycle;
        toast.success(`${data.cycle.name} started`);
        reload();
    } catch (error) {
        reportError(error);
    }
};

const openIssues = computed(() =>
    props.issues.data.filter(
        (i) =>
            i.workstream &&
            !['completed', 'canceled'].includes(i.workstream.status.category),
    ),
);

const complete = async (carryOver: boolean) => {
    busy.value = true;

    try {
        const data = await api.post<{ cycle: CycleSummary }>(
            `${apiBase.value}/complete`,
            { carry_over: carryOver },
        );
        cycle.value = data.cycle;
        completeOpen.value = false;
        toast.success(
            `${data.cycle.name} completed${data.cycle.carried_over_count ? ` · ${data.cycle.carried_over_count} carried over` : ''}`,
        );
        reload();
    } catch (error) {
        reportError(error);
    } finally {
        busy.value = false;
    }
};

const destroy = async () => {
    busy.value = true;

    try {
        await api.delete(apiBase.value);
        toast.success('Cycle deleted');
        router.visit(`${teamBase.value}/cycles`);
    } catch (error) {
        reportError(error);
        busy.value = false;
    }
};

const openSheet = () => {
    sheetOpen.value = true;

    if (props.backlog === undefined || props.backlog === null) {
        router.reload({ only: ['backlog'] });
    }
};

const addToCycle = async (issue: IssueListItem) => {
    if (!issue.workstream) {
        return;
    }

    adding.value = new Set([...adding.value, issue.uuid]);
    const result = await actions.setWorkstreamCycle(
        issue,
        issue.workstream,
        cycle.value.id,
    );
    adding.value = new Set([...adding.value].filter((u) => u !== issue.uuid));

    if (result) {
        toast.success(`${issue.identifier} added to ${cycle.value.name}`);
        router.reload({
            only: ['issues', 'board', 'summary', 'cycle', 'backlog'],
        });
    }
};

const onCycleSaved = (saved: CycleSummary) => {
    cycle.value = saved;
    reload();
};

const container = computed(() => ({
    type: 'cycle' as const,
    id: cycle.value.id,
    name: cycle.value.name,
    url: apiBase.value,
}));
</script>

<template>
    <Head :title="`${cycle.name} · ${team.name}`" />

    <div class="flex h-full min-h-0 flex-col">
        <header
            class="shrink-0 border-b bg-background/95 px-4 pt-3 pb-3 backdrop-blur sm:px-6"
        >
            <Breadcrumbs
                :items="[
                    { label: 'Teams', href: `/${slug}/teams` },
                    { label: team.name, href: teamBase },
                    { label: 'Cycles', href: `${teamBase}/cycles` },
                    { label: cycle.name },
                ]"
                class="pl-9 md:pl-0"
            />
            <div
                class="mt-2 flex flex-wrap items-start justify-between gap-x-4 gap-y-2"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h1
                            class="truncate font-display text-xl leading-tight font-semibold tracking-[-0.01em]"
                        >
                            {{ cycle.name }}
                        </h1>
                        <span
                            :class="
                                cn(
                                    'rounded-full px-2 py-0.5 text-[11px] font-medium',
                                    CYCLE_STATUS[cycle.status].className,
                                )
                            "
                            >{{ CYCLE_STATUS[cycle.status].label }}</span
                        >
                    </div>
                    <p class="text-[12px] text-muted-foreground">
                        {{ cycleDates(cycle) }}
                        <template v-if="cycle.status === 'active'">
                            · {{ left }} day{{
                                left === 1 ? '' : 's'
                            }}
                            left</template
                        >
                    </p>
                </div>

                <div class="flex items-center gap-1.5">
                    <div class="mr-2 flex items-center rounded-md border">
                        <Link
                            v-if="previous"
                            :href="cycleUrl(previous)"
                            class="flex size-7 items-center justify-center text-muted-foreground hover:bg-accent hover:text-foreground"
                            :aria-label="`Previous: ${previous.name}`"
                            ><ChevronLeft class="size-3.5"
                        /></Link>
                        <span
                            v-else
                            class="flex size-7 items-center justify-center text-muted-foreground/40"
                            ><ChevronLeft class="size-3.5"
                        /></span>
                        <Popover>
                            <PopoverTrigger as-child>
                                <button
                                    type="button"
                                    class="h-7 border-x px-2 text-[11.5px] text-muted-foreground tabular-nums hover:bg-accent hover:text-foreground"
                                >
                                    #{{ cycle.number }}
                                </button>
                            </PopoverTrigger>
                            <PopoverContent class="w-56 p-1" align="end">
                                <Link
                                    v-for="c in [...ordered].reverse()"
                                    :key="c.uuid"
                                    :href="cycleUrl(c)"
                                    :class="
                                        cn(
                                            'flex items-center gap-2 rounded-md px-2 py-1.5 text-[12.5px] hover:bg-accent',
                                            c.uuid === cycle.uuid &&
                                                'bg-brand-soft/60 text-brand-soft-foreground',
                                        )
                                    "
                                >
                                    <span class="truncate">{{ c.name }}</span>
                                    <span
                                        class="ml-auto text-[11px] text-muted-foreground"
                                        >{{
                                            CYCLE_STATUS[c.status].label
                                        }}</span
                                    >
                                </Link>
                            </PopoverContent>
                        </Popover>
                        <Link
                            v-if="next"
                            :href="cycleUrl(next)"
                            class="flex size-7 items-center justify-center text-muted-foreground hover:bg-accent hover:text-foreground"
                            :aria-label="`Next: ${next.name}`"
                            ><ChevronRight class="size-3.5"
                        /></Link>
                        <span
                            v-else
                            class="flex size-7 items-center justify-center text-muted-foreground/40"
                            ><ChevronRight class="size-3.5"
                        /></span>
                    </div>

                    <template v-if="team.can.lead">
                        <Button
                            v-if="cycle.status === 'upcoming'"
                            size="sm"
                            class="h-8 gap-1.5"
                            @click="start"
                            ><Play class="size-3.5" /> Start cycle</Button
                        >
                        <Button
                            v-else-if="cycle.status === 'active'"
                            size="sm"
                            variant="outline"
                            class="h-8 gap-1.5"
                            @click="completeOpen = true"
                            ><Square class="size-3.5" /> Complete</Button
                        >
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    aria-label="Cycle actions"
                                    ><MoreHorizontal class="size-4"
                                /></Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-44">
                                <DropdownMenuItem @click="editOpen = true"
                                    >Edit cycle</DropdownMenuItem
                                >
                                <DropdownMenuItem @click="openSheet"
                                    >Add issues</DropdownMenuItem
                                >
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    class="text-destructive"
                                    :disabled="cycle.status === 'active'"
                                    @click="deleteOpen = true"
                                    >Delete</DropdownMenuItem
                                >
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>
                </div>
            </div>

            <div class="mt-3 space-y-3">
                <div v-if="team.can.lead || cycle.goals" class="text-[13px]">
                    <Textarea
                        v-if="editingGoals"
                        v-model="goals"
                        v-focus
                        rows="2"
                        class="text-[13px]"
                        placeholder="What should be true when this cycle ends?"
                        @blur="saveGoals"
                        @keydown.esc="editingGoals = false"
                        @keydown.meta.enter="saveGoals"
                    />
                    <button
                        v-else
                        type="button"
                        :disabled="!team.can.lead"
                        :class="
                            cn(
                                '-mx-1.5 max-w-3xl rounded-md px-1.5 py-0.5 text-left',
                                team.can.lead && 'hover:bg-accent',
                                !cycle.goals && 'text-muted-foreground',
                            )
                        "
                        @click="
                            goals = cycle.goals ?? '';
                            editingGoals = true;
                        "
                    >
                        {{ cycle.goals || 'Add cycle goals…' }}
                    </button>
                </div>
                <CycleProgressStrip :summary="summary" :status="cycle.status" />
            </div>
        </header>

        <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
            <div
                class="grid gap-6 px-4 py-5 sm:px-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
            >
                <OverviewSection
                    label="Burndown"
                    :hint="
                        summary.burndown.length
                            ? `${summary.burndown.length} day${summary.burndown.length === 1 ? '' : 's'} recorded`
                            : null
                    "
                >
                    <div class="rounded-lg border bg-card p-3 shadow-pane">
                        <Burndown
                            v-if="
                                summary.burndown.length > 0 ||
                                cycle.status !== 'upcoming'
                            "
                            :series="summary.burndown"
                            :days-total="summary.days_total"
                            :starts-at="cycle.starts_at"
                            :ends-at="cycle.ends_at"
                        />
                        <p
                            v-else
                            class="py-10 text-center text-[12.5px] text-muted-foreground"
                        >
                            The burndown starts drawing once the cycle is
                            active.
                        </p>
                    </div>
                </OverviewSection>
                <OverviewSection
                    label="Velocity"
                    :hint="velocity.length ? `last ${velocity.length}` : null"
                >
                    <div class="rounded-lg border bg-card p-3 shadow-pane">
                        <VelocityBars
                            v-if="velocity.length > 0"
                            :cycles="velocity"
                        />
                        <p
                            v-else
                            class="py-10 text-center text-[12.5px] text-muted-foreground"
                        >
                            Velocity appears after the first completed cycle.
                        </p>
                    </div>
                </OverviewSection>
            </div>

            <div class="min-h-[28rem] border-t">
                <IssueWorkspaceBody
                    :container="container"
                    :filters="filters"
                    :display="display"
                    :issues="issues"
                    :board="board"
                    :workflow="workflow.statuses"
                    :lookups="lookups"
                    :focus-team-id="team.id"
                    :channels="[`team.${team.id}`]"
                    :base-url="apiBase"
                    :allow-modes="['list', 'board']"
                    empty-key="team"
                    empty-title="Nothing planned in this cycle yet."
                    empty-body="Add issues from the team backlog to get going."
                    :prefill="{ team_ids: [team.id], lead_team_id: team.id }"
                >
                    <template #header>
                        <Button
                            v-if="team.can.lead || cycle.status !== 'completed'"
                            variant="outline"
                            size="sm"
                            class="h-8 gap-1.5"
                            @click="openSheet"
                            ><Plus class="size-3.5" /> Add issues</Button
                        >
                    </template>
                </IssueWorkspaceBody>
            </div>
        </div>

        <CycleDialog
            v-model:open="editOpen"
            :team-slug="team.slug"
            :cycle="cycle"
            @saved="onCycleSaved"
        />
        <ConfirmDialog
            v-model:open="deleteOpen"
            title="Delete this cycle?"
            description="Planned workstreams go back to the backlog. This cannot be undone."
            confirm-label="Delete cycle"
            destructive
            :busy="busy"
            @confirm="destroy"
        />
        <CarryOverDialog
            v-model:open="completeOpen"
            :cycle="cycle"
            :open_issues="openIssues"
            :team-id="team.id"
            :default-carry-over="true"
            :busy="busy"
            @confirm="complete"
        />
        <AddToCycleSheet
            v-model:open="sheetOpen"
            :backlog="backlog"
            :team-name="team.name"
            :adding="adding"
            @add="addToCycle"
        />
    </div>
</template>
