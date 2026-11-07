<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import BoardColumn from '@/components/projects/board/BoardColumn.vue';
import { useSidebarPreferences } from '@/composables/projects/useSidebarPreferences';
import { issueStore } from '@/lib/projects/issueStore';
import { CATEGORY_ORDER } from '@/lib/projects/meta';
import type {
    BoardColumn as BoardColumnData,
    CreateIssuePrefill,
    IssueListItem,
    IssueLookups,
    WorkflowStatus,
} from '@/types';

/**
 * Kanban board. Columns hold uuids and read the issues from the shared store, so optimistic
 * and realtime changes show up without a refetch; a move patches the store and emits for the
 * parent to persist.
 */
const props = withDefaults(
    defineProps<{
        columns: BoardColumnData[];
        statuses: WorkflowStatus[];
        lookups: IssueLookups | null;
        focusTeamId?: number | null;
        containerKey: string;
        canMove?: boolean;
        prefill?: CreateIssuePrefill;
        propKey?: string;
    }>(),
    { focusTeamId: null, canMove: true, propKey: 'board' },
);

const emit = defineEmits<{
    open: [issue: IssueListItem];
    move: [
        payload: {
            issue: IssueListItem;
            status: WorkflowStatus;
            position: number;
            rebalance: boolean;
        },
    ];
}>();

const prefs = useSidebarPreferences();
const collapsed = ref<Set<number>>(new Set());
const loadingColumn = ref<number | null>(null);

onMounted(() => {
    collapsed.value = new Set(
        prefs.displayFor(props.containerKey).collapsedColumns ?? [],
    );
});

/** uuid lists per status, seeded from props and kept in sync with the store. */
const membership = ref<Map<number, string[]>>(new Map());
const totals = ref<Map<number, number>>(new Map());
const cursors = ref<Map<number, string | null>>(new Map());

watch(
    () => props.columns,
    (columns) => {
        const next = new Map<number, string[]>();

        for (const column of columns) {
            issueStore.ingest(column.issues);
            const existing = membership.value.get(column.status_id) ?? [];
            const incoming = column.issues.map((i) => i.uuid);
            // Append pages: keep what we already had (minus dupes), then the new ones.
            next.set(column.status_id, [
                ...existing.filter((u) => !incoming.includes(u)),
                ...incoming,
            ]);
            totals.value.set(column.status_id, column.total);
            cursors.value.set(column.status_id, column.next_cursor);
        }

        membership.value = next;
        loadingColumn.value = null;
    },
    { immediate: true },
);

const statusOf = (issue: IssueListItem): number =>
    props.focusTeamId
        ? (issue.workstream?.status.id ?? issue.status.id)
        : issue.status.id;

/** Realtime/optimistic: a status change moves the uuid between columns. */
issueStore.subscribe(({ type, uuid, issue }) => {
    const next = new Map(membership.value);

    for (const [statusId, list] of next) {
        if (list.includes(uuid)) {
            next.set(
                statusId,
                list.filter((u) => u !== uuid),
            );
        }
    }

    if (type === 'upsert' && issue) {
        const target = statusOf(issue as IssueListItem);

        if (next.has(target)) {
            next.set(target, [...(next.get(target) ?? []), uuid]);
        }
    }

    membership.value = next;
});

const ordered = computed(() =>
    [...props.statuses].sort(
        (a, b) =>
            CATEGORY_ORDER.indexOf(a.category) -
                CATEGORY_ORDER.indexOf(b.category) || a.position - b.position,
    ),
);

const issuesFor = (statusId: number): IssueListItem[] => {
    void issueStore.version.value;

    return (membership.value.get(statusId) ?? [])
        .map((uuid) => issueStore.get(uuid) as IssueListItem | undefined)
        .filter((i): i is IssueListItem => Boolean(i))
        .sort(
            (a, b) =>
                (a.sort_order ?? Number.MAX_SAFE_INTEGER) -
                    (b.sort_order ?? Number.MAX_SAFE_INTEGER) ||
                b.number - a.number,
        );
};

const toggle = (statusId: number) => {
    const next = new Set(collapsed.value);
    next.has(statusId) ? next.delete(statusId) : next.add(statusId);
    collapsed.value = next;
    prefs.rememberDisplay(props.containerKey, { collapsedColumns: [...next] });
};

const move = ({
    uuid,
    toStatusId,
    position,
    rebalance,
}: {
    uuid: string;
    fromStatusId: number;
    toStatusId: number;
    position: number;
    rebalance: boolean;
}) => {
    const issue = issueStore.get(uuid) as IssueListItem | undefined;
    const status = props.statuses.find((s) => s.id === toStatusId);

    if (!issue || !status) {
        return;
    }

    issueStore.patch(uuid, { sort_order: position });
    emit('move', { issue, status, position, rebalance });
};

const loadMore = (statusId: number) => {
    const cursor = cursors.value.get(statusId);

    if (!cursor) {
        return;
    }

    loadingColumn.value = statusId;
    router.reload({
        only: [props.propKey],
        data: { [`cursor_${statusId}`]: cursor },
    });
};
</script>

<template>
    <div
        class="flex scrollbar-thin min-h-0 flex-1 gap-3 overflow-x-auto px-4 pt-3 pb-4"
    >
        <BoardColumn
            v-for="status in ordered"
            :key="status.id"
            :status="status"
            :issues="issuesFor(status.id)"
            :total="
                Math.max(
                    totals.get(status.id) ?? 0,
                    issuesFor(status.id).length,
                )
            "
            :next-cursor="cursors.get(status.id) ?? null"
            :lookups="lookups"
            :workflow="statuses"
            :focus-team-id="focusTeamId"
            :collapsed="collapsed.has(status.id)"
            :can-move="canMove"
            :loading-more="loadingColumn === status.id"
            :prefill="prefill"
            @open="emit('open', $event)"
            @move="move"
            @toggle="toggle(status.id)"
            @load-more="loadMore(status.id)"
        />
    </div>
</template>
