<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, inject } from 'vue';
import IssueWorkspaceBody from '@/components/projects/IssueWorkspaceBody.vue';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { useKeyboard } from '@/composables/useKeyboard';
import { PRIORITY } from '@/lib/keyboard';
import { cn } from '@/lib/utils';
import type {
    BoardColumn,
    CreateIssuePrefill,
    CursorPage,
    IssueDisplay,
    IssueFilters,
    IssueListItem,
    IssueLookups,
    MyIssuesBucket,
    WorkflowStatus,
} from '@/types';

/**
 * The personal workspace: everything assigned to, owned by, created by or followed by you,
 * plus what is due and what you looked at recently. One row per issue, even when several of
 * your teams are on it.
 */
const props = defineProps<{
    bucket: MyIssuesBucket;
    buckets?: Record<string, number> | null;
    filters: IssueFilters;
    display: IssueDisplay;
    issues: CursorPage<IssueListItem>;
    board?: BoardColumn[] | null;
    lookups: IssueLookups;
}>();

const page = usePage();
const organization = computed(() => page.props.currentOrganization);
const slug = computed(() => organization.value?.slug ?? '');
const meId = computed(() => page.props.auth.user.id);
const openCreateIssue = inject<(prefill?: CreateIssuePrefill) => void>(
    'openCreateIssue',
    () => {},
);

const tabs: { value: MyIssuesBucket; label: string }[] = [
    { value: 'assigned', label: 'Assigned' },
    { value: 'owned', label: 'Owned' },
    { value: 'workstreams', label: 'My teams' },
    { value: 'created', label: 'Created' },
    { value: 'subscribed', label: 'Following' },
    { value: 'due_soon', label: 'Due soon' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'completed', label: 'Completed' },
    { value: 'recent', label: 'Recent' },
];

const baseUrl = computed(() => `/${slug.value}/my-issues`);
const container = computed(() => ({
    type: 'my' as const,
    id: props.bucket,
    name: 'My issues',
    url: baseUrl.value,
}));
const workflow = computed<WorkflowStatus[]>(() => props.lookups.statuses);
const channels = computed(() =>
    organization.value ? [`organization.${organization.value.id}.issues`] : [],
);
const teamIds = computed(() => props.lookups.team_ids);

const switchBucket = (bucket: MyIssuesBucket) =>
    router.visit(`${baseUrl.value}?bucket=${bucket}`, {
        preserveState: true,
        preserveScroll: true,
        only: ['bucket', 'issues', 'filters', 'display', 'board'],
        reset: ['issues'],
    });

const today = new Date().toISOString().slice(0, 10);
const week = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
const terminal = (issue: IssueListItem) =>
    issue.status_category === 'completed' ||
    issue.status_category === 'canceled';
const mine = (issue: IssueListItem) =>
    issue.owner?.id === meId.value ||
    issue.assignees.some((a) => a.id === meId.value);

/** Keeps realtime rows honest: an issue only stays in a bucket while it still qualifies. */
const belongs = (issue: IssueListItem): boolean => {
    switch (props.bucket) {
        case 'assigned':
            return (
                issue.assignees.some((a) => a.id === meId.value) &&
                !terminal(issue)
            );
        case 'owned':
            return issue.owner?.id === meId.value && !terminal(issue);
        case 'workstreams':
            return issue.teams.some(
                (t) =>
                    teamIds.value.includes(t.id) &&
                    t.status_category !== 'completed' &&
                    t.status_category !== 'canceled',
            );
        case 'created':
            return true;
        case 'subscribed':
            return true;
        case 'due_soon':
            return (
                mine(issue) &&
                Boolean(issue.due_date) &&
                issue.due_date! >= today &&
                issue.due_date! <= week &&
                !terminal(issue)
            );
        case 'overdue':
            return (
                mine(issue) &&
                Boolean(issue.due_date) &&
                issue.due_date! < today &&
                !terminal(issue)
            );
        case 'completed':
            return mine(issue) && issue.status_category === 'completed';
        default:
            return true;
    }
};

useKeyboard(
    () =>
        tabs.map((tab, index) => ({
            keys: String(index + 1),
            description: `Show ${tab.label.toLowerCase()}`,
            group: 'My issues',
            hidden: index > 0,
            handler: () => switchBucket(tab.value),
        })),
    { scope: 'My issues', priority: PRIORITY.page },
);
</script>

<template>
    <Head title="My issues" />

    <IssueWorkspaceBody
        :container="container"
        :filters="filters"
        :display="display"
        :issues="issues"
        :board="board"
        :workflow="workflow"
        :lookups="lookups"
        :channels="channels"
        :base-url="baseUrl"
        :query="{ bucket }"
        :allow-modes="['list', 'board']"
        :empty-key="bucket"
        :belongs="belongs"
        :prefill="{ owner_id: meId }"
    >
        <template #header>
            <header class="shrink-0 px-4 pt-2.5 pl-12 md:pl-4">
                <div class="flex h-9 items-center gap-3">
                    <h1 class="text-[15px] font-semibold tracking-[-0.01em]">
                        My issues
                    </h1>
                    <Button
                        size="sm"
                        class="ml-auto h-8 gap-1.5 text-brand-foreground brand-button-surface"
                        @click="openCreateIssue({ owner_id: meId })"
                    >
                        <Plus class="size-3.5" /> New issue
                        <Kbd
                            class="h-4 min-w-4 bg-brand-foreground/15 px-1 text-[10px] text-brand-foreground/80"
                            >C</Kbd
                        >
                    </Button>
                </div>
                <nav
                    class="-mx-1 flex scrollbar-thin items-center gap-0.5 overflow-x-auto pb-1"
                    aria-label="Buckets"
                >
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        :class="
                            cn(
                                'flex h-7 shrink-0 items-center gap-1.5 rounded-md px-2 text-[12.5px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
                                bucket === tab.value &&
                                    'bg-brand-soft/70 font-medium text-brand-soft-foreground hover:bg-brand-soft hover:text-brand-soft-foreground',
                            )
                        "
                        :aria-current="
                            bucket === tab.value ? 'page' : undefined
                        "
                        @click="switchBucket(tab.value)"
                    >
                        {{ tab.label }}
                        <span
                            v-if="
                                buckets === undefined && tab.value !== 'recent'
                            "
                            class="h-3 w-4 shimmer rounded"
                        />
                        <span
                            v-else-if="
                                buckets && buckets[tab.value] !== undefined
                            "
                            :class="
                                cn(
                                    'text-[10.5px] tabular-nums',
                                    tab.value === 'overdue' &&
                                        buckets[tab.value] > 0
                                        ? 'font-semibold text-destructive'
                                        : 'text-muted-foreground/80',
                                )
                            "
                            >{{ buckets[tab.value] }}</span
                        >
                    </button>
                </nav>
            </header>
        </template>
    </IssueWorkspaceBody>
</template>
