<script setup lang="ts">
import { OctagonAlert } from '@lucide/vue';
import AvatarStack from '@/components/projects/shared/AvatarStack.vue';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import { cn } from '@/lib/utils';
import type { IssueListItem } from '@/types';

/** One issue on one day. Draggable through the parent's pointerdown handler. */
withDefaults(
    defineProps<{
        issue: IssueListItem;
        focusTeamId?: number | null;
        dragging?: boolean;
        compact?: boolean;
        canEdit?: boolean;
    }>(),
    { focusTeamId: null, dragging: false, compact: false, canEdit: true },
);

const emit = defineEmits<{
    open: [issue: IssueListItem];
    dragstart: [event: PointerEvent, issue: IssueListItem];
}>();
</script>

<template>
    <div
        :data-uuid="issue.uuid"
        role="button"
        tabindex="0"
        :aria-label="`${issue.identifier} ${issue.title}`"
        :class="
            cn(
                'group/chip flex h-[22px] max-w-full items-center gap-1 rounded-md border border-transparent bg-card px-1.5 text-[11.5px] shadow-pane transition-[box-shadow,opacity] select-none hover:border-border hover:shadow-float',
                canEdit
                    ? 'cursor-grab active:cursor-grabbing'
                    : 'cursor-pointer',
                dragging && 'opacity-40',
                (issue.status_category === 'completed' ||
                    issue.status_category === 'canceled') &&
                    'text-muted-foreground',
            )
        "
        @pointerdown="canEdit && emit('dragstart', $event, issue)"
        @click="emit('open', issue)"
        @keydown.enter.prevent="emit('open', issue)"
    >
        <StatusIcon
            :status="
                focusTeamId
                    ? (issue.workstream?.status ?? issue.status)
                    : issue.status
            "
            :size="12"
        />
        <IssueIdentifier
            v-if="!compact"
            :identifier="issue.identifier"
            class="text-[10.5px]"
        />
        <span
            :class="
                cn(
                    'min-w-0 flex-1 truncate',
                    issue.status_category === 'completed' && 'line-through',
                )
            "
            >{{ issue.title }}</span
        >
        <OctagonAlert
            v-if="
                issue.counts.blocked_by > 0 ||
                issue.counts.blocked_workstreams > 0
            "
            class="size-3 shrink-0 text-destructive"
        />
        <AvatarStack
            v-if="!compact && issue.assignees.length > 0"
            :users="issue.assignees"
            :max="1"
            size="xs"
            class="shrink-0"
        />
    </div>
</template>
