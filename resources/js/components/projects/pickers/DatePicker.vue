<script setup lang="ts">
import { getLocalTimeZone, parseDate, today } from '@internationalized/date';
import type { DateValue } from '@internationalized/date';
import { CalendarIcon, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import PickerTrigger from '@/components/projects/pickers/PickerTrigger.vue';
import DueDateBadge from '@/components/projects/shared/DueDateBadge.vue';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { StatusCategory } from '@/types';

const props = withDefaults(
    defineProps<{
        value: string | null;
        label?: string;
        compact?: boolean;
        disabled?: boolean;
        category?: StatusCategory;
        align?: 'start' | 'center' | 'end';
    }>(),
    {
        label: 'Due date',
        compact: false,
        disabled: false,
        align: 'start',
    },
);
const emit = defineEmits<{ change: [value: string | null] }>();

const open = ref(false);
const model = computed<DateValue | undefined>(() =>
    props.value ? parseDate(props.value) : undefined,
);

const presets = computed(() => {
    const now = today(getLocalTimeZone());

    return [
        { label: 'Today', value: now.toString() },
        { label: 'Tomorrow', value: now.add({ days: 1 }).toString() },
        { label: 'In a week', value: now.add({ weeks: 1 }).toString() },
        {
            label: 'End of month',
            value: now
                .set({ day: now.calendar.getDaysInMonth(now) })
                .toString(),
        },
    ];
});

const pick = (value: string | null) => {
    emit('change', value);
    open.value = false;
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child :disabled="disabled">
            <slot name="trigger">
                <PickerTrigger
                    :compact="compact"
                    :disabled="disabled"
                    :empty="!value"
                    :label="label"
                >
                    <CalendarIcon v-if="!value" class="size-3.5" />
                    <DueDateBadge
                        v-else
                        :date="value"
                        :category="category"
                        compact
                    />
                    <span v-if="!compact && !value" class="truncate">{{
                        label
                    }}</span>
                </PickerTrigger>
            </slot>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0" :align="align">
            <div class="flex">
                <div class="flex w-32 flex-col gap-0.5 border-r p-1.5">
                    <button
                        v-for="preset in presets"
                        :key="preset.value"
                        type="button"
                        :class="
                            cn(
                                'rounded-md px-2 py-1 text-left text-[12.5px] hover:bg-accent',
                                value === preset.value &&
                                    'bg-brand-soft/60 text-brand-soft-foreground',
                            )
                        "
                        @click="pick(preset.value)"
                    >
                        {{ preset.label }}
                    </button>
                    <button
                        v-if="value"
                        type="button"
                        class="mt-auto flex items-center gap-1 rounded-md px-2 py-1 text-left text-[12.5px] text-muted-foreground hover:bg-accent"
                        @click="pick(null)"
                    >
                        <X class="size-3" /> Clear
                    </button>
                </div>
                <Calendar
                    :model-value="model"
                    class="p-2"
                    @update:model-value="(v) => pick(v ? v.toString() : null)"
                />
            </div>
        </PopoverContent>
    </Popover>
</template>
