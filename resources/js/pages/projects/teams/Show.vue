<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, defineAsyncComponent, inject, ref, watch } from 'vue';
import IssueWorkspaceBody from '@/components/projects/IssueWorkspaceBody.vue';
import AvatarStack from '@/components/projects/shared/AvatarStack.vue';
import ProjectRows from '@/components/projects/workspace/ProjectRows.vue';
import WorkspaceHeader from '@/components/projects/workspace/WorkspaceHeader.vue';
import { workspaceTabs } from '@/components/projects/workspace/useWorkspaceTabs';
import CyclesTab from '@/components/projects/workspace/team/CyclesTab.vue';
import OverviewTab from '@/components/projects/workspace/team/OverviewTab.vue';
import type { TeamOverview } from '@/components/projects/workspace/team/OverviewTab.vue';
import WorkloadTab from '@/components/projects/workspace/team/WorkloadTab.vue';
import type { WorkloadRow } from '@/components/projects/workspace/team/WorkloadTab.vue';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { useIssueLookups } from '@/composables/projects/useIssueLookups';
import type {
    BoardColumn,
    CreateIssuePrefill,
    CursorPage,
    CycleSummary,
    IssueDisplay,
    IssueFilters,
    IssueListItem,
    IssueLookups,
    ProjectSummary,
    Team,
    Workflow,
} from '@/types';

const SettingsTab = defineAsyncComponent(
    () => import('@/components/projects/workspace/team/SettingsTab.vue'),
);

const props = defineProps<{
    team: Team;
    tab:
        | 'overview'
        | 'issues'
        | 'backlog'
        | 'board'
        | 'cycles'
        | 'workload'
        | 'settings';
    workflow: Workflow;
    lookups: IssueLookups;
    filters: IssueFilters;
    display: IssueDisplay;
    overview?: TeamOverview | null;
    issues?: CursorPage<IssueListItem> | null;
    board?: BoardColumn[] | null;
    cycles?: CycleSummary[] | null;
    workload?: WorkloadRow[] | null;
    projects?: ProjectSummary[] | null;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(() => `/${slug.value}/teams/${props.team.slug}`);
const { lookups } = useIssueLookups(() => props.lookups);
const openCreateIssue = inject<(prefill?: CreateIssuePrefill) => void>(
    'openCreateIssue',
    () => {},
);

const workflow = ref(props.workflow);
watch(
    () => props.workflow,
    (w) => (workflow.value = w),
);

const tabs = computed(() =>
    workspaceTabs(base.value, [
        { key: 'overview', label: 'Overview' },
        { key: 'issues', label: 'Issues', props: ['issues', 'workflow'] },
        { key: 'backlog', label: 'Backlog', props: ['issues', 'workflow'] },
        {
            key: 'board',
            label: 'Board',
            props: ['board', 'workflow'],
            query: '?mode=board',
        },
        {
            key: 'cycles',
            label: 'Cycles',
            count: props.team.active_cycle ? 1 : null,
        },
        { key: 'workload', label: 'Workload' },
        ...(props.team.can.lead
            ? [
                  {
                      key: 'settings',
                      label: 'Settings',
                      props: ['team', 'workflow'],
                  },
              ]
            : []),
    ]),
);

const listTab = computed(() =>
    ['issues', 'backlog', 'board'].includes(props.tab),
);
const container = computed(() => ({
    type: 'team' as const,
    id: props.team.id,
    name: props.team.name,
    url: `${base.value}/${props.tab === 'backlog' ? 'backlog' : 'issues'}`,
}));
const leads = computed(() =>
    props.team.members.filter((m) => m.role === 'lead'),
);
</script>

<template>
    <Head :title="team.name" />

    <div class="flex h-full min-h-0 flex-col">
        <WorkspaceHeader
            :title="team.name"
            :subtitle="team.description"
            :color="team.color"
            :breadcrumbs="[
                { label: 'Teams', href: `/${slug}/teams` },
                { label: team.name },
            ]"
            :tabs="tabs"
            :active-tab="tab"
            favorite-type="team"
            :favorite-id="team.id"
        >
            <template #meta>
                <span
                    class="flex items-center gap-1.5 text-[12px] text-muted-foreground"
                    ><AvatarStack :users="team.members" :max="4" />{{
                        team.members.length
                    }}</span
                >
                <span
                    v-if="leads.length > 0"
                    class="text-[12px] text-muted-foreground"
                    >Lead {{ leads.map((l) => l.name).join(', ') }}</span
                >
                <span
                    v-if="team.active_cycle"
                    class="rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground"
                    >{{ team.active_cycle.name }} ·
                    {{ Math.round(team.active_cycle.progress * 100) }}%</span
                >
            </template>
            <template #actions>
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8 gap-1.5"
                    @click="
                        openCreateIssue({
                            team_ids: [team.id],
                            lead_team_id: team.id,
                        })
                    "
                    ><Plus class="size-3.5" /> New issue
                    <Kbd class="h-4 px-1 text-[10px]">C</Kbd></Button
                >
            </template>
        </WorkspaceHeader>

        <div v-if="listTab" class="min-h-0 flex-1">
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
                :base-url="`${base}/${tab}`"
                empty-key="team"
            />
        </div>

        <div
            v-else
            class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-4 py-6 sm:px-6"
        >
            <div class="mx-auto max-w-5xl">
                <OverviewTab
                    v-if="tab === 'overview'"
                    :team="team"
                    :workflow="workflow"
                    :overview="overview"
                />
                <CyclesTab
                    v-else-if="tab === 'cycles'"
                    :team="team"
                    :cycles="cycles"
                />
                <div v-else-if="tab === 'workload'" class="space-y-8">
                    <WorkloadTab :team="team" :workload="workload" />
                    <div
                        v-if="projects && projects.length > 0"
                        class="max-w-3xl"
                    >
                        <p
                            class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Projects this team is on
                        </p>
                        <ProjectRows :projects="projects" />
                    </div>
                </div>
                <SettingsTab
                    v-else-if="tab === 'settings'"
                    :team="team"
                    :workflow="workflow"
                    @update:workflow="(w) => (workflow = w)"
                />
            </div>
        </div>
    </div>
</template>
