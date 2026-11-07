<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Plus, Settings } from '@lucide/vue';
import { computed, defineAsyncComponent, inject, ref, watch } from 'vue';
import IssueWorkspaceBody from '@/components/projects/IssueWorkspaceBody.vue';
import ActivityFeed from '@/components/projects/workspace/ActivityFeed.vue';
import ProjectRows from '@/components/projects/workspace/ProjectRows.vue';
import WorkspaceHeader from '@/components/projects/workspace/WorkspaceHeader.vue';
import { workspaceTabs } from '@/components/projects/workspace/useWorkspaceTabs';
import OverviewTab from '@/components/projects/workspace/product/OverviewTab.vue';
import type { ProductOverview } from '@/components/projects/workspace/product/OverviewTab.vue';
import ReleasesTab from '@/components/projects/workspace/product/ReleasesTab.vue';
import RoadmapTab from '@/components/projects/workspace/product/RoadmapTab.vue';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { useIssueLookups } from '@/composables/projects/useIssueLookups';
import type {
    Activity,
    BoardColumn,
    CreateIssuePrefill,
    CursorPage,
    IssueDisplay,
    IssueFilters,
    IssueListItem,
    IssueLookups,
    Product,
    ProjectSummary,
    Release,
    Workflow,
} from '@/types';

const ModulesTab = defineAsyncComponent(
    () => import('@/components/projects/workspace/product/ModulesTab.vue'),
);
const SettingsTab = defineAsyncComponent(
    () => import('@/components/projects/workspace/product/SettingsTab.vue'),
);

const props = defineProps<{
    product: Product;
    tab:
        | 'overview'
        | 'issues'
        | 'modules'
        | 'projects'
        | 'roadmap'
        | 'releases'
        | 'activity'
        | 'settings';
    lookups: IssueLookups;
    filters: IssueFilters;
    display: IssueDisplay;
    workflow: Workflow;
    overview?: ProductOverview | null;
    issues?: CursorPage<IssueListItem> | null;
    board?: BoardColumn[] | null;
    projects?: ProjectSummary[] | null;
    releases?: Release[] | null;
    activity?: Activity[] | null;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(() => `/${slug.value}/products/${props.product.slug}`);
const { lookups } = useIssueLookups(() => props.lookups);
const openCreateIssue = inject<(prefill?: CreateIssuePrefill) => void>(
    'openCreateIssue',
    () => {},
);

const product = ref(props.product);
const workflow = ref(props.workflow);
watch(
    () => props.product,
    (p) => (product.value = p),
);
watch(
    () => props.workflow,
    (w) => (workflow.value = w),
);

const tabs = computed(() =>
    workspaceTabs(base.value, [
        { key: 'overview', label: 'Overview' },
        {
            key: 'issues',
            label: 'Issues',
            props: ['issues', 'board', 'workflow'],
            count: product.value.open_issues_count,
        },
        {
            key: 'modules',
            label: 'Modules',
            props: ['product'],
            count: product.value.modules.length,
        },
        { key: 'projects', label: 'Projects' },
        { key: 'roadmap', label: 'Roadmap', props: ['projects', 'releases'] },
        { key: 'releases', label: 'Releases' },
        { key: 'activity', label: 'Activity' },
        ...(product.value.can.update
            ? [
                  {
                      key: 'settings',
                      label: 'Settings',
                      props: ['product', 'workflow'],
                  },
              ]
            : []),
    ]),
);

const container = computed(() => ({
    type: 'product' as const,
    id: product.value.id,
    name: product.value.name,
    url: `${base.value}/issues`,
}));
</script>

<template>
    <Head :title="product.name" />

    <div class="flex h-full min-h-0 flex-col">
        <WorkspaceHeader
            :title="product.name"
            :subtitle="`${product.key} · ${product.open_issues_count} open issues`"
            :color="product.color"
            :breadcrumbs="[
                { label: 'Products', href: `/${slug}/products` },
                { label: product.name },
            ]"
            :tabs="tabs"
            :active-tab="tab"
            favorite-type="product"
            :favorite-id="product.id"
        >
            <template #actions>
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8 gap-1.5"
                    @click="openCreateIssue({ product_id: product.id })"
                    ><Plus class="size-3.5" /> New issue
                    <Kbd class="h-4 px-1 text-[10px]">C</Kbd></Button
                >
                <Button
                    v-if="product.can.update && tab !== 'settings'"
                    size="icon"
                    variant="ghost"
                    class="size-8"
                    aria-label="Product settings"
                    as-child
                >
                    <a :href="`${base}/settings`"
                        ><Settings class="size-4"
                    /></a>
                </Button>
            </template>
        </WorkspaceHeader>

        <div v-if="tab === 'issues'" class="min-h-0 flex-1">
            <IssueWorkspaceBody
                :container="container"
                :filters="filters"
                :display="display"
                :issues="issues"
                :board="board"
                :workflow="workflow.statuses"
                :lookups="lookups"
                :channels="[`product.${product.uuid}`]"
                :base-url="`${base}/issues`"
                empty-key="product"
            />
        </div>

        <div
            v-else
            class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-4 py-6 sm:px-6"
        >
            <div class="mx-auto max-w-5xl">
                <OverviewTab
                    v-if="tab === 'overview'"
                    :product="product"
                    :overview="overview"
                />
                <ModulesTab
                    v-else-if="tab === 'modules'"
                    :product="product"
                    :lookups="lookups"
                />
                <div v-else-if="tab === 'projects'" class="max-w-3xl">
                    <div
                        v-if="projects === undefined || projects === null"
                        class="space-y-2"
                    >
                        <div
                            v-for="i in 4"
                            :key="i"
                            class="h-10 shimmer rounded-lg"
                        />
                    </div>
                    <ProjectRows
                        v-else
                        :projects="projects"
                        :show-product="false"
                        :empty-text="`No projects in ${product.name} yet.`"
                    />
                </div>
                <RoadmapTab
                    v-else-if="tab === 'roadmap'"
                    :product="product"
                    :projects="projects"
                    :releases="releases"
                />
                <ReleasesTab
                    v-else-if="tab === 'releases'"
                    :product="product"
                    :releases="releases"
                />
                <div v-else-if="tab === 'activity'" class="max-w-3xl">
                    <ActivityFeed :activity="activity" />
                </div>
                <SettingsTab
                    v-else-if="tab === 'settings'"
                    :product="product"
                    :workflow="workflow"
                    :lookups="lookups"
                    @updated="(p) => (product = p)"
                    @update:workflow="(w) => (workflow = w)"
                />
            </div>
        </div>
    </div>
</template>
