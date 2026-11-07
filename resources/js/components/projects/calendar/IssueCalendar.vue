<script setup lang="ts">
import { ChevronRight, Inbox } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import CalendarChip from '@/components/projects/calendar/CalendarChip.vue';
import CalendarDay from '@/components/projects/calendar/CalendarDay.vue';
import CalendarRangeBar from '@/components/projects/calendar/CalendarRangeBar.vue';
import CalendarToolbar from '@/components/projects/calendar/CalendarToolbar.vue';
import IssueContextMenu from '@/components/projects/list/IssueContextMenu.vue';
import Collapse from '@/components/Collapse.vue';
import {
    dateUnderPointer,
    useDragMove,
} from '@/composables/projects/useDragMove';
import { useSidebarPreferences } from '@/composables/projects/useSidebarPreferences';
import { useKeyboard } from '@/composables/useKeyboard';
import { PRIORITY } from '@/lib/keyboard';
import {
    dayDiff,
    dayLabel,
    laneAssign,
    monthGrid,
    monthLabel,
    shiftDate,
    shiftMonth,
    startOfMonth,
    startOfWeek,
    todayISO,
    weekDays,
    weekdayNames,
} from '@/lib/projects/calendarLayout';
import type {
    CalendarDay as Day,
    CalendarField,
    CalendarMode,
} from '@/lib/projects/calendarLayout';
import { cn } from '@/lib/utils';
import type { IssueListItem, IssueLookups, WorkflowStatus } from '@/types';

/**
 * Month and week calendar over the current issue list. Chips (one date) and bars (start→due)
 * drag onto other days; the parent persists through `reschedule`.
 */
const props = withDefaults(
    defineProps<{
        issues: IssueListItem[];
        lookups: IssueLookups | null;
        workflow: WorkflowStatus[];
        field?: CalendarField;
        focusTeamId?: number | null;
        containerKey: string;
        canEdit?: boolean;
    }>(),
    { field: 'due_date', focusTeamId: null, canEdit: true },
);

const emit = defineEmits<{
    open: [issue: IssueListItem];
    reschedule: [
        payload: {
            issue: IssueListItem;
            start_date: string | null;
            due_date: string | null;
        },
    ];
    create: [date: string];
}>();

const prefs = useSidebarPreferences();
const today = todayISO();
const anchor = ref(today);
const mode = ref<CalendarMode>('month');
const field = ref<CalendarField>(props.field);
const trayOpen = ref(false);
const hoverDate = ref<string | null>(null);
const LANE = 24;
const MAX_CHIPS = 3;

onMounted(() => {
    const saved = prefs.displayFor(props.containerKey) as Record<
        string,
        unknown
    >;

    if (saved.calendarMode === 'month' || saved.calendarMode === 'week') {
        mode.value = saved.calendarMode;
    }

    if (
        saved.calendarField === 'due_date' ||
        saved.calendarField === 'start_date' ||
        saved.calendarField === 'range'
    ) {
        field.value = saved.calendarField;
    }
});

const remember = () =>
    prefs.rememberDisplay(props.containerKey, {
        calendarMode: mode.value,
        calendarField: field.value,
    } as never);

const setMode = (value: CalendarMode) => {
    mode.value = value;
    remember();
};

const setField = (value: CalendarField) => {
    field.value = value;
    remember();
};

/** Rows of days: six weeks or one. */
const weeks = computed<Day[][]>(() =>
    mode.value === 'month'
        ? monthGrid(anchor.value, 1, today)
        : [weekDays(anchor.value, 1, today)],
);
const names = weekdayNames(1);

const title = computed(() => {
    if (mode.value === 'month') {
        return monthLabel(anchor.value);
    }

    const start = startOfWeek(anchor.value, 1);

    return `${dayLabel(start)} – ${dayLabel(shiftDate(start, 6), { month: 'short', day: 'numeric', year: 'numeric' })}`;
});

/** Items that render as bars (both dates, in span mode) and the ones that render as chips. */
const spans = computed(() =>
    field.value === 'range'
        ? props.issues.filter(
              (i) => i.start_date && i.due_date && i.start_date !== i.due_date,
          )
        : [],
);
const dateOf = (issue: IssueListItem): string | null => {
    if (field.value === 'start_date') {
        return issue.start_date;
    }

    if (field.value === 'due_date') {
        return issue.due_date;
    }

    return issue.due_date ?? issue.start_date;
};

const chipsByDate = computed(() => {
    const map = new Map<string, IssueListItem[]>();
    const spanned = new Set(spans.value.map((i) => i.uuid));

    for (const issue of props.issues) {
        if (spanned.has(issue.uuid)) {
            continue;
        }

        const date = dateOf(issue);

        if (date) {
            map.set(date, [...(map.get(date) ?? []), issue]);
        }
    }

    for (const list of map.values()) {
        list.sort((a, b) =>
            a.priority === b.priority ? a.number - b.number : 0,
        );
    }

    return map;
});

const unscheduled = computed(() => props.issues.filter((i) => !dateOf(i)));

/** Bars laid out per week row with lanes. */
const weekBars = computed(() =>
    weeks.value.map((week) => {
        const { bars, lanes } = laneAssign(
            spans.value.map((i) => ({
                id: i.uuid,
                start: i.start_date as string,
                end: i.due_date as string,
            })),
            week.map((d) => d.date),
        );

        return { bars, lanes, offset: lanes * LANE };
    }),
);

const byUuid = computed(() => new Map(props.issues.map((i) => [i.uuid, i])));

/* --- navigation ---------------------------------------------------------------------------- */

const prev = () =>
    (anchor.value =
        mode.value === 'month'
            ? shiftMonth(anchor.value, -1)
            : shiftDate(anchor.value, -7));
const next = () =>
    (anchor.value =
        mode.value === 'month'
            ? shiftMonth(anchor.value, 1)
            : shiftDate(anchor.value, 7));
const goToday = () => (anchor.value = today);

useKeyboard(
    () => [
        {
            keys: '[',
            description: 'Previous period',
            group: 'Calendar',
            handler: prev,
        },
        {
            keys: ']',
            description: 'Next period',
            group: 'Calendar',
            handler: next,
        },
        {
            keys: 't',
            description: 'Jump to today',
            group: 'Calendar',
            handler: goToday,
        },
    ],
    { scope: 'Calendar', priority: PRIORITY.page },
);

/* --- drag ------------------------------------------------------------------------------------ */

type DragPayload = {
    issue: IssueListItem;
    kind: 'move' | 'resize-start' | 'resize-end';
    from: 'grid' | 'tray';
};

const drag = useDragMove<DragPayload>({
    onMove: (_dx, _dy, _payload, event) => {
        hoverDate.value = dateUnderPointer(event);
    },
    onEnd: (_dx, _dy, payload, event) => {
        const target = dateUnderPointer(event);
        hoverDate.value = null;

        if (!target) {
            return;
        }

        const { issue, kind } = payload;

        if (kind === 'resize-start') {
            const due = issue.due_date ?? target;
            emit('reschedule', {
                issue,
                start_date: target > due ? due : target,
                due_date: due,
            });

            return;
        }

        if (kind === 'resize-end') {
            const start = issue.start_date ?? target;
            emit('reschedule', {
                issue,
                start_date: start,
                due_date: target < start ? start : target,
            });

            return;
        }

        if (field.value === 'range' && issue.start_date && issue.due_date) {
            const origin = issue.due_date;
            const delta = dayDiff(origin, target);

            if (delta === 0) {
                return;
            }

            emit('reschedule', {
                issue,
                start_date: shiftDate(issue.start_date, delta),
                due_date: shiftDate(issue.due_date, delta),
            });

            return;
        }

        if (field.value === 'start_date') {
            if (issue.start_date === target) {
                return;
            }

            const due =
                issue.due_date && issue.due_date < target
                    ? target
                    : issue.due_date;
            emit('reschedule', { issue, start_date: target, due_date: due });

            return;
        }

        if (issue.due_date === target) {
            return;
        }

        const start =
            issue.start_date && issue.start_date > target
                ? target
                : issue.start_date;
        emit('reschedule', { issue, start_date: start, due_date: target });
    },
    onCancel: () => (hoverDate.value = null),
});

const startDrag = (
    event: PointerEvent,
    issue: IssueListItem,
    kind: DragPayload['kind'] = 'move',
    from: DragPayload['from'] = 'grid',
) => {
    if (!props.canEdit) {
        return;
    }

    drag.start(event, { issue, kind, from });
};

const draggingUuid = computed(() =>
    drag.dragging.value ? (drag.payload.value?.issue.uuid ?? null) : null,
);
const scheduledCount = computed(
    () => props.issues.length - unscheduled.value.length,
);
const showMonthInLabel = computed(() => mode.value === 'week');
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <CalendarToolbar
            :title="title"
            :mode="mode"
            :field="field"
            :total="issues.length"
            :scheduled="scheduledCount"
            @prev="prev"
            @next="next"
            @today="goToday"
            @update:mode="setMode"
            @update:field="setField"
        />

        <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
            <div
                class="sticky top-0 z-10 grid grid-cols-7 border-b bg-background/95 backdrop-blur"
            >
                <div
                    v-for="name in names"
                    :key="name"
                    class="px-2 py-1.5 text-[10.5px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ name }}
                </div>
            </div>

            <div
                v-for="(week, w) in weeks"
                :key="week[0].date"
                class="relative grid grid-cols-7"
            >
                <CalendarDay
                    v-for="day in week"
                    :key="day.date"
                    :day="day"
                    :issues="chipsByDate.get(day.date) ?? []"
                    :lookups="lookups"
                    :workflow="workflow"
                    :focus-team-id="focusTeamId"
                    :bars-offset="weekBars[w].offset"
                    :max="mode === 'month' ? MAX_CHIPS : 40"
                    :mode="mode"
                    :hover="hoverDate === day.date"
                    :can-edit="canEdit"
                    :dragging-uuid="draggingUuid"
                    :show-month="showMonthInLabel"
                    @open="emit('open', $event)"
                    @create="emit('create', $event)"
                    @dragstart="(e, i) => startDrag(e, i)"
                />

                <template v-for="bar in weekBars[w].bars" :key="bar.id">
                    <IssueContextMenu
                        v-if="byUuid.get(bar.id)"
                        :issue="byUuid.get(bar.id)!"
                        :lookups="lookups"
                        :workflow="workflow"
                        :focus-team-id="focusTeamId"
                        @open="emit('open', $event)"
                    >
                        <CalendarRangeBar
                            :issue="byUuid.get(bar.id)!"
                            :start-col="bar.startCol"
                            :end-col="bar.endCol"
                            :lane="bar.lane"
                            :continues-before="bar.continuesBefore"
                            :continues-after="bar.continuesAfter"
                            :lane-height="LANE"
                            :top="30"
                            :resizable="mode === 'week'"
                            :dragging="draggingUuid === bar.id"
                            :can-edit="canEdit"
                            :focus-team-id="focusTeamId"
                            @open="emit('open', $event)"
                            @dragstart="(e, i, kind) => startDrag(e, i, kind)"
                        />
                    </IssueContextMenu>
                </template>
            </div>
        </div>

        <div v-if="unscheduled.length > 0" class="border-t bg-surface">
            <button
                type="button"
                class="flex w-full items-center gap-2 px-4 py-2 text-left text-[12px] font-medium text-muted-foreground hover:text-foreground"
                :aria-expanded="trayOpen"
                @click="trayOpen = !trayOpen"
            >
                <ChevronRight
                    :class="
                        cn(
                            'size-3.5 transition-transform',
                            trayOpen && 'rotate-90',
                        )
                    "
                />
                <Inbox class="size-3.5" />
                Unscheduled
                <span
                    class="rounded-full bg-muted px-1.5 text-[10.5px] tabular-nums"
                    >{{ unscheduled.length }}</span
                >
                <span
                    v-if="canEdit"
                    class="ml-auto text-[11px] font-normal text-muted-foreground/70"
                    >Drag onto a day to schedule</span
                >
            </button>
            <Collapse :open="trayOpen">
                <div
                    class="flex scrollbar-thin max-h-40 flex-wrap gap-1 overflow-y-auto px-4 pb-3"
                >
                    <IssueContextMenu
                        v-for="issue in unscheduled"
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
                            :can-edit="canEdit"
                            class="max-w-64"
                            @open="emit('open', $event)"
                            @dragstart="
                                (e, i) => startDrag(e, i, 'move', 'tray')
                            "
                        />
                    </IssueContextMenu>
                </div>
            </Collapse>
        </div>
    </div>
</template>
