<script setup lang="ts">
import { computed, ref } from 'vue';
import ChartTooltip from '@/components/projects/charts/ChartTooltip.vue';
import { linearScale, niceTicks } from '@/lib/projects/chartScales';

/** Committed vs completed per cycle, with the average completed as a reference line. */
const props = withDefaults(
    defineProps<{
        cycles: {
            cycle: { name: string };
            committed: number;
            completed: number;
            points: number;
        }[];
        height?: number;
    }>(),
    { height: 176 },
);

const W = 420;
const H = computed(() => props.height);
const PAD = { top: 10, right: 8, bottom: 22, left: 26 };

const maxY = computed(() =>
    Math.max(...props.cycles.map((c) => Math.max(c.committed, c.completed)), 1),
);
const ticksY = computed(() => niceTicks(0, maxY.value, 3));
const y = computed(() =>
    linearScale(
        [0, ticksY.value.at(-1) ?? maxY.value],
        [H.value - PAD.bottom, PAD.top],
    ),
);
const slot = computed(
    () => (W - PAD.left - PAD.right) / Math.max(props.cycles.length, 1),
);
const barW = computed(() => Math.min(18, slot.value * 0.3));
const average = computed(() =>
    props.cycles.length
        ? props.cycles.reduce((s, c) => s + c.completed, 0) /
          props.cycles.length
        : 0,
);

const wrapper = ref<HTMLElement | null>(null);
const hover = ref<{ i: number; px: number; py: number } | null>(null);

const onMove = (event: MouseEvent) => {
    const el = wrapper.value;

    if (!el || props.cycles.length === 0) {
        return;
    }

    const rect = el.getBoundingClientRect();
    const px = event.clientX - rect.left;
    const chartX = (px / rect.width) * W;
    const i = Math.min(
        Math.max(Math.floor((chartX - PAD.left) / slot.value), 0),
        props.cycles.length - 1,
    );

    hover.value = { i, px, py: event.clientY - rect.top };
};
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
            aria-labelledby="velocity-title"
        >
            <title id="velocity-title">
                Velocity: workstreams committed and completed per cycle
            </title>
            <g class="text-border">
                <line
                    v-for="t in ticksY"
                    :key="t"
                    :x1="PAD.left"
                    :x2="W - PAD.right"
                    :y1="y(t)"
                    :y2="y(t)"
                    stroke="currentColor"
                    :opacity="t === 0 ? 1 : 0.6"
                />
            </g>
            <g class="fill-muted-foreground" font-size="10">
                <text
                    v-for="t in ticksY"
                    :key="`l-${t}`"
                    :x="PAD.left - 6"
                    :y="y(t) + 3"
                    text-anchor="end"
                >
                    {{ t }}
                </text>
            </g>
            <g v-for="(c, i) in cycles" :key="i">
                <rect
                    :x="PAD.left + i * slot + slot / 2 - barW - 1"
                    :y="y(c.committed)"
                    :width="barW"
                    :height="Math.max(y(0) - y(c.committed), 0)"
                    rx="2"
                    fill="var(--muted-foreground)"
                    opacity="0.35"
                />
                <rect
                    :x="PAD.left + i * slot + slot / 2 + 1"
                    :y="y(c.completed)"
                    :width="barW"
                    :height="Math.max(y(0) - y(c.completed), 0)"
                    rx="2"
                    fill="var(--brand)"
                    :opacity="hover && hover.i !== i ? 0.6 : 1"
                />
                <text
                    :x="PAD.left + i * slot + slot / 2"
                    :y="H - 6"
                    text-anchor="middle"
                    font-size="10"
                    class="fill-muted-foreground"
                >
                    {{ c.cycle.name.replace(/^Cycle /, '#') }}
                </text>
            </g>
            <line
                v-if="cycles.length > 1"
                :x1="PAD.left"
                :x2="W - PAD.right"
                :y1="y(average)"
                :y2="y(average)"
                stroke="var(--chart-3)"
                stroke-width="1.25"
                stroke-dasharray="4 3"
                vector-effect="non-scaling-stroke"
            />
        </svg>

        <ChartTooltip
            v-if="hover && cycles[hover.i]"
            :x="hover.px"
            :y="hover.py"
            :width="wrapper?.clientWidth ?? W"
            :title="cycles[hover.i].cycle.name"
            :rows="[
                {
                    label: 'Committed',
                    value: cycles[hover.i].committed,
                    tone: 'var(--muted-foreground)',
                },
                {
                    label: 'Completed',
                    value: cycles[hover.i].completed,
                    tone: 'var(--brand)',
                },
                { label: 'Points', value: cycles[hover.i].points },
            ]"
        />

        <table class="sr-only">
            <caption>
                Velocity per cycle
            </caption>
            <thead>
                <tr>
                    <th>Cycle</th>
                    <th>Committed</th>
                    <th>Completed</th>
                    <th>Points</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(c, i) in cycles" :key="i">
                    <td>{{ c.cycle.name }}</td>
                    <td>{{ c.committed }}</td>
                    <td>{{ c.completed }}</td>
                    <td>{{ c.points }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
