<script setup lang="ts">
import { computed } from 'vue';
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type { TimelineRow, TimelineScale } from '@/lib/projects/timelineScale';
import { dayLabel } from '@/lib/projects/calendarLayout';

/**
 * A bar (or diamond) on one canvas row. `preview` holds the dates while dragging; the bar is
 * positioned from them so the drag feels immediate without touching the store.
 */
const props = withDefaults(
    defineProps<{
        row: TimelineRow;
        scale: TimelineScale;
        start: string | null;
        end: string | null;
        canEdit: boolean;
        dragging?: boolean;
    }>(),
    { dragging: false },
);

const emit = defineEmits<{
    dragstart: [
        event: PointerEvent,
        row: TimelineRow,
        kind: 'move' | 'resize-start' | 'resize-end',
    ];
    open: [row: TimelineRow];
    setDates: [row: TimelineRow];
}>();

const diamond = computed(
    () => props.row.kind === 'milestone' || props.row.kind === 'release',
);
const left = computed(() =>
    props.start ? props.scale.xForDate(props.start) : null,
);
const width = computed(() =>
    props.start && props.end
        ? Math.max(
              props.scale.dayWidth,
              props.scale.xForDate(props.end) -
                  props.scale.xForDate(props.start) +
                  props.scale.dayWidth,
          )
        : props.scale.dayWidth,
);
const label = computed(() => {
    const dates =
        props.start && props.end && props.start !== props.end
            ? `${dayLabel(props.start)} – ${dayLabel(props.end)}`
            : props.start
              ? dayLabel(props.start)
              : 'No dates';

    return `${props.row.identifier ? props.row.identifier + ' · ' : ''}${props.row.label} · ${dates}`;
});
const color = computed(() =>
    chipClass(
        props.row.color ??
            (props.row.kind === 'release' ? 'emerald' : 'violet'),
    ),
);
</script>

<template>
    <div
        v-if="left === null"
        class="absolute top-1/2 -translate-y-1/2"
        :style="{ left: `${scale.xForDate(scale.from) + 4}px` }"
    >
        <button
            type="button"
            :class="
                cn(
                    'rounded-full border border-dashed px-2 py-0.5 text-[10.5px] text-muted-foreground transition-colors',
                    canEdit
                        ? 'hover:border-brand hover:text-brand'
                        : 'cursor-default',
                )
            "
            :aria-label="label"
            @click="canEdit ? emit('setDates', row) : emit('open', row)"
        >
            {{ canEdit ? 'Set dates' : 'No dates' }}
        </button>
    </div>

    <button
        v-else-if="diamond"
        type="button"
        :class="
            cn(
                'absolute top-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 rotate-45 rounded-[3px] border-2 bg-background shadow-pane transition-transform hover:scale-125',
                color,
                canEdit
                    ? 'cursor-grab active:cursor-grabbing'
                    : 'cursor-pointer',
                dragging && 'opacity-60',
            )
        "
        :style="{
            left: `${left + scale.dayWidth / 2}px`,
            borderColor: 'var(--chip)',
        }"
        :aria-label="label"
        :title="label"
        @pointerdown="canEdit && emit('dragstart', $event, row, 'move')"
        @click="emit('open', row)"
    />

    <div
        v-else
        role="button"
        tabindex="0"
        :class="
            cn(
                'group/bar absolute top-1.5 flex h-6 items-center overflow-hidden rounded-md border text-[11px] shadow-pane transition-[box-shadow,opacity] select-none hover:z-10 hover:shadow-float',
                color,
                row.kind === 'project' ? 'bg-(--chip)/15' : 'bg-card',
                canEdit
                    ? 'cursor-grab active:cursor-grabbing'
                    : 'cursor-pointer',
                dragging && 'opacity-70 shadow-float',
            )
        "
        :style="{
            left: `${left}px`,
            width: `${width}px`,
            borderColor: 'color-mix(in oklch, var(--chip) 55%, transparent)',
        }"
        :aria-label="label"
        :title="label"
        @pointerdown.self="canEdit && emit('dragstart', $event, row, 'move')"
        @click="emit('open', row)"
        @keydown.enter.prevent="emit('open', row)"
    >
        <span
            class="pointer-events-none absolute inset-y-0 left-0 bg-(--chip)/35"
            :style="{
                width: `${Math.round(Math.min(Math.max(row.progress, 0), 1) * 100)}%`,
            }"
            aria-hidden="true"
        />
        <span
            v-if="canEdit"
            class="absolute inset-y-0 left-0 w-2 cursor-ew-resize"
            aria-hidden="true"
            @pointerdown.stop="emit('dragstart', $event, row, 'resize-start')"
        />
        <span
            class="pointer-events-none relative min-w-0 truncate px-2"
            :class="scale.zoom === 'quarter' && width < 40 ? 'sr-only' : ''"
        >
            <span
                v-if="row.identifier"
                class="mr-1 font-mono text-[10px] text-muted-foreground"
                >{{ row.identifier }}</span
            >{{ row.label }}
        </span>
        <span
            v-if="canEdit"
            class="absolute inset-y-0 right-0 w-2 cursor-ew-resize"
            aria-hidden="true"
            @pointerdown.stop="emit('dragstart', $event, row, 'resize-end')"
        />
    </div>
</template>
