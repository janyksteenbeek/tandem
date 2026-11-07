<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, defineAsyncComponent, inject, ref, watch } from 'vue';
import IssueWorkspaceBody from '@/components/projects/IssueWorkspaceBody.vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import ActivityFeed from '@/components/projects/workspace/ActivityFeed.vue';
import WorkspaceHeader from '@/components/projects/workspace/WorkspaceHeader.vue';
import { workspaceTabs } from '@/components/projects/workspace/useWorkspaceTabs';
import OverviewTab from '@/components/projects/workspace/project/OverviewTab.vue';
import type { ProjectOverview } from '@/components/projects/workspace/project/OverviewTab.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { useIssueLookups } from '@/composables/projects/useIssueLookups';
import { PROJECT_HEALTH_META, PROJECT_STATUS_META } from '@/lib/projects/meta';
import { cn } from '@/lib/utils';
import type {
    Activity,
    BoardColumn,
    CreateIssuePrefill,
    CursorPage,
    IssueDisplay,
    IssueFilters,
    IssueListItem,
    IssueLookups,
    Milestone,
    Project,
    Workflow,
} from '@/types';

const MilestonesTab = defineAsyncComponent(
    () => import('@/components/projects/workspace/project/MilestonesTab.vue'),
);
const SettingsTab = defineAsyncComponent(
    () => import('@/components/projects/workspace/project/SettingsTab.vue'),
);

const props = defineProps<{
    project: Project;
    tab:
        | 'overview'
        | 'issues'
        | 'board'
        | 'timeline'
        | 'milestones'
        | 'activity'
        | 'settings';
    lookups: IssueLookups;
    filters: IssueFilters;
    display: IssueDisplay;
    workflow: Workflow;
    overview?: ProjectOverview | null;
    issues?: CursorPage<IssueListItem> | null;
    board?: BoardColumn[] | null;
    milestones?: Milestone[] | null;
    dependencies?: { from: string; to: string }[] | null;
    activity?: Activity[] | null;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(() => `/${slug.value}/projects/${props.project.slug}`);
const timelineProject = computed(() => ({
    ...props.project,
    milestones: props.milestones ?? props.project.milestones ?? [],
}));
const { lookups } = useIssueLookups(() => props.lookups);
const openCreateIssue = inject<(prefill?: CreateIssuePrefill) => void>(
    'openCreateIssue',
    () => {},
);

const project = ref(props.project);
watch(
    () => props.project,
    (p) => (project.value = p),
);

const tabs = computed(() =>
    workspaceTabs(base.value, [
        { key: 'overview', label: 'Overview' },
        {
            key: 'issues',
            label: 'Issues',
            props: ['issues', 'workflow'],
            count:
                project.value.counts.issues -
                project.value.counts.completed -
                project.value.counts.canceled,
        },
        {
            key: 'board',
            label: 'Board',
            props: ['board', 'workflow'],
            query: '?mode=board',
        },
        {
            key: 'timeline',
            label: 'Timeline',
            props: ['issues', 'workflow'],
            query: '?mode=timeline',
        },
        {
            key: 'milestones',
            label: 'Milestones',
            count: project.value.milestones.length || null,
        },
        { key: 'activity', label: 'Activity' },
        ...(project.value.can.update
            ? [{ key: 'settings', label: 'Settings', props: ['project'] }]
            : []),
    ]),
);

const container = computed(() => ({
    type: 'project' as const,
    id: project.value.id,
    name: project.value.name,
    url: `${base.value}/issues`,
}));
const listTab = computed(() =>
    ['issues', 'board', 'timeline'].includes(props.tab),
);
const crumbs = computed(() => [
    { label: 'Projects', href: `/${slug.value}/projects/all` },
    { label: project.value.name },
]);
</script>

<template>
    <Head :title="project.name" />

    <div class="flex h-full min-h-0 flex-col">
        <WorkspaceHeader
            :title="project.name"
            :color="project.color"
            :breadcrumbs="crumbs"
            :tabs="tabs"
            :active-tab="tab"
            favorite-type="project"
            :favorite-id="project.id"
        >
            <template #meta>
                <Link
                    v-if="project.product"
                    :href="`/${slug}/products/${project.product.slug}`"
                    class="flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11.5px] text-muted-foreground transition-colors hover:text-foreground"
                    ><InboxDot :color="project.product.color" size="sm" />{{
                        project.product.name
                    }}</Link
                >
                <span
                    class="flex items-center gap-1.5 text-[12px] text-muted-foreground"
                    ><span
                        :class="
                            cn(
                                'size-1.5 rounded-full',
                                PROJECT_STATUS_META[project.status].dot,
                            )
                        "
                    />{{ PROJECT_STATUS_META[project.status].label }}</span
                >
                <span
                    v-if="project.health !== 'unknown'"
                    :class="
                        cn(
                            'text-[12px]',
                            PROJECT_HEALTH_META[project.health].className,
                        )
                    "
                    >{{ PROJECT_HEALTH_META[project.health].label }}</span
                >
                <span
                    class="flex items-center gap-1.5 text-[12px] text-muted-foreground tabular-nums"
                    ><ProgressRing :value="project.progress" :size="14" />{{
                        Math.round(project.progress * 100)
                    }}%</span
                >
                <span
                    v-if="project.lead"
                    class="flex items-center gap-1.5 text-[12px] text-muted-foreground"
                    ><UserAvatar
                        :name="project.lead.name"
                        :email="project.lead.email"
                        :src="project.lead.avatar"
                        :initials="project.lead.initials"
                        size="xs"
                    />{{ project.lead.name }}</span
                >
                <span
                    v-if="project.target_date"
                    class="text-[12px] text-muted-foreground tabular-nums"
                    >→ {{ project.target_date }}</span
                >
            </template>
            <template #actions>
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8 gap-1.5"
                    @click="
                        openCreateIssue({
                            project_id: project.id,
                            product_id: project.product?.id ?? null,
                            team_ids: project.teams
                                .map((t) => t.id)
                                .slice(0, 1),
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
                :channels="[`project.${project.uuid}`]"
                :base-url="`${base}/${tab}`"
                :allow-modes="
                    tab === 'timeline' ? ['timeline', 'list'] : undefined
                "
                :project="timelineProject"
                :dependencies="dependencies ?? []"
                empty-key="project"
            />
        </div>

        <div
            v-else
            class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-4 py-6 sm:px-6"
        >
            <div class="mx-auto max-w-5xl">
                <OverviewTab
                    v-if="tab === 'overview'"
                    :project="project"
                    :overview="overview"
                    @updated="(p) => (project = p)"
                />
                <MilestonesTab
                    v-else-if="tab === 'milestones'"
                    :project="project"
                    :milestones="milestones"
                />
                <div v-else-if="tab === 'activity'" class="max-w-3xl">
                    <ActivityFeed :activity="activity" />
                </div>
                <SettingsTab
                    v-else-if="tab === 'settings'"
                    :project="project"
                    :lookups="lookups"
                    @updated="(p) => (project = p)"
                />
            </div>
        </div>
    </div>
</template>
