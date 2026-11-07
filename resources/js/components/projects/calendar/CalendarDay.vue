<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import CalendarChip from '@/components/projects/calendar/CalendarChip.vue';
import IssueContextMenu from '@/components/projects/list/IssueContextMenu.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { parseISO } from '@/lib/projects/calendarLayout';
import { cn } from '@/lib/utils';
import type { CalendarDay as Day } from '@/lib/projects/calendarLayout';
import type { IssueListItem, IssueLookups, WorkflowStatus } from '@/types';

/** One cell of the grid: the day number, up to `max` chips, and a "+n" popover for the rest. */
const props = withDefaults(
    defineProps<{
        day: Day;
        issues: IssueListItem[];
        lookups: IssueLookups | null;
        workflow: WorkflowStatus[];
        focusTeamId?: number | null;
        /** Vertical space reserved for spanning bars in this row. */
        barsOffset: number;
        max: number;
        mode: 'month' | 'week';
        hover: boolean;
        canEdit: boolean;
        draggingUuid: string | null;
        showMonth: boolean;
    }>(),
    { focusTeamId: null },
);

const emit = defineEmits<{
    open: [issue: IssueListItem];
    create: [date: string];
    dragstart: [event: PointerEvent, issue: IssueListItem];
}>();

const visible = computed(() => props.issues.slice(0, props.max));
const overflow = computed(() => props.issues.slice(props.max));
const label = computed(() => {
    const d = parseISO(props.day.date);

    return props.showMonth || d.getDate() === 1
        ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
        : String(d.getDate());
});
</script>

<template>
    <div
        :data-date="day.date"
        :class="
            cn(
                'group/day relative flex min-w-0 flex-col border-r border-b px-1 pb-1 transition-colors last:border-r-0',
                mode === 'month' ? 'min-h-[6.5rem]' : 'min-h-[24rem]',
                !day.inMonth &&
                    mode === 'month' &&
                    'bg-surface-sunken/60 text-muted-foreground',
                day.isWeekend && 'bg-surface/70',
                hover && 'bg-brand-soft/40 ring-2 ring-brand/40 ring-inset',
            )
        "
    >
        <div class="flex h-7 items-center justify-between px-0.5">
            <span
                :class="
                    cn(
                        'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[11.5px] tabular-nums',
                        day.isToday
                            ? 'bg-brand font-semibold text-brand-foreground'
                            : 'text-muted-foreground',
                    )
                "
                >{{ label }}</span
            >
            <button
                v-if="canEdit"
                type="button"
                class="rounded-md p-0.5 text-muted-foreground/0 transition-colors group-hover/day:text-muted-foreground/70 hover:bg-accent hover:text-foreground"
                :aria-label="`Create issue due ${day.date}`"
                @click="emit('create', day.date)"
            >
                <Plus class="size-3.5" />
            </button>
        </div>

        <div :style="{ height: `${barsOffset}px` }" aria-hidden="true" />

        <div class="flex flex-col gap-0.5">
            <IssueContextMenu
                v-for="issue in visible"
                :key="issue.uuid"
                :issue="issue"
                :lookups="lookups"
                :workflow="workflow"
                :focus-team-id="focusTeamId"
                @open="emit('open', $event)"
            >
                <CalendarChip
                    :issue="issue"
                    :focus-team-id="focusTeamId"
                    :dragging="draggingUuid === issue.uuid"
                    :compact="mode === 'month'"
                    :can-edit="canEdit"
                    @open="emit('open', $event)"
                    @dragstart="(e, i) => emit('dragstart', e, i)"
                />
            </IssueContextMenu>

            <Popover v-if="overflow.length > 0">
                <PopoverTrigger as-child>
                    <button
                        type="button"
                        class="h-5 rounded-md px-1.5 text-left text-[11px] font-medium text-muted-foreground hover:bg-accent hover:text-foreground"
                    >
                        +{{ overflow.length }} more
                    </button>
                </PopoverTrigger>
                <PopoverContent class="w-72 p-1.5" align="start">
                    <p
                        class="px-1.5 pb-1 text-[10.5px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{
                            parseISO(day.date).toLocaleDateString(undefined, {
                                weekday: 'long',
                                month: 'long',
                                day: 'numeric',
                            })
                        }}
                    </p>
                    <div class="flex flex-col gap-0.5">
                        <CalendarChip
                            v-for="issue in issues"
                            :key="issue.uuid"
                            :issue="issue"
                            :focus-team-id="focusTeamId"
                            :can-edit="canEdit"
                            @open="emit('open', $event)"
                            @dragstart="(e, i) => emit('dragstart', e, i)"
                        />
                    </div>
                </PopoverContent>
            </Popover>
        </div>
    </div>
</template>
