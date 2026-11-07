<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type {
    CalendarField,
    CalendarMode,
} from '@/lib/projects/calendarLayout';

defineProps<{
    title: string;
    mode: CalendarMode;
    field: CalendarField;
    total: number;
    scheduled: number;
}>();

const emit = defineEmits<{
    prev: [];
    next: [];
    today: [];
    'update:mode': [mode: CalendarMode];
    'update:field': [field: CalendarField];
}>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 border-b px-4 py-2">
        <div class="flex items-center gap-0.5">
            <Button
                variant="ghost"
                size="icon"
                class="size-7"
                aria-label="Previous"
                @click="emit('prev')"
            >
                <ChevronLeft class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                class="size-7"
                aria-label="Next"
                @click="emit('next')"
            >
                <ChevronRight class="size-4" />
            </Button>
            <Button
                variant="outline"
                size="sm"
                class="ml-1 h-7 gap-1.5 px-2 text-[12px]"
                @click="emit('today')"
            >
                Today <Kbd class="h-4 px-1 text-[10px]">T</Kbd>
            </Button>
        </div>
        <h2
            class="min-w-40 text-[14px] font-semibold tracking-[-0.01em]"
            aria-live="polite"
        >
            {{ title }}
        </h2>
        <span class="text-[11.5px] text-muted-foreground tabular-nums"
            >{{ scheduled }} of {{ total }} scheduled</span
        >

        <div class="ml-auto flex items-center gap-2">
            <ToggleGroup
                type="single"
                :model-value="field"
                size="sm"
                variant="outline"
                aria-label="Which date to show"
                @update:model-value="
                    (v) => v && emit('update:field', v as CalendarField)
                "
            >
                <ToggleGroupItem value="due_date" class="h-7 px-2 text-[12px]"
                    >Due</ToggleGroupItem
                >
                <ToggleGroupItem value="start_date" class="h-7 px-2 text-[12px]"
                    >Start</ToggleGroupItem
                >
                <ToggleGroupItem value="range" class="h-7 px-2 text-[12px]"
                    >Span</ToggleGroupItem
                >
            </ToggleGroup>
            <ToggleGroup
                type="single"
                :model-value="mode"
                size="sm"
                variant="outline"
                aria-label="Calendar range"
                @update:model-value="
                    (v) => v && emit('update:mode', v as CalendarMode)
                "
            >
                <ToggleGroupItem value="month" class="h-7 px-2 text-[12px]"
                    >Month</ToggleGroupItem
                >
                <ToggleGroupItem value="week" class="h-7 px-2 text-[12px]"
                    >Week</ToggleGroupItem
                >
            </ToggleGroup>
        </div>
    </div>
</template>
