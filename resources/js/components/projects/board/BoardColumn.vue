<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useSortable } from '@vueuse/integrations/useSortable';
import { ChevronsLeftRight, Loader2, Plus } from '@lucide/vue';
import { computed, inject, nextTick, ref, watch } from 'vue';
import BoardCard from '@/components/projects/board/BoardCard.vue';
import IssueContextMenu from '@/components/projects/list/IssueContextMenu.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import { between } from '@/lib/projects/positioning';
import { cn } from '@/lib/utils';
import type {
    CreateIssuePrefill,
    IssueListItem,
    IssueLookups,
    WorkflowStatus,
} from '@/types';

/**
 * One board column. Keeps its own ordered copy of the issues so Sortable can move DOM nodes
 * without fighting Vue; the parent is told about moves and owns the real state.
 */
const props = withDefaults(
    defineProps<{
        status: WorkflowStatus;
        issues: IssueListItem[];
        total: number;
        nextCursor: string | null;
        lookups: IssueLookups | null;
        workflow: WorkflowStatus[];
        focusTeamId?: number | null;
        collapsed: boolean;
        canMove: boolean;
        loadingMore?: boolean;
        prefill?: CreateIssuePrefill;
    }>(),
    { focusTeamId: null, loadingMore: false },
);

const emit = defineEmits<{
    open: [issue: IssueListItem];
    move: [
        payload: {
            uuid: string;
            fromStatusId: number;
            toStatusId: number;
            position: number;
            rebalance: boolean;
        },
    ];
    toggle: [];
    loadMore: [];
}>();

const openCreateIssue = inject<(prefill?: CreateIssuePrefill) => void>(
    'openCreateIssue',
    () => {},
);
const listEl = ref<HTMLElement | null>(null);
const local = ref<IssueListItem[]>([...props.issues]);
const dragging = ref<string | null>(null);

watch(
    () => props.issues,
    (value) => {
        if (!dragging.value) {
            local.value = [...value];
        }
    },
);

useSortable(listEl, local, {
    group: 'issue-board',
    animation: 150,
    ghostClass: 'opacity-40',
    dragClass: 'shadow-float',
    disabled: !props.canMove,
    watchElement: true,
    onStart: (event) => {
        dragging.value = (event.item as HTMLElement).dataset.uuid ?? null;
    },
    onEnd: () => {
        dragging.value = null;
    },
    onAdd: (event) => {
        // A card dropped here from another column: Sortable moved the node; tell the parent.
        const uuid = (event.item as HTMLElement).dataset.uuid;
        const from = Number((event.from as HTMLElement).dataset.statusId);

        if (!uuid) {
            return;
        }

        const index = event.newIndex ?? local.value.length;
        const prev = local.value[index - 1]?.sort_order ?? null;
        const next = local.value[index]?.sort_order ?? null;
        const { position, rebalance } = between(prev, next);

        // Undo Sortable's DOM move; Vue re-renders from the store once the parent updates.
        void nextTick(() => {
            event.item.remove();
            emit('move', {
                uuid,
                fromStatusId: from,
                toStatusId: props.status.id,
                position,
                rebalance,
            });
        });
    },
    onUpdate: (event) => {
        const uuid = (event.item as HTMLElement).dataset.uuid;

        if (
            !uuid ||
            event.newIndex === undefined ||
            event.oldIndex === undefined
        ) {
            return;
        }

        const ordered = [...local.value];
        const [moved] = ordered.splice(event.oldIndex, 1);
        ordered.splice(event.newIndex, 0, moved);
        const prev = ordered[event.newIndex - 1]?.sort_order ?? null;
        const next = ordered[event.newIndex + 1]?.sort_order ?? null;
        const { position, rebalance } = between(prev, next);
        local.value = ordered;
        emit('move', {
            uuid,
            fromStatusId: props.status.id,
            toStatusId: props.status.id,
            position,
            rebalance,
        });
    },
});

const create = () =>
    openCreateIssue({
        ...props.prefill,
        ...(props.focusTeamId ? {} : { status_id: props.status.id }),
    });
const terminal = computed(
    () =>
        props.status.category === 'completed' ||
        props.status.category === 'canceled',
);
</script>

<template>
    <section
        :data-status-id="status.id"
        :class="
            cn(
                'flex shrink-0 flex-col rounded-xl bg-surface-sunken/60 transition-[width]',
                collapsed ? 'w-10' : 'w-72',
            )
        "
        :aria-label="status.name"
    >
        <header
            v-if="collapsed"
            class="flex flex-1 flex-col items-center gap-2 py-3"
        >
            <button
                type="button"
                class="rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                aria-label="Expand column"
                @click="emit('toggle')"
            >
                <ChevronsLeftRight class="size-3.5" />
            </button>
            <StatusIcon :status="status" :size="14" />
            <span
                class="text-[11px] font-medium text-muted-foreground tabular-nums"
                >{{ total }}</span
            >
            <span
                class="text-[11.5px] font-medium [writing-mode:vertical-rl]"
                >{{ status.name }}</span
            >
        </header>
        <template v-else>
            <header class="group/col flex h-9 items-center gap-2 px-3">
                <StatusIcon :status="status" :size="14" />
                <span class="truncate text-[12.5px] font-semibold">{{
                    status.name
                }}</span>
                <span
                    class="text-[11.5px] text-muted-foreground tabular-nums"
                    >{{ total }}</span
                >
                <span
                    class="ml-auto flex items-center gap-0.5 opacity-0 transition-opacity group-hover/col:opacity-100"
                >
                    <button
                        type="button"
                        class="rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                        aria-label="Create issue in this column"
                        @click="create"
                    >
                        <Plus class="size-3.5" />
                    </button>
                    <button
                        type="button"
                        class="rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                        aria-label="Collapse column"
                        @click="emit('toggle')"
                    >
                        <ChevronsLeftRight class="size-3.5" />
                    </button>
                </span>
            </header>
            <div
                ref="listEl"
                :data-status-id="status.id"
                class="flex scrollbar-thin min-h-16 flex-1 flex-col gap-1.5 overflow-y-auto px-2 pb-2"
                role="list"
            >
                <IssueContextMenu
                    v-for="issue in local"
                    :key="issue.uuid"
                    :issue="issue"
                    :lookups="lookups"
                    :workflow="workflow"
                    :focus-team-id="focusTeamId"
                    @open="emit('open', $event)"
                >
                    <div
                        role="listitem"
                        :data-uuid="issue.uuid"
                        :class="
                            cn(
                                'cursor-grab active:cursor-grabbing',
                                terminal && 'opacity-70',
                            )
                        "
                    >
                        <BoardCard
                            :issue="issue"
                            :focus-team-id="focusTeamId"
                            :dragging="dragging === issue.uuid"
                            @open="emit('open', $event)"
                        />
                    </div>
                </IssueContextMenu>
                <button
                    v-if="nextCursor"
                    type="button"
                    class="flex h-8 items-center justify-center rounded-md text-[11.5px] text-muted-foreground hover:bg-accent"
                    :disabled="loadingMore"
                    @click="emit('loadMore')"
                >
                    <Loader2 v-if="loadingMore" class="size-3.5 animate-spin" />
                    <span v-else>Show more ({{ total - local.length }})</span>
                </button>
                <p
                    v-else-if="local.length === 0"
                    class="rounded-lg border border-dashed px-3 py-4 text-center text-[11.5px] text-muted-foreground/70"
                >
                    Drop an issue here
                </p>
            </div>
        </template>
    </section>
</template>
