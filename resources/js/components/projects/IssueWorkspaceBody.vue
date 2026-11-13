<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    computed,
    defineAsyncComponent,
    inject,
    onBeforeUnmount,
    onMounted,
    ref,
    useAttrs,
    watch,
} from 'vue';
import BulkActionBar from '@/components/projects/list/BulkActionBar.vue';
import IssueList from '@/components/projects/list/IssueList.vue';
import FilterBar from '@/components/projects/filters/FilterBar.vue';
import SavedViewDialog from '@/components/projects/filters/SavedViewDialog.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    provideIssueActions,
    useIssueActions,
} from '@/composables/projects/useIssueActions';
import { useIssueView } from '@/composables/projects/useIssueView';
import { useSidebarPreferences } from '@/composables/projects/useSidebarPreferences';
import { api, reportError } from '@/lib/http';
import { toQuery } from '@/lib/projects/issueFilters';
import { issueRows } from '@/lib/projects/timelineRows';
import type { Dependency } from '@/lib/projects/timelineRows';
import type { TimelineRow } from '@/lib/projects/timelineScale';
import type {
    BoardColumn,
    CompletionReadiness,
    CreateIssuePrefill,
    CursorPage,
    IssueContainer,
    IssueDisplay,
    IssueFilters,
    IssueListItem,
    IssueLookups,
    IssueViewMode,
    Milestone,
    ProjectSummary,
    WorkflowStatus,
} from '@/types';

const IssueBoard = defineAsyncComponent(
    () => import('@/components/projects/board/IssueBoard.vue'),
);
const IssueCalendar = defineAsyncComponent(
    () => import('@/components/projects/calendar/IssueCalendar.vue'),
);
const Timeline = defineAsyncComponent(
    () => import('@/components/projects/timeline/Timeline.vue'),
);

/**
 * The body every issue-list page shares: filter bar, list or board, selection with bulk
 * actions, saved views and the "complete anyway" confirmation. Pages only pass props and
 * decide where opening an issue goes.
 */
const props = withDefaults(
    defineProps<{
        container: IssueContainer;
        filters: IssueFilters;
        display: IssueDisplay;
        issues: CursorPage<IssueListItem> | null | undefined;
        board?: BoardColumn[] | null;
        workflow: WorkflowStatus[];
        lookups: IssueLookups | null;
        focusTeamId?: number | null;
        channels: string[];
        baseUrl: string;
        allowModes?: IssueViewMode[];
        emptyKey?: string;
        emptyTitle?: string;
        emptyBody?: string;
        belongs?: (issue: IssueListItem) => boolean;
        showFilterBar?: boolean;
        prefill?: CreateIssuePrefill;
        /** Extra query params to keep on every visit (e.g. bucket). */
        query?: Record<string, string | number | null | undefined>;
        propKeys?: string[];
        selectable?: boolean;
        /** Gives the timeline a project row, its milestones and date editing endpoints. */
        project?: (ProjectSummary & { milestones?: Milestone[] }) | null;
        /** Known blocking relations between loaded issues, drawn as arrows on the timeline. */
        dependencies?: Dependency[];
    }>(),
    {
        board: null,
        focusTeamId: null,
        allowModes: () => ['list', 'board', 'calendar', 'timeline'],
        emptyKey: 'default',
        showFilterBar: true,
        query: () => ({}),
        propKeys: () => ['issues', 'filters', 'display', 'board'],
        selectable: true,
        project: null,
        dependencies: () => [],
    },
);

const emit = defineEmits<{ open: [issue: IssueListItem] }>();
const attrs = useAttrs();
const useAttrsSafe = () => attrs as { onOpen?: unknown };

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const openCreateIssue = inject<(prefill?: CreateIssuePrefill) => void>(
    'openCreateIssue',
    () => {},
);
const prefs = useSidebarPreferences();
const containerKey = computed(
    () => `${props.container.type}:${props.container.id ?? 'all'}`,
);

const actions = useIssueActions();
provideIssueActions(actions);

const view = useIssueView({
    page: () => props.issues,
    filters: () => props.filters,
    channels: () => props.channels,
    belongs: props.belongs,
    propKey: 'issues',
});

const selected = ref<string[]>([]);
const listRef = ref<InstanceType<typeof IssueList> | null>(null);
const saveOpen = ref(false);
const mode = computed(() =>
    props.allowModes.includes(props.display.mode) ? props.display.mode : 'list',
);

const visit = (filters: IssueFilters, display: IssueDisplay) => {
    view.loading.value = true;
    prefs.rememberDisplay(containerKey.value, {
        mode: display.mode,
        group_by: display.group_by,
        sort: display.sort,
        show_completed: display.show_completed,
        columns: display.columns,
    });

    router.visit(props.baseUrl + toQuery(filters, display, props.query), {
        preserveState: true,
        preserveScroll: true,
        only: props.propKeys,
        reset: ['issues'],
        onFinish: () => (view.loading.value = false),
    });
};

const updateFilters = (filters: IssueFilters) => visit(filters, props.display);
const updateDisplay = (display: IssueDisplay) => visit(props.filters, display);

/** Board columns arrive lazily; ask for them the first time the board is shown. */
watch(
    mode,
    (value) => {
        if (value === 'board' && !props.board) {
            router.reload({
                only: ['board'],
                data: { ...props.query, mode: 'board' },
            });
        }
    },
    { immediate: true },
);

const open = (issue: IssueListItem) => emit('open', issue);

/** Default open: full visit to the issue with a view transition. */
const defaultOpen = (issue: IssueListItem) =>
    router.visit(`/${slug.value}/issues/${issue.identifier}`, {
        viewTransition: true,
    });

const bulk = (action: string, params: Record<string, unknown>) => {
    const uuids = [...selected.value];

    if (uuids.length === 0) {
        return;
    }

    void actions.bulk(uuids, action, params);
    listRef.value?.clearSelection();
};

/** "Complete anyway" confirmation, raised by rows, cards and the board. */
const blocked = ref<{
    issue: IssueListItem;
    status: WorkflowStatus;
    readiness: CompletionReadiness;
} | null>(null);

const onBlocked = (event: Event) => {
    blocked.value = (
        event as CustomEvent<{
            issue: IssueListItem;
            status: WorkflowStatus;
            readiness: CompletionReadiness;
        }>
    ).detail;
};

onMounted(() =>
    window.addEventListener('tandem:completion-blocked', onBlocked),
);
onBeforeUnmount(() =>
    window.removeEventListener('tandem:completion-blocked', onBlocked),
);

const completeAnyway = async () => {
    if (!blocked.value) {
        return;
    }

    const { issue, status } = blocked.value;
    blocked.value = null;
    await actions.setStatus(issue, status, true, 'Completed from the board');
};

const moveOnBoard = async ({
    issue,
    status,
}: {
    issue: IssueListItem;
    status: WorkflowStatus;
    position: number;
    rebalance: boolean;
}) => {
    if (props.focusTeamId && issue.workstream) {
        await actions.setWorkstreamStatus(issue, issue.workstream, status);

        return;
    }

    const result = await actions.setStatus(issue, status);

    if (result && 'readiness' in result) {
        blocked.value = { issue, status, readiness: result.readiness };
    }
};

const total = computed(() =>
    props.issues && !view.nextCursor.value ? view.items.value.length : null,
);

/* --- calendar & timeline ---------------------------------------------------------------------- */

const reschedule = ({
    issue,
    start_date,
    due_date,
}: {
    issue: IssueListItem;
    start_date: string | null;
    due_date: string | null;
}) => void actions.setDates(issue, { start_date, due_date });

const timelineRows = computed<TimelineRow[]>(() =>
    issueRows(view.items.value, {
        project: props.project,
        milestones: props.project?.milestones ?? null,
        dependencies: props.dependencies,
        orgSlug: slug.value,
    }),
);

const issueByUuid = computed(
    () => new Map(view.items.value.map((issue) => [issue.uuid, issue])),
);

const shiftRow = async ({
    kind,
    start,
    end,
    row,
}: {
    id: string;
    kind: TimelineRow['kind'];
    start: string | null;
    end: string | null;
    row: TimelineRow;
}) => {
    const meta = row.meta ?? {};

    if (kind === 'issue') {
        const issue = issueByUuid.value.get(String(meta.uuid));

        if (issue) {
            await actions.setDates(issue, { start_date: start, due_date: end });
        }

        return;
    }

    if (!props.project) {
        return;
    }

    try {
        if (kind === 'milestone') {
            await api.patch(
                `/${slug.value}/projects/${props.project.slug}/milestones/${String(meta.uuid)}`,
                { target_date: end ?? start },
            );
        } else if (kind === 'project') {
            await api.patch(`/${slug.value}/projects/${props.project.slug}`, {
                start_date: start,
                target_date: end,
            });
        }

        router.reload({ only: ['project', 'milestones'] });
    } catch (error) {
        reportError(error);
    }
};

const openRow = (row: TimelineRow) => {
    if (row.kind === 'issue') {
        const issue = issueByUuid.value.get(String(row.meta?.uuid));

        if (issue) {
            $attrsHasOpen() ? open(issue) : defaultOpen(issue);
        }

        return;
    }

    if (row.url) {
        router.visit(row.url);
    }
};

const $attrsHasOpen = () => Boolean(useAttrsSafe().onOpen);

/** Load every remaining page so the timeline shows the whole set. */
const loadingAll = ref(false);

const loadAll = async () => {
    if (loadingAll.value) {
        return;
    }

    loadingAll.value = true;

    const step = (): Promise<void> =>
        new Promise((resolve) => {
            if (!view.nextCursor.value) {
                resolve();

                return;
            }

            const stop = watch(
                () => view.loadingMore.value,
                (busy) => {
                    if (!busy) {
                        stop();
                        resolve();
                    }
                },
            );
            view.loadMore(props.query);
        });

    try {
        let guard = 0;

        while (view.nextCursor.value && guard++ < 50) {
            await step();
        }
    } finally {
        loadingAll.value = false;
    }
};
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <slot name="header" />

        <FilterBar
            v-if="showFilterBar"
            :filters="filters"
            :display="display"
            :lookups="lookups"
            :workflow-statuses="workflow"
            :total="total"
            :allow-modes="allowModes"
            :container="container"
            :focus-team-id="focusTeamId"
            @update:filters="updateFilters"
            @update:display="updateDisplay"
            @save="saveOpen = true"
        />

        <BulkActionBar
            v-if="selected.length > 0"
            :count="selected.length"
            :lookups="lookups"
            :workflow="workflow"
            @clear="listRef?.clearSelection()"
            @action="bulk"
        />

        <IssueList
            v-if="mode === 'list'"
            ref="listRef"
            :issues="view.items.value"
            :display="display"
            :lookups="lookups"
            :workflow="workflow"
            :focus-team-id="focusTeamId"
            :loading="view.loading.value && view.items.value.length === 0"
            :loading-more="view.loadingMore.value"
            :has-more="Boolean(view.nextCursor.value)"
            :container-key="containerKey"
            :empty-key="emptyKey"
            :empty-title="emptyTitle"
            :empty-body="emptyBody"
            :prefill="prefill"
            :selectable="selectable"
            @load-more="view.loadMore(query)"
            @open="$attrs.onOpen ? open($event) : defaultOpen($event)"
            @selection-change="(uuids) => (selected = uuids)"
        >
            <template #empty><slot name="empty" /></template>
        </IssueList>

        <IssueBoard
            v-else-if="mode === 'board' && board"
            :columns="board"
            :statuses="workflow"
            :lookups="lookups"
            :focus-team-id="focusTeamId"
            :container-key="containerKey"
            :prefill="prefill"
            @open="$attrs.onOpen ? open($event) : defaultOpen($event)"
            @move="moveOnBoard"
        />

        <div
            v-else-if="mode === 'board'"
            class="flex flex-1 items-center justify-center text-sm text-muted-foreground"
        >
            Loading board…
        </div>

        <IssueCalendar
            v-else-if="mode === 'calendar'"
            :issues="view.items.value"
            :lookups="lookups"
            :workflow="workflow"
            :focus-team-id="focusTeamId"
            :container-key="containerKey"
            @open="$attrs.onOpen ? open($event) : defaultOpen($event)"
            @reschedule="reschedule"
            @create="
                (date) =>
                    openCreateIssue({ ...(prefill ?? {}), due_date: date })
            "
        />

        <template v-else-if="mode === 'timeline'">
            <div
                v-if="view.nextCursor.value"
                class="flex items-center gap-3 border-b bg-surface px-4 py-1.5 text-[12px] text-muted-foreground"
            >
                <span
                    >Showing the first
                    {{ view.items.value.length }} issues.</span
                >
                <Button
                    variant="link"
                    size="sm"
                    class="h-auto p-0 text-[12px]"
                    :disabled="loadingAll"
                    @click="loadAll"
                >
                    {{
                        loadingAll
                            ? 'Loading…'
                            : 'Load all issues for the timeline'
                    }}
                </Button>
            </div>
            <Timeline
                :rows="timelineRows"
                :container-key="containerKey"
                :can-edit="true"
                @shift="shiftRow"
                @open="openRow"
            />
        </template>

        <EmptyState
            v-else
            view="issues"
            title="This view isn't available here."
            body="Switch to list or board."
        />

        <SavedViewDialog
            v-model:open="saveOpen"
            :filters="filters"
            :display="display"
            :lookups="lookups"
        />

        <Dialog
            :open="blocked !== null"
            @update:open="(v) => !v && (blocked = null)"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Required workstreams aren't done</DialogTitle>
                    <DialogDescription v-if="blocked">
                        {{
                            blocked.readiness.required -
                            blocked.readiness.required_completed -
                            blocked.readiness.required_canceled
                        }}
                        of {{ blocked.readiness.required }} required team{{
                            blocked.readiness.required === 1 ? '' : 's'
                        }}
                        on {{ blocked.issue.identifier }} still
                        {{
                            blocked.readiness.required -
                                blocked.readiness.required_completed -
                                blocked.readiness.required_canceled ===
                            1
                                ? 'has'
                                : 'have'
                        }}
                        open work. Completing anyway is recorded in the audit
                        log.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="blocked = null"
                        >Keep it open</Button
                    >
                    <Button @click="completeAnyway">Complete anyway</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
