<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, inject, onMounted } from 'vue';
import WorkspaceHeader from '@/components/projects/workspace/WorkspaceHeader.vue';
import IssueWorkspaceBody from '@/components/projects/IssueWorkspaceBody.vue';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import type {
    BoardColumn,
    CreateIssuePrefill,
    CursorPage,
    IssueContainer,
    IssueDisplay,
    IssueFilters,
    IssueListItem,
    IssueLookups,
    IssueView,
    WorkflowStatus,
} from '@/types';

/**
 * Every issue in the organization, or one saved view (same page, `view` set).
 */
const props = defineProps<{
    container: IssueContainer;
    view?:
        | (Pick<
              IssueView,
              'id' | 'uuid' | 'name' | 'scope' | 'icon' | 'color'
          > & { can_manage: boolean })
        | null;
    filters: IssueFilters;
    display: IssueDisplay;
    issues: CursorPage<IssueListItem>;
    board?: BoardColumn[] | null;
    workflow: WorkflowStatus[];
    lookups: IssueLookups;
    open_create?: boolean;
}>();

const page = usePage();
const organization = computed(() => page.props.currentOrganization);
const openCreateIssue = inject<(prefill?: CreateIssuePrefill) => void>(
    'openCreateIssue',
    () => {},
);

const title = computed(() => props.view?.name ?? 'Issues');
const crumbs = computed(() =>
    props.view
        ? [
              { label: 'Issues', href: `/${organization.value?.slug}/issues` },
              { label: props.view.name },
          ]
        : [{ label: 'Issues' }],
);
const subtitle = computed(() =>
    props.view
        ? {
              personal: 'Personal view',
              team: 'Team view',
              organization: 'Shared with everyone',
          }[props.view.scope]
        : `Everything you can see in ${organization.value?.name ?? 'this organization'}`,
);
const channels = computed(() =>
    organization.value ? [`organization.${organization.value.id}.issues`] : [],
);

onMounted(() => {
    if (props.open_create) {
        openCreateIssue();
    }
});
</script>

<template>
    <Head :title="title" />

    <IssueWorkspaceBody
        :container="container"
        :filters="filters"
        :display="display"
        :issues="issues"
        :board="board"
        :workflow="workflow"
        :lookups="lookups"
        :channels="channels"
        :base-url="container.url"
        :allow-modes="['list', 'board', 'calendar', 'timeline']"
        empty-key="default"
    >
        <template #header>
            <WorkspaceHeader :breadcrumbs="crumbs" :subtitle="subtitle">
                <template #actions>
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-8 gap-1.5"
                        @click="openCreateIssue()"
                    >
                        <Plus class="size-3.5" /> New issue
                        <Kbd class="h-4 min-w-4 px-1 text-[10px]">C</Kbd>
                    </Button>
                </template>
            </WorkspaceHeader>
        </template>
    </IssueWorkspaceBody>
</template>
