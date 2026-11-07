<script setup lang="ts">
import { ChevronRight, Plus } from '@lucide/vue';
import { inject } from 'vue';
import PriorityIcon from '@/components/projects/shared/PriorityIcon.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import type { IssueGroup } from '@/lib/projects/issueGrouping';
import { cn } from '@/lib/utils';
import type { CreateIssuePrefill, IssueGroupBy, IssuePriority } from '@/types';

/**
 * Sticky header of one group in the list: a glyph for the group, its label, the count and a
 * "+" that creates an issue with the group's value prefilled.
 */
const props = defineProps<{
    group: IssueGroup;
    groupBy: IssueGroupBy;
    collapsed: boolean;
    focusTeamId?: number | null;
    prefill?: CreateIssuePrefill;
}>();
const emit = defineEmits<{ toggle: [] }>();

const openCreateIssue = inject<(prefill?: CreateIssuePrefill) => void>(
    'openCreateIssue',
    () => {},
);

const create = () => {
    const prefill: CreateIssuePrefill = { ...props.prefill };

    switch (props.groupBy) {
        case 'status':
            if (props.group.status && !props.focusTeamId) {
                prefill.status_id = props.group.status.id;
            }
            break;
        case 'priority':
            prefill.priority = props.group.key as IssuePriority;
            break;
        case 'team':
            if (props.group.key !== '__none__') {
                prefill.team_ids = [Number(props.group.key)];
            }
            break;
        case 'project':
            if (props.group.key !== '__none__') {
                prefill.project_id = Number(props.group.key);
            }
            break;
        case 'product':
            if (props.group.key !== '__none__') {
                prefill.product_id = Number(props.group.key);
            }
            break;
        case 'owner':
            if (props.group.key !== '__none__') {
                prefill.owner_id = Number(props.group.key);
            }
            break;
        case 'assignee':
            if (props.group.key !== '__none__') {
                prefill.assignee_ids = [Number(props.group.key)];
            }
            break;
        case 'label':
            if (props.group.key !== '__none__') {
                prefill.label_ids = [Number(props.group.key)];
            }
            break;
    }

    openCreateIssue(prefill);
};
</script>

<template>
    <div
        class="group/header sticky top-0 z-10 flex h-8 items-center gap-2 bg-background/95 px-2 backdrop-blur-sm"
    >
        <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-2 text-left"
            :aria-expanded="!collapsed"
            @click="emit('toggle')"
        >
            <ChevronRight
                :class="
                    cn(
                        'size-3.5 shrink-0 text-muted-foreground/60 transition-transform',
                        !collapsed && 'rotate-90',
                    )
                "
            />
            <StatusIcon
                v-if="groupBy === 'status' && group.status"
                :status="group.status"
                :size="14"
            />
            <PriorityIcon
                v-else-if="groupBy === 'priority'"
                :priority="group.key as IssuePriority"
            />
            <InboxDot v-else-if="group.color" :color="group.color" size="sm" />
            <span
                class="truncate text-[12.5px] font-semibold text-foreground"
                >{{ group.label }}</span
            >
            <span class="text-[11.5px] text-muted-foreground tabular-nums">{{
                group.issues.length
            }}</span>
        </button>
        <button
            type="button"
            class="flex size-6 items-center justify-center rounded-md text-muted-foreground/0 transition-colors group-hover/header:text-muted-foreground/70 hover:bg-accent hover:text-foreground"
            aria-label="Create issue in this group"
            @click="create"
        >
            <Plus class="size-3.5" />
        </button>
    </div>
</template>
