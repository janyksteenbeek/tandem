<script setup lang="ts">
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type { IssueListItem } from '@/types';

/**
 * A bar spanning several days in one week row. Resize handles appear in week mode; the whole bar
 * drags to move (the length is preserved).
 */
withDefaults(
    defineProps<{
        issue: IssueListItem;
        startCol: number;
        endCol: number;
        lane: number;
        continuesBefore: boolean;
        continuesAfter: boolean;
        laneHeight: number;
        top: number;
        resizable?: boolean;
        dragging?: boolean;
        canEdit?: boolean;
        focusTeamId?: number | null;
    }>(),
    { resizable: false, dragging: false, canEdit: true, focusTeamId: null },
);

const emit = defineEmits<{
    open: [issue: IssueListItem];
    dragstart: [
        event: PointerEvent,
        issue: IssueListItem,
        kind: 'move' | 'resize-start' | 'resize-end',
    ];
}>();
</script>

<template>
    <div
        :data-uuid="issue.uuid"
        role="button"
        tabindex="0"
        :aria-label="`${issue.identifier} ${issue.title}, ${issue.start_date} to ${issue.due_date}`"
        :class="
            cn(
                'group/bar absolute flex h-[22px] items-center gap-1 border bg-card pr-1.5 pl-1.5 text-[11.5px] shadow-pane transition-[box-shadow,opacity] select-none hover:z-10 hover:shadow-float',
                chipClass(
                    issue.project?.color ?? issue.teams[0]?.color ?? 'violet',
                ),
                continuesBefore ? 'rounded-l-none border-l-0' : 'rounded-l-md',
                continuesAfter ? 'rounded-r-none border-r-0' : 'rounded-r-md',
                canEdit
                    ? 'cursor-grab active:cursor-grabbing'
                    : 'cursor-pointer',
                dragging && 'opacity-40',
            )
        "
        :style="{
            left: `calc(${(startCol / 7) * 100}% + 2px)`,
            width: `calc(${((endCol - startCol + 1) / 7) * 100}% - 4px)`,
            top: `${top + lane * laneHeight}px`,
            borderLeftColor: continuesBefore ? undefined : 'var(--chip)',
            borderLeftWidth: continuesBefore ? undefined : '3px',
        }"
        @pointerdown.self="canEdit && emit('dragstart', $event, issue, 'move')"
        @click="emit('open', issue)"
        @keydown.enter.prevent="emit('open', issue)"
    >
        <span
            v-if="resizable && canEdit && !continuesBefore"
            class="absolute inset-y-0 -left-1 w-2 cursor-ew-resize"
            aria-hidden="true"
            @pointerdown.stop="emit('dragstart', $event, issue, 'resize-start')"
        />
        <StatusIcon
            :status="
                focusTeamId
                    ? (issue.workstream?.status ?? issue.status)
                    : issue.status
            "
            :size="12"
            class="pointer-events-none"
        />
        <span
            class="pointer-events-none shrink-0 font-mono text-[10.5px] text-muted-foreground"
            >{{ issue.identifier }}</span
        >
        <span class="pointer-events-none min-w-0 flex-1 truncate">{{
            issue.title
        }}</span>
        <span
            v-if="resizable && canEdit && !continuesAfter"
            class="absolute inset-y-0 -right-1 w-2 cursor-ew-resize"
            aria-hidden="true"
            @pointerdown.stop="emit('dragstart', $event, issue, 'resize-end')"
        />
    </div>
</template>
