<script setup lang="ts">
import { computed } from 'vue';
import { linearScale, polyline } from '@/lib/projects/chartScales';

/** A tiny trend line for headers and rows. */
const props = withDefaults(
    defineProps<{
        values: number[];
        tone?: 'brand' | 'success' | 'muted';
        width?: number;
        height?: number;
    }>(),
    { tone: 'brand', width: 64, height: 18 },
);

const color = computed(
    () =>
        ({
            brand: 'var(--brand)',
            success: 'var(--success)',
            muted: 'var(--muted-foreground)',
        })[props.tone],
);

const points = computed(() => {
    if (props.values.length === 0) {
        return '';
    }

    const max = Math.max(...props.values, 1);
    const x = linearScale(
        [0, Math.max(props.values.length - 1, 1)],
        [1, props.width - 1],
    );
    const y = linearScale([0, max], [props.height - 1, 1]);

    return polyline(props.values.map((v, i) => ({ x: x(i), y: y(v) })));
});
</script>

<template>
    <svg
        :width="width"
        :height="height"
        :viewBox="`0 0 ${width} ${height}`"
        role="img"
        :aria-label="`Trend: ${values.join(', ')}`"
        class="shrink-0"
    >
        <polyline
            :points="points"
            fill="none"
            :stroke="color"
            stroke-width="1.5"
            stroke-linejoin="round"
            stroke-linecap="round"
        />
    </svg>
</template>
