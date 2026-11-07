<script setup lang="ts">
import { cn } from '@/lib/utils';

/** Inline "12 open · 3 blocked" counts; keeps overviews compact instead of tiles. */
defineProps<{
    items: {
        label: string;
        value: number | string;
        tone?: 'muted' | 'brand' | 'warning' | 'destructive' | 'success';
    }[];
}>();

const tones = {
    muted: 'text-foreground',
    brand: 'text-brand',
    warning: 'text-warning',
    destructive: 'text-destructive',
    success: 'text-success',
};
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12.5px] text-muted-foreground"
    >
        <template v-for="(item, index) in items" :key="item.label">
            <span v-if="index > 0" class="text-border" aria-hidden="true"
                >·</span
            >
            <span
                ><span
                    :class="
                        cn(
                            'font-semibold tabular-nums',
                            tones[item.tone ?? 'muted'],
                        )
                    "
                    >{{ item.value }}</span
                >
                {{ item.label }}</span
            >
        </template>
    </div>
</template>
