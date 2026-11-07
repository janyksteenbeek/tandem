<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, inject, onMounted } from 'vue';
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
            <header
                class="flex h-12 shrink-0 items-center gap-3 px-4 pl-12 md:pl-4"
            >
                <div class="min-w-0">
                    <h1
                        class="truncate text-[15px] font-semibold tracking-[-0.01em]"
                    >
                        {{ title }}
                    </h1>
                    <p class="truncate text-[11.5px] text-muted-foreground">
                        {{ subtitle }}
                    </p>
                </div>
                <Button
                    size="sm"
                    class="ml-auto h-8 gap-1.5 text-brand-foreground brand-button-surface"
                    @click="openCreateIssue()"
                >
                    <Plus class="size-3.5" /> New issue
                    <Kbd
                        class="h-4 min-w-4 bg-brand-foreground/15 px-1 text-[10px] text-brand-foreground/80"
                        >C</Kbd
                    >
                </Button>
            </header>
        </template>
    </IssueWorkspaceBody>
</template>
