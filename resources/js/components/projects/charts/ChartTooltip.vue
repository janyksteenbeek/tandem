<script setup lang="ts">
import { computed } from 'vue';

/** A small pointer-anchored tooltip rendered inside a chart's relative wrapper. */
const props = defineProps<{
    x: number;
    y: number;
    width: number;
    title: string;
    rows: { label: string; value: string | number; tone?: string }[];
}>();

const flip = computed(() => props.x > props.width * 0.65);
</script>

<template>
    <div
        class="pointer-events-none absolute z-10 rounded-md border bg-popover px-2.5 py-1.5 text-[11.5px] shadow-float"
        :style="{
            left: `${x}px`,
            top: `${y}px`,
            transform: flip
                ? 'translate(calc(-100% - 10px), -50%)'
                : 'translate(10px, -50%)',
        }"
        role="status"
    >
        <p class="font-medium text-foreground">{{ title }}</p>
        <p
            v-for="row in rows"
            :key="row.label"
            class="flex items-center justify-between gap-3 text-muted-foreground"
        >
            <span class="flex items-center gap-1.5"
                ><span
                    v-if="row.tone"
                    class="size-1.5 rounded-full"
                    :style="{ background: row.tone }"
                />{{ row.label }}</span
            >
            <span class="font-medium text-foreground tabular-nums">{{
                row.value
            }}</span>
        </p>
    </div>
</template>
