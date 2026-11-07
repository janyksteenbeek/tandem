<script setup lang="ts">
import { computed } from 'vue';
import type { ArrowEdge } from '@/lib/projects/timelineScale';

/** Orthogonal arrows from a dependency's end to the dependent's start, over the whole canvas. */
const props = defineProps<{
    edges: ArrowEdge[];
    width: number;
    height: number;
}>();

const paths = computed(() =>
    props.edges.map((edge) => {
        const gap = 10;
        const midX = edge.fromX + gap;
        const d =
            edge.toX > edge.fromX + gap * 2
                ? `M ${edge.fromX} ${edge.fromY} H ${midX} V ${edge.toY} H ${edge.toX - 4}`
                : `M ${edge.fromX} ${edge.fromY} H ${midX} V ${(edge.fromY + edge.toY) / 2} H ${edge.toX - gap} V ${edge.toY} H ${edge.toX - 4}`;

        return { ...edge, d };
    }),
);
</script>

<template>
    <svg
        class="pointer-events-none absolute top-0 left-0 overflow-visible"
        :width="width"
        :height="height"
        aria-hidden="true"
    >
        <defs>
            <marker
                id="tl-arrow"
                viewBox="0 0 8 8"
                refX="7"
                refY="4"
                markerWidth="6"
                markerHeight="6"
                orient="auto-start-reverse"
            >
                <path d="M0 0 L8 4 L0 8 z" fill="currentColor" />
            </marker>
            <marker
                id="tl-arrow-late"
                viewBox="0 0 8 8"
                refX="7"
                refY="4"
                markerWidth="6"
                markerHeight="6"
                orient="auto-start-reverse"
            >
                <path d="M0 0 L8 4 L0 8 z" fill="var(--destructive)" />
            </marker>
        </defs>
        <path
            v-for="path in paths"
            :key="path.id"
            :d="path.d"
            fill="none"
            :stroke="path.late ? 'var(--destructive)' : 'currentColor'"
            :class="path.late ? '' : 'text-muted-foreground/60'"
            stroke-width="1.5"
            :marker-end="path.late ? 'url(#tl-arrow-late)' : 'url(#tl-arrow)'"
        />
    </svg>
</template>
