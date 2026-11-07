<script setup lang="ts">
import { computed, ref } from 'vue';
import ChartTooltip from '@/components/projects/charts/ChartTooltip.vue';
import {
    dateRange,
    idealBurndown,
    linearScale,
    niceTicks,
    polyline,
    timeTicks,
} from '@/lib/projects/chartScales';

/**
 * Remaining work per day against the ideal line. Scope shows as its own line when it moved
 * during the cycle. Pure SVG on the design tokens.
 */
const props = withDefaults(
    defineProps<{
        series: { date: string; scope: number; remaining: number }[];
        daysTotal: number;
        startsAt: string;
        endsAt: string;
        height?: number;
    }>(),
    { height: 176 },
);

const W = 640;
const H = computed(() => props.height);
const PAD = { top: 10, right: 12, bottom: 22, left: 30 };

const dates = computed(() => dateRange(props.startsAt, props.endsAt));
const byDate = computed(() => new Map(props.series.map((p) => [p.date, p])));
const scope0 = computed(
    () => props.series[0]?.scope ?? props.series.at(-1)?.scope ?? 0,
);
const ideal = computed(() =>
    idealBurndown(scope0.value, Math.max(dates.value.length - 1, 1)),
);
const maxY = computed(() =>
    Math.max(
        scope0.value,
        ...props.series.map((p) => Math.max(p.scope, p.remaining)),
        1,
    ),
);
const ticksY = computed(() => niceTicks(0, maxY.value, 3));
const x = computed(() =>
    linearScale(
        [0, Math.max(dates.value.length - 1, 1)],
        [PAD.left, W - PAD.right],
    ),
);
const y = computed(() =>
    linearScale(
        [0, ticksY.value.at(-1) ?? maxY.value],
        [H.value - PAD.bottom, PAD.top],
    ),
);

const today = new Date().toISOString().slice(0, 10);
const todayIndex = computed(() => dates.value.indexOf(today));

/** Remaining/scope lines only up to the last recorded day. */
const recorded = computed(() =>
    dates.value
        .map((date, i) => ({ i, point: byDate.value.get(date) }))
        .filter(
            (
                d,
            ): d is {
                i: number;
                point: { date: string; scope: number; remaining: number };
            } => Boolean(d.point),
        ),
);
const remainingPts = computed(() =>
    recorded.value.map((d) => ({
        x: x.value(d.i),
        y: y.value(d.point.remaining),
    })),
);
const scopePts = computed(() =>
    recorded.value.map((d) => ({ x: x.value(d.i), y: y.value(d.point.scope) })),
);
const scopeChanged = computed(() =>
    recorded.value.some((d) => d.point.scope !== scope0.value),
);
const idealPts = computed(() =>
    ideal.value.map((v, i) => ({ x: x.value(i), y: y.value(v) })),
);
const area = computed(() => {
    if (remainingPts.value.length === 0) {
        return '';
    }

    const first = remainingPts.value[0];
    const last = remainingPts.value.at(-1)!;
    const base = y.value(0);

    return `M${first.x},${base} L${polyline(remainingPts.value).replace(/ /g, ' L')} L${last.x},${base} Z`;
});

const ticksX = computed(() =>
    timeTicks(dates.value, dates.value.length > 21 ? 7 : 5),
);
const label = (date: string) =>
    new Date(date + 'T00:00:00').toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });

const wrapper = ref<HTMLElement | null>(null);
const hover = ref<{ i: number; px: number; py: number } | null>(null);

const onMove = (event: MouseEvent) => {
    const el = wrapper.value;

    if (!el || dates.value.length === 0) {
        return;
    }

    const rect = el.getBoundingClientRect();
    const px = event.clientX - rect.left;
    const chartX = (px / rect.width) * W;
    const i = Math.round(
        ((chartX - PAD.left) / (W - PAD.left - PAD.right)) *
            Math.max(dates.value.length - 1, 1),
    );
    const clamped = Math.min(Math.max(i, 0), dates.value.length - 1);

    hover.value = {
        i: clamped,
        px: (x.value(clamped) / W) * rect.width,
        py: event.clientY - rect.top,
    };
};

const hoverPoint = computed(() =>
    hover.value ? byDate.value.get(dates.value[hover.value.i]) : null,
);
</script>

<template>
    <div
        ref="wrapper"
        class="relative w-full"
        :style="{ height: `${height}px` }"
        @mousemove="onMove"
        @mouseleave="hover = null"
    >
        <svg
            :viewBox="`0 0 ${W} ${H}`"
            preserveAspectRatio="none"
            class="h-full w-full"
            role="img"
            aria-labelledby="burndown-title"
        >
            <title id="burndown-title">
                Burndown: remaining work per day against the ideal line
            </title>
            <g class="text-border">
                <line
                    v-for="t in ticksY"
                    :key="`y-${t}`"
                    :x1="PAD.left"
                    :x2="W - PAD.right"
                    :y1="y(t)"
                    :y2="y(t)"
                    stroke="currentColor"
                    stroke-width="1"
                    :opacity="t === 0 ? 1 : 0.6"
                />
            </g>
            <g class="fill-muted-foreground" font-size="10">
                <text
                    v-for="t in ticksY"
                    :key="`yl-${t}`"
                    :x="PAD.left - 6"
                    :y="y(t) + 3"
                    text-anchor="end"
                >
                    {{ t }}
                </text>
                <text
                    v-for="d in ticksX"
                    :key="`xl-${d}`"
                    :x="x(dates.indexOf(d))"
                    :y="H - 6"
                    text-anchor="middle"
                >
                    {{ label(d) }}
                </text>
            </g>
            <polyline
                :points="polyline(idealPts)"
                fill="none"
                stroke="var(--muted-foreground)"
                stroke-width="1.25"
                stroke-dasharray="4 4"
                opacity="0.6"
                vector-effect="non-scaling-stroke"
            />
            <path v-if="area" :d="area" fill="var(--brand)" opacity="0.1" />
            <polyline
                v-if="scopeChanged"
                :points="polyline(scopePts)"
                fill="none"
                stroke="var(--chart-3)"
                stroke-width="1.5"
                vector-effect="non-scaling-stroke"
            />
            <polyline
                :points="polyline(remainingPts)"
                fill="none"
                stroke="var(--brand)"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
                vector-effect="non-scaling-stroke"
            />
            <line
                v-if="todayIndex >= 0"
                :x1="x(todayIndex)"
                :x2="x(todayIndex)"
                :y1="PAD.top"
                :y2="H - PAD.bottom"
                stroke="var(--highlight)"
                stroke-width="1"
                stroke-dasharray="2 3"
                vector-effect="non-scaling-stroke"
            />
            <g v-if="hover">
                <line
                    :x1="x(hover.i)"
                    :x2="x(hover.i)"
                    :y1="PAD.top"
                    :y2="H - PAD.bottom"
                    stroke="var(--foreground)"
                    stroke-width="1"
                    opacity="0.25"
                    vector-effect="non-scaling-stroke"
                />
                <circle
                    v-if="hoverPoint"
                    :cx="x(hover.i)"
                    :cy="y(hoverPoint.remaining)"
                    r="3.5"
                    fill="var(--brand)"
                    stroke="var(--background)"
                    stroke-width="2"
                />
            </g>
        </svg>

        <ChartTooltip
            v-if="hover"
            :x="hover.px"
            :y="hover.py"
            :width="wrapper?.clientWidth ?? W"
            :title="label(dates[hover.i])"
            :rows="[
                {
                    label: 'Remaining',
                    value: hoverPoint ? hoverPoint.remaining : '—',
                    tone: 'var(--brand)',
                },
                {
                    label: 'Scope',
                    value: hoverPoint ? hoverPoint.scope : '—',
                    tone: 'var(--chart-3)',
                },
                {
                    label: 'Ideal',
                    value: Math.round(ideal[hover.i] ?? 0),
                    tone: 'var(--muted-foreground)',
                },
            ]"
        />

        <table class="sr-only">
            <caption>
                Burndown data
            </caption>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Remaining</th>
                    <th>Scope</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in series" :key="p.date">
                    <td>{{ p.date }}</td>
                    <td>{{ p.remaining }}</td>
                    <td>{{ p.scope }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
