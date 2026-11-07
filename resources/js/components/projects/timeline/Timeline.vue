<script setup lang="ts">
import { useVirtualizer } from '@tanstack/vue-virtual';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import DependencyArrows from '@/components/projects/timeline/DependencyArrows.vue';
import TimelineBar from '@/components/projects/timeline/TimelineBar.vue';
import TimelineHeader from '@/components/projects/timeline/TimelineHeader.vue';
import TimelineRow from '@/components/projects/timeline/TimelineRow.vue';
import TimelineToolbar from '@/components/projects/timeline/TimelineToolbar.vue';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    ResizableHandle,
    ResizablePanel,
    ResizablePanelGroup,
} from '@/components/ui/resizable';
import { useDragMove } from '@/composables/projects/useDragMove';
import { useSidebarPreferences } from '@/composables/projects/useSidebarPreferences';
import { useKeyboard } from '@/composables/useKeyboard';
import { PRIORITY } from '@/lib/keyboard';
import { dayDiff, shiftDate, todayISO } from '@/lib/projects/calendarLayout';
import {
    buildScale,
    clampRange,
    zoomIn,
    zoomOut,
} from '@/lib/projects/timelineScale';
import type {
    ArrowEdge,
    TimelineRow as Row,
    TimelineZoom,
} from '@/lib/projects/timelineScale';
import { descendantIds, flattenRows } from '@/lib/projects/timelineRows';
import { parseDate } from '@internationalized/date';
import { cn } from '@/lib/utils';

/**
 * Gantt-style timeline: a resizable tree on the left and a day-scaled canvas on the right. Bars
 * move and resize by dragging (snapped to days); the parent persists through `shift`.
 */
const props = withDefaults(
    defineProps<{
        rows: Row[];
        today?: string;
        zoom?: TimelineZoom;
        canEdit?: boolean;
        containerKey: string;
        dense?: boolean;
    }>(),
    { today: () => todayISO(), zoom: 'week', canEdit: true, dense: false },
);

const emit = defineEmits<{
    shift: [
        payload: {
            id: string;
            kind: Row['kind'];
            start: string | null;
            end: string | null;
            row: Row;
        },
    ];
    open: [row: Row];
    toggle: [id: string];
    'update:zoom': [zoom: TimelineZoom];
}>();

const ROW = 36;
const prefs = useSidebarPreferences();
const zoom = ref<TimelineZoom>(props.zoom);
const collapsed = ref<Set<string>>(new Set());
const scroller = ref<HTMLElement | null>(null);
const leftScroller = ref<HTMLElement | null>(null);
const mounted = ref(false);
const datePickerFor = ref<string | null>(null);

onMounted(() => {
    mounted.value = true;
    const saved = prefs.displayFor(props.containerKey) as Record<
        string,
        unknown
    >;

    if (
        typeof saved.timelineZoom === 'string' &&
        ['day', 'week', 'month', 'quarter'].includes(saved.timelineZoom)
    ) {
        zoom.value = saved.timelineZoom as TimelineZoom;
    }

    collapsed.value = new Set(
        props.rows.filter((r) => r.collapsed).map((r) => r.id),
    );
    void nextTick(() => scrollToToday(false));
});

watch(
    () => props.zoom,
    (value) => (zoom.value = value),
);

const setZoom = (value: TimelineZoom) => {
    const centerDate = scroller.value
        ? scale.value.dateForX(
              scroller.value.scrollLeft + scroller.value.clientWidth / 2,
          )
        : props.today;
    zoom.value = value;
    prefs.rememberDisplay(props.containerKey, { timelineZoom: value } as never);
    emit('update:zoom', value);
    void nextTick(() => scrollTo(centerDate, false));
};

/* --- rows & scale ---------------------------------------------------------------------------- */

const flat = computed(() => flattenRows(props.rows, collapsed.value));
const range = computed(() => clampRange(props.rows, props.today, zoom.value));
const scale = computed(() =>
    buildScale(range.value.from, range.value.to, zoom.value),
);
const todayX = computed(() =>
    props.today >= scale.value.from && props.today <= scale.value.to
        ? scale.value.xForDate(props.today) + scale.value.dayWidth / 2
        : null,
);
const dated = computed(() => flat.value.filter((r) => r.start || r.end).length);

const toggle = (id: string) => {
    const next = new Set(collapsed.value);
    next.has(id) ? next.delete(id) : next.add(id);
    collapsed.value = next;
    emit('toggle', id);
};

/* --- virtualisation (only for long lists) ------------------------------------------------------- */

const virtual = computed(() => mounted.value && flat.value.length > 200);
const virtualizer = useVirtualizer(
    computed(() => ({
        count: virtual.value ? flat.value.length : 0,
        getScrollElement: () => scroller.value,
        estimateSize: () => ROW,
        overscan: 10,
        getItemKey: (index: number) => flat.value[index]?.id ?? index,
    })),
);
const visibleRows = computed(() =>
    virtual.value
        ? virtualizer.value.getVirtualItems().map((item) => ({
              row: flat.value[item.index],
              index: item.index,
              y: item.start,
          }))
        : flat.value.map((row, index) => ({ row, index, y: index * ROW })),
);
const canvasHeight = computed(() =>
    virtual.value ? virtualizer.value.getTotalSize() : flat.value.length * ROW,
);

/* --- drag ------------------------------------------------------------------------------------- */

type DragPayload = {
    row: Row;
    kind: 'move' | 'resize-start' | 'resize-end';
    shift: boolean;
    start: string;
    end: string;
};
const preview = ref<Map<string, { start: string | null; end: string | null }>>(
    new Map(),
);

const effectiveDates = (
    row: Row,
): { start: string | null; end: string | null } =>
    preview.value.get(row.id) ?? { start: row.start, end: row.end };

const shiftedDates = (
    payload: DragPayload,
    days: number,
): { start: string; end: string } => {
    if (payload.kind === 'resize-start') {
        const start = shiftDate(payload.start, days);

        return {
            start: start > payload.end ? payload.end : start,
            end: payload.end,
        };
    }

    if (payload.kind === 'resize-end') {
        const end = shiftDate(payload.end, days);

        return {
            start: payload.start,
            end: end < payload.start ? payload.start : end,
        };
    }

    return {
        start: shiftDate(payload.start, days),
        end: shiftDate(payload.end, days),
    };
};

const drag = useDragMove<DragPayload>({
    onMove: (dx, _dy, payload) => {
        const days = Math.round(dx / scale.value.dayWidth);
        const next = new Map<
            string,
            { start: string | null; end: string | null }
        >();
        next.set(payload.row.id, shiftedDates(payload, days));

        if (payload.shift && payload.kind === 'move') {
            for (const child of descendants(payload.row)) {
                if (child.start || child.end) {
                    next.set(child.id, {
                        start: child.start
                            ? shiftDate(child.start, days)
                            : null,
                        end: child.end ? shiftDate(child.end, days) : null,
                    });
                }
            }
        }

        preview.value = next;
    },
    onEnd: (dx, _dy, payload) => {
        const days = Math.round(dx / scale.value.dayWidth);
        preview.value = new Map();

        if (days === 0) {
            return;
        }

        const dates = shiftedDates(payload, days);
        emit('shift', {
            id: payload.row.id,
            kind: payload.row.kind,
            start: dates.start,
            end: dates.end,
            row: payload.row,
        });

        if (payload.shift && payload.kind === 'move') {
            for (const child of descendants(payload.row)) {
                if (child.start || child.end) {
                    emit('shift', {
                        id: child.id,
                        kind: child.kind,
                        start: child.start
                            ? shiftDate(child.start, days)
                            : null,
                        end: child.end ? shiftDate(child.end, days) : null,
                        row: child,
                    });
                }
            }
        }
    },
    onCancel: () => (preview.value = new Map()),
});

const descendants = (row: Row): Row[] => {
    const ids = new Set(descendantIds(row));
    const found: Row[] = [];
    const walk = (list: Row[]) => {
        for (const r of list) {
            if (ids.has(r.id)) {
                found.push(r);
            }

            walk(r.children ?? []);
        }
    };
    walk(row.children ?? []);

    return found;
};

const startDrag = (
    event: PointerEvent,
    row: Row,
    kind: DragPayload['kind'],
) => {
    if (!props.canEdit) {
        return;
    }

    const start = row.start ?? row.end;
    const end = row.end ?? row.start;

    if (!start || !end) {
        return;
    }

    drag.start(event, { row, kind, shift: event.shiftKey, start, end });
};

const draggingId = computed(() =>
    drag.dragging.value ? (drag.payload.value?.row.id ?? null) : null,
);

const setDates = (row: Row, iso: string) => {
    datePickerFor.value = null;
    emit('shift', {
        id: row.id,
        kind: row.kind,
        start: iso,
        end:
            row.kind === 'milestone' || row.kind === 'release'
                ? iso
                : shiftDate(iso, 6),
        row,
    });
};

/* --- arrows ----------------------------------------------------------------------------------- */

const rowIndex = computed(() => new Map(flat.value.map((r, i) => [r.id, i])));

const edges = computed<ArrowEdge[]>(() => {
    const out: ArrowEdge[] = [];

    for (const row of flat.value) {
        const to = effectiveDates(row);

        if (!to.start) {
            continue;
        }

        for (const depId of row.dependsOn) {
            const fromIndex = rowIndex.value.get(depId);
            const fromRow = flat.value.find((r) => r.id === depId);

            if (fromIndex === undefined || !fromRow) {
                continue;
            }

            const from = effectiveDates(fromRow);
            const fromEnd = from.end ?? from.start;

            if (!fromEnd) {
                continue;
            }

            out.push({
                id: `${depId}->${row.id}`,
                fromX: scale.value.xForDate(fromEnd) + scale.value.dayWidth,
                fromY: fromIndex * ROW + ROW / 2,
                toX: scale.value.xForDate(to.start),
                toY: (rowIndex.value.get(row.id) ?? 0) * ROW + ROW / 2,
                late: to.start < fromEnd,
            });
        }
    }

    return out;
});

/* --- scrolling -------------------------------------------------------------------------------- */

const scrollTo = (iso: string, smooth = true) => {
    const el = scroller.value;

    if (!el) {
        return;
    }

    const x = Math.max(0, scale.value.xForDate(iso) - el.clientWidth / 3);
    el.scrollTo({ left: x, behavior: smooth ? 'smooth' : 'auto' });
};

const scrollToToday = (smooth = true) => scrollTo(props.today, smooth);

const syncLeft = () => {
    if (leftScroller.value && scroller.value) {
        leftScroller.value.scrollTop = scroller.value.scrollTop;
    }
};

useKeyboard(
    () => [
        {
            keys: '-',
            description: 'Zoom out',
            group: 'Timeline',
            handler: () => setZoom(zoomOut(zoom.value)),
        },
        {
            keys: '=',
            description: 'Zoom in',
            group: 'Timeline',
            handler: () => setZoom(zoomIn(zoom.value)),
        },
        {
            keys: 't',
            description: 'Scroll to today',
            group: 'Timeline',
            handler: () => scrollToToday(),
        },
    ],
    { scope: 'Timeline', priority: PRIORITY.page },
);

const weekendBands = computed(() => {
    if (zoom.value === 'quarter' || zoom.value === 'month') {
        return [] as { x: number; width: number }[];
    }

    const bands: { x: number; width: number }[] = [];

    for (let i = 0; i < scale.value.days; i++) {
        const date = shiftDate(scale.value.from, i);
        const day = new Date(date + 'T00:00:00').getDay();

        if (day === 6) {
            bands.push({
                x: i * scale.value.dayWidth,
                width: scale.value.dayWidth * 2,
            });
        }
    }

    return bands;
});

const gridBackground = computed(() =>
    scale.value.gridStep > 0
        ? `repeating-linear-gradient(to right, color-mix(in oklch, var(--border) 70%, transparent) 0 1px, transparent 1px ${scale.value.gridStep}px)`
        : `repeating-linear-gradient(to right, color-mix(in oklch, var(--border) 70%, transparent) 0 1px, transparent 1px ${scale.value.dayWidth * 7}px)`,
);

defineExpose({ scrollToToday, scrollTo });
void dayDiff;
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <TimelineToolbar
            :zoom="zoom"
            :rows="flat.length"
            :dated="dated"
            @update:zoom="setZoom"
            @zoom-in="setZoom(zoomIn(zoom))"
            @zoom-out="setZoom(zoomOut(zoom))"
            @today="scrollToToday()"
        >
            <slot name="toolbar" />
        </TimelineToolbar>

        <ResizablePanelGroup
            direction="horizontal"
            class="min-h-0 flex-1"
            auto-save-id="tandem-timeline-panes"
        >
            <ResizablePanel :default-size="26" :min-size="16" :max-size="50">
                <div class="flex h-full flex-col">
                    <div
                        class="h-8 shrink-0 border-b px-3 text-[10.5px] leading-8 font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        Work
                    </div>
                    <div
                        ref="leftScroller"
                        class="relative min-h-0 flex-1 overflow-hidden"
                    >
                        <div
                            :style="{
                                height: `${canvasHeight}px`,
                                position: 'relative',
                            }"
                        >
                            <div
                                v-for="item in visibleRows"
                                :key="item.row.id"
                                class="absolute top-0 left-0 w-full"
                                :style="{
                                    transform: `translateY(${item.y}px)`,
                                }"
                            >
                                <TimelineRow
                                    :row="item.row"
                                    :collapsed="collapsed.has(item.row.id)"
                                    @toggle="toggle"
                                    @open="emit('open', $event)"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </ResizablePanel>
            <ResizableHandle />
            <ResizablePanel :default-size="74">
                <div
                    ref="scroller"
                    class="relative scrollbar-thin h-full overflow-auto"
                    @scroll.passive="syncLeft"
                >
                    <TimelineHeader :scale="scale" :today-x="todayX" />
                    <div
                        class="relative"
                        :style="{
                            width: `${scale.totalWidth}px`,
                            height: `${canvasHeight}px`,
                            backgroundImage: gridBackground,
                        }"
                    >
                        <div
                            v-for="band in weekendBands"
                            :key="band.x"
                            class="absolute top-0 h-full bg-surface-sunken/60"
                            :style="{
                                left: `${band.x}px`,
                                width: `${band.width}px`,
                            }"
                            aria-hidden="true"
                        />
                        <div
                            v-if="todayX !== null"
                            class="absolute top-0 h-full w-px bg-brand/70"
                            :style="{ left: `${todayX}px` }"
                            aria-hidden="true"
                        />

                        <DependencyArrows
                            :edges="edges"
                            :width="scale.totalWidth"
                            :height="canvasHeight"
                        />

                        <div
                            v-for="item in visibleRows"
                            :key="item.row.id"
                            :class="
                                cn(
                                    'absolute left-0 w-full border-b border-border/40',
                                    draggingId === item.row.id && 'z-20',
                                )
                            "
                            :style="{
                                transform: `translateY(${item.y}px)`,
                                height: `${ROW}px`,
                            }"
                        >
                            <Popover
                                :open="datePickerFor === item.row.id"
                                @update:open="
                                    (v) => !v && (datePickerFor = null)
                                "
                            >
                                <PopoverTrigger as-child>
                                    <span
                                        class="absolute top-0 left-0 h-px w-px"
                                        aria-hidden="true"
                                    />
                                </PopoverTrigger>
                                <PopoverContent
                                    class="w-auto p-2"
                                    align="start"
                                >
                                    <p
                                        class="mb-1 px-1 text-[11px] text-muted-foreground"
                                    >
                                        Pick a start date for
                                        {{ item.row.label }}
                                    </p>
                                    <Calendar
                                        :model-value="
                                            item.row.start
                                                ? parseDate(item.row.start)
                                                : undefined
                                        "
                                        @update:model-value="
                                            (v) =>
                                                v &&
                                                setDates(item.row, v.toString())
                                        "
                                    />
                                </PopoverContent>
                            </Popover>
                            <TimelineBar
                                :row="item.row"
                                :scale="scale"
                                :start="effectiveDates(item.row).start"
                                :end="effectiveDates(item.row).end"
                                :can-edit="canEdit"
                                :dragging="draggingId === item.row.id"
                                @dragstart="startDrag"
                                @open="emit('open', $event)"
                                @set-dates="(row) => (datePickerFor = row.id)"
                            />
                        </div>
                    </div>
                </div>
            </ResizablePanel>
        </ResizablePanelGroup>
    </div>
</template>
