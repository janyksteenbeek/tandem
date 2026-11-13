<script setup lang="ts">
import { useVirtualizer } from '@tanstack/vue-virtual';
import { Loader2 } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import IssueContextMenu from '@/components/projects/list/IssueContextMenu.vue';
import IssueGroupHeader from '@/components/projects/list/IssueGroupHeader.vue';
import IssueRow from '@/components/projects/list/IssueRow.vue';
import IssueSkeleton from '@/components/projects/shared/IssueSkeleton.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import { useSidebarPreferences } from '@/composables/projects/useSidebarPreferences';
import { useKeyboard } from '@/composables/useKeyboard';
import { PRIORITY } from '@/lib/keyboard';
import {
    flattenGroups,
    groupIssues,
    sortIssues,
} from '@/lib/projects/issueGrouping';
import type { VirtualRow } from '@/lib/projects/issueGrouping';
import { EMPTY_COPY } from '@/lib/projects/meta';
import type {
    CreateIssuePrefill,
    IssueColumn,
    IssueDisplay,
    IssueListItem,
    IssueLookups,
    WorkflowStatus,
} from '@/types';

/**
 * The grouped, keyboard-driven issue list. Rows read from the store-backed `issues` array the
 * page provides; grouping and sorting are client-side so realtime changes reshuffle instantly.
 * Virtualised above 60 rows; sticky group headers either way.
 */
const props = withDefaults(
    defineProps<{
        issues: IssueListItem[];
        display: IssueDisplay;
        lookups: IssueLookups | null;
        workflow: WorkflowStatus[];
        focusTeamId?: number | null;
        loading?: boolean;
        loadingMore?: boolean;
        hasMore?: boolean;
        containerKey: string;
        emptyKey?: string;
        emptyTitle?: string;
        emptyBody?: string;
        prefill?: CreateIssuePrefill;
        selectable?: boolean;
    }>(),
    {
        focusTeamId: null,
        loading: false,
        loadingMore: false,
        hasMore: false,
        emptyKey: 'default',
        selectable: true,
    },
);

const emit = defineEmits<{
    loadMore: [];
    open: [issue: IssueListItem];
    selectionChange: [uuids: string[]];
}>();

const prefs = useSidebarPreferences();
const scrollEl = ref<HTMLElement | null>(null);
const sentinel = ref<HTMLElement | null>(null);
const rowRefs = new Map<string, InstanceType<typeof IssueRow>>();
const selected = ref<Set<string>>(new Set());
const cursorUuid = ref<string | null>(null);
const collapsed = ref<Set<string>>(new Set());
const mounted = ref(false);

onMounted(() => {
    collapsed.value = new Set(
        prefs.displayFor(props.containerKey).collapsedGroups ?? [],
    );
    mounted.value = true;
});

const statuses = computed(() => props.workflow);
const columns = computed<IssueColumn[] | undefined>(
    () => props.display.columns ?? undefined,
);

const groups = computed(() =>
    groupIssues(
        sortIssues(props.issues, props.display.sort, statuses.value),
        props.display.group_by,
        props.lookups,
        statuses.value,
        props.focusTeamId,
    ),
);
const showHeaders = computed(() => props.display.group_by !== 'none');
const rows = computed<VirtualRow[]>(() =>
    flattenGroups(groups.value, collapsed.value, showHeaders.value),
);
const issueRows = computed(() =>
    rows.value.filter(
        (r): r is Extract<VirtualRow, { kind: 'issue' }> => r.kind === 'issue',
    ),
);
const virtual = computed(() => mounted.value && rows.value.length > 60);

const virtualizer = useVirtualizer(
    computed(() => ({
        count: virtual.value ? rows.value.length : 0,
        getScrollElement: () => scrollEl.value,
        estimateSize: (index: number) =>
            rows.value[index]?.kind === 'group' ? 32 : 36,
        overscan: 12,
        getItemKey: (index: number) => {
            const row = rows.value[index];

            return row?.kind === 'group'
                ? `g:${row.group.key}`
                : `${row?.groupKey}:${row?.issue.uuid}`;
        },
    })),
);

const virtualRows = computed(() =>
    virtual.value ? virtualizer.value.getVirtualItems() : [],
);
const totalSize = computed(() =>
    virtual.value ? virtualizer.value.getTotalSize() : 0,
);

const toggleGroup = (key: string) => {
    const next = new Set(collapsed.value);
    next.has(key) ? next.delete(key) : next.add(key);
    collapsed.value = next;
    prefs.rememberDisplay(props.containerKey, { collapsedGroups: [...next] });
};

const emitSelection = () => emit('selectionChange', [...selected.value]);

const toggleSelect = (issue: IssueListItem, shift: boolean) => {
    const next = new Set(selected.value);
    const ordered = issueRows.value.map((r) => r.issue.uuid);

    if (shift && cursorUuid.value && ordered.includes(cursorUuid.value)) {
        const a = ordered.indexOf(cursorUuid.value);
        const b = ordered.indexOf(issue.uuid);
        ordered
            .slice(Math.min(a, b), Math.max(a, b) + 1)
            .forEach((uuid) => next.add(uuid));
    } else if (next.has(issue.uuid)) {
        next.delete(issue.uuid);
    } else {
        next.add(issue.uuid);
    }

    selected.value = next;
    cursorUuid.value = issue.uuid;
    emitSelection();
};

const clearSelection = () => {
    if (selected.value.size > 0) {
        selected.value = new Set();
        emitSelection();
    }
};

const selectAll = () => {
    selected.value = new Set(issueRows.value.map((r) => r.issue.uuid));
    emitSelection();
};

/** Drop selections of rows that left the list (realtime, filters). */
watch(issueRows, (list) => {
    const present = new Set(list.map((r) => r.issue.uuid));
    const kept = [...selected.value].filter((uuid) => present.has(uuid));

    if (kept.length !== selected.value.size) {
        selected.value = new Set(kept);
        emitSelection();
    }
});

const cursorIssue = computed(
    () =>
        issueRows.value.find((r) => r.issue.uuid === cursorUuid.value)?.issue ??
        null,
);

const scrollCursorIntoView = () => {
    const index = rows.value.findIndex(
        (r) => r.kind === 'issue' && r.issue.uuid === cursorUuid.value,
    );

    if (index === -1) {
        return;
    }

    if (virtual.value) {
        virtualizer.value.scrollToIndex(index, { align: 'auto' });

        return;
    }

    void nextTick(() =>
        scrollEl.value
            ?.querySelector<HTMLElement>(`[data-uuid="${cursorUuid.value}"]`)
            ?.scrollIntoView({ block: 'nearest' }),
    );
};

const moveCursor = (delta: number) => {
    const list = issueRows.value;

    if (list.length === 0) {
        return;
    }

    const current = list.findIndex((r) => r.issue.uuid === cursorUuid.value);
    const next = Math.max(
        0,
        Math.min(
            list.length - 1,
            (current === -1 ? (delta > 0 ? -1 : list.length) : current) + delta,
        ),
    );
    cursorUuid.value = list[next].issue.uuid;
    scrollCursorIntoView();
};

const openPicker = (kind: 'status' | 'priority' | 'assignee' | 'date') => {
    if (cursorUuid.value) {
        rowRefs.get(cursorUuid.value)?.openPicker(kind);
    }
};

useKeyboard(
    () => [
        {
            keys: 'j',
            description: 'Next issue',
            group: 'List',
            handler: () => moveCursor(1),
        },
        {
            keys: 'k',
            description: 'Previous issue',
            group: 'List',
            handler: () => moveCursor(-1),
        },
        {
            keys: 'arrowdown',
            description: 'Next issue',
            group: 'List',
            hidden: true,
            handler: () => moveCursor(1),
        },
        {
            keys: 'arrowup',
            description: 'Previous issue',
            group: 'List',
            hidden: true,
            handler: () => moveCursor(-1),
        },
        {
            keys: 'enter',
            description: 'Open issue',
            group: 'List',
            handler: () => cursorIssue.value && emit('open', cursorIssue.value),
        },
        {
            keys: 'x',
            description: 'Select issue',
            group: 'List',
            handler: () =>
                cursorIssue.value && toggleSelect(cursorIssue.value, false),
        },
        {
            keys: 'mod+a',
            description: 'Select all',
            group: 'List',
            handler: () => selectAll(),
        },
        {
            keys: 'escape',
            description: 'Clear selection',
            group: 'List',
            hidden: true,
            handler: () => clearSelection(),
        },
        {
            keys: 's',
            description: 'Change status',
            group: 'Issue',
            handler: () => openPicker('status'),
        },
        {
            keys: 'p',
            description: 'Set priority',
            group: 'Issue',
            handler: () => openPicker('priority'),
        },
        {
            keys: 'a',
            description: 'Assign',
            group: 'Issue',
            handler: () => openPicker('assignee'),
        },
        {
            keys: 'd',
            description: 'Set due date',
            group: 'Issue',
            handler: () => openPicker('date'),
        },
    ],
    { scope: 'List', priority: PRIORITY.page },
);

let observer: IntersectionObserver | null = null;

onMounted(() => {
    if (typeof IntersectionObserver === 'undefined') {
        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            if (
                entries.some((e) => e.isIntersecting) &&
                props.hasMore &&
                !props.loadingMore
            ) {
                emit('loadMore');
            }
        },
        { root: scrollEl.value, rootMargin: '400px' },
    );

    if (sentinel.value) {
        observer.observe(sentinel.value);
    }
});

watch(sentinel, (el, old) => {
    if (old) {
        observer?.unobserve(old);
    }

    if (el) {
        observer?.observe(el);
    }
});

onBeforeUnmount(() => observer?.disconnect());

const register = (uuid: string, instance: unknown) => {
    if (instance) {
        rowRefs.set(uuid, instance as InstanceType<typeof IssueRow>);
    } else {
        rowRefs.delete(uuid);
    }
};

const copy = computed(() => ({
    title:
        props.emptyTitle ??
        EMPTY_COPY[props.emptyKey]?.title ??
        EMPTY_COPY.default.title,
    body:
        props.emptyBody ??
        EMPTY_COPY[props.emptyKey]?.body ??
        EMPTY_COPY.default.body,
}));

defineExpose({ clearSelection, selectAll, selected, moveCursor });
</script>

<template>
    <div
        ref="scrollEl"
        class="relative scrollbar-thin min-h-0 flex-1 overflow-y-auto px-2 pb-6"
        role="listbox"
        aria-multiselectable="true"
        aria-label="Issues"
    >
        <IssueSkeleton v-if="loading" />

        <EmptyState
            v-else-if="rows.length === 0"
            view="issues"
            :title="copy.title"
            :body="copy.body"
        >
            <slot name="empty" />
        </EmptyState>

        <template v-else-if="virtual">
            <div :style="{ height: `${totalSize}px`, position: 'relative' }">
                <div
                    v-for="item in virtualRows"
                    :key="String(item.key)"
                    :data-index="item.index"
                    :ref="
                        (el) => el && virtualizer.measureElement(el as Element)
                    "
                    class="absolute top-0 left-0 w-full"
                    :style="{ transform: `translateY(${item.start}px)` }"
                >
                    <IssueGroupHeader
                        v-if="rows[item.index].kind === 'group'"
                        :group="
                            (
                                rows[item.index] as Extract<
                                    VirtualRow,
                                    { kind: 'group' }
                                >
                            ).group
                        "
                        :group-by="display.group_by"
                        :collapsed="
                            collapsed.has(
                                (
                                    rows[item.index] as Extract<
                                        VirtualRow,
                                        { kind: 'group' }
                                    >
                                ).group.key,
                            )
                        "
                        :focus-team-id="focusTeamId"
                        :prefill="prefill"
                        @toggle="
                            toggleGroup(
                                (
                                    rows[item.index] as Extract<
                                        VirtualRow,
                                        { kind: 'group' }
                                    >
                                ).group.key,
                            )
                        "
                    />
                    <IssueContextMenu
                        v-else
                        :issue="
                            (
                                rows[item.index] as Extract<
                                    VirtualRow,
                                    { kind: 'issue' }
                                >
                            ).issue
                        "
                        :lookups="lookups"
                        :workflow="workflow"
                        :focus-team-id="focusTeamId"
                        @open="emit('open', $event)"
                    >
                        <IssueRow
                            :ref="
                                (el) =>
                                    register(
                                        (
                                            rows[item.index] as Extract<
                                                VirtualRow,
                                                { kind: 'issue' }
                                            >
                                        ).issue.uuid,
                                        el,
                                    )
                            "
                            :issue="
                                (
                                    rows[item.index] as Extract<
                                        VirtualRow,
                                        { kind: 'issue' }
                                    >
                                ).issue
                            "
                            :columns="columns"
                            :selected="
                                selected.has(
                                    (
                                        rows[item.index] as Extract<
                                            VirtualRow,
                                            { kind: 'issue' }
                                        >
                                    ).issue.uuid,
                                )
                            "
                            :cursor="
                                cursorUuid ===
                                (
                                    rows[item.index] as Extract<
                                        VirtualRow,
                                        { kind: 'issue' }
                                    >
                                ).issue.uuid
                            "
                            :workflow="workflow"
                            :lookups="lookups"
                            :focus-team-id="focusTeamId"
                            :selectable="selectable"
                            @open="emit('open', $event)"
                            @toggle-select="toggleSelect"
                            @click="
                                cursorUuid = (
                                    rows[item.index] as Extract<
                                        VirtualRow,
                                        { kind: 'issue' }
                                    >
                                ).issue.uuid
                            "
                        />
                    </IssueContextMenu>
                </div>
            </div>
        </template>

        <template v-else>
            <template
                v-for="row in rows"
                :key="
                    row.kind === 'group'
                        ? `g:${row.group.key}`
                        : `${row.groupKey}:${row.issue.uuid}`
                "
            >
                <IssueGroupHeader
                    v-if="row.kind === 'group'"
                    :group="row.group"
                    :group-by="display.group_by"
                    :collapsed="collapsed.has(row.group.key)"
                    :focus-team-id="focusTeamId"
                    :prefill="prefill"
                    @toggle="toggleGroup(row.group.key)"
                />
                <IssueContextMenu
                    v-else
                    :issue="row.issue"
                    :lookups="lookups"
                    :workflow="workflow"
                    :focus-team-id="focusTeamId"
                    @open="emit('open', $event)"
                >
                    <IssueRow
                        :ref="(el) => register(row.issue.uuid, el)"
                        :issue="row.issue"
                        :columns="columns"
                        :selected="selected.has(row.issue.uuid)"
                        :cursor="cursorUuid === row.issue.uuid"
                        :workflow="workflow"
                        :lookups="lookups"
                        :focus-team-id="focusTeamId"
                        :selectable="selectable"
                        @open="emit('open', $event)"
                        @toggle-select="toggleSelect"
                        @click="cursorUuid = row.issue.uuid"
                    />
                </IssueContextMenu>
            </template>
        </template>

        <div
            v-if="!loading && rows.length > 0"
            ref="sentinel"
            class="flex h-10 items-center justify-center text-[11px] text-muted-foreground"
        >
            <Loader2 v-if="loadingMore" class="size-3.5 animate-spin" />
            <span v-else-if="hasMore">Scroll for more</span>
            <span v-else class="opacity-60"
                >{{ issues.length }} issue{{
                    issues.length === 1 ? '' : 's'
                }}</span
            >
        </div>
    </div>
</template>
