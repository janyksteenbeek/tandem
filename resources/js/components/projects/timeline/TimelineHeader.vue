<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { TimelineScale } from '@/lib/projects/timelineScale';

/** The date ruler above the canvas; sticky inside the horizontal scroller. */
defineProps<{ scale: TimelineScale; todayX: number | null }>();
</script>

<template>
    <div
        class="sticky top-0 z-10 h-8 border-b bg-background/95 backdrop-blur"
        :style="{ width: `${scale.totalWidth}px` }"
    >
        <div
            v-for="tick in scale.ticks"
            :key="tick.date"
            :class="
                cn(
                    'absolute top-0 flex h-full items-center border-l pl-1.5 text-[10.5px] whitespace-nowrap tabular-nums',
                    tick.major
                        ? 'border-border font-semibold text-foreground'
                        : 'border-border/50 text-muted-foreground',
                )
            "
            :style="{ left: `${tick.x}px` }"
        >
            {{ tick.label }}
        </div>
        <div
            v-if="todayX !== null"
            class="absolute top-0 h-full w-px bg-brand"
            :style="{ left: `${todayX}px` }"
        >
            <span
                class="absolute top-0.5 left-1 rounded-sm bg-brand px-1 text-[9.5px] font-semibold text-brand-foreground"
                >Today</span
            >
        </div>
    </div>
</template>
