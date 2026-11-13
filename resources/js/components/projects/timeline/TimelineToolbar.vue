<script setup lang="ts">
import { CalendarDays, Minus, Plus } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { TimelineZoom } from '@/lib/projects/timelineScale';

defineProps<{ zoom: TimelineZoom; rows: number; dated: number }>();

const emit = defineEmits<{
    'update:zoom': [zoom: TimelineZoom];
    zoomIn: [];
    zoomOut: [];
    today: [];
}>();

const zooms: { value: TimelineZoom; label: string }[] = [
    { value: 'day', label: 'Day' },
    { value: 'week', label: 'Week' },
    { value: 'month', label: 'Month' },
    { value: 'quarter', label: 'Quarter' },
];
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 border-b px-4 py-2">
        <Button
            variant="outline"
            size="sm"
            class="h-7 gap-1.5 px-2 text-[12px]"
            @click="emit('today')"
        >
            <CalendarDays class="size-3.5" /> Today
        </Button>
        <span class="text-[11.5px] text-muted-foreground tabular-nums"
            >{{ dated }} of {{ rows }} scheduled</span
        >
        <slot />
        <div class="ml-auto flex items-center gap-1">
            <Button
                variant="ghost"
                size="icon"
                class="size-7"
                aria-label="Zoom out"
                @click="emit('zoomOut')"
            >
                <Minus class="size-3.5" />
            </Button>
            <ToggleGroup
                type="single"
                :model-value="zoom"
                size="sm"
                variant="outline"
                aria-label="Zoom"
                @update:model-value="
                    (v) => v && emit('update:zoom', v as TimelineZoom)
                "
            >
                <ToggleGroupItem
                    v-for="z in zooms"
                    :key="z.value"
                    :value="z.value"
                    class="h-7 px-2 text-[12px]"
                    >{{ z.label }}</ToggleGroupItem
                >
            </ToggleGroup>
            <Button
                variant="ghost"
                size="icon"
                class="size-7"
                aria-label="Zoom in"
                @click="emit('zoomIn')"
            >
                <Plus class="size-3.5" />
            </Button>
            <Kbd class="ml-1 h-4 px-1 text-[10px]">-</Kbd>
            <Kbd class="h-4 px-1 text-[10px]">=</Kbd>
        </div>
    </div>
</template>
