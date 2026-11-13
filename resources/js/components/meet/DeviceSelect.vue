<script setup lang="ts">
import type { Component } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { DeviceOption } from '@/lib/meet/types';

/**
 * One device picker: icon, the current device, and the list of alternatives.
 */
defineProps<{
    label: string;
    icon: Component;
    options: DeviceOption[];
    modelValue: string | null;
    placeholder?: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <Select
        :model-value="modelValue ?? undefined"
        :disabled="disabled || options.length === 0"
        @update:model-value="
            (value) => emit('update:modelValue', String(value))
        "
    >
        <SelectTrigger
            class="h-9 w-full min-w-0 justify-start gap-2 rounded-lg bg-card text-left"
            :aria-label="label"
        >
            <component
                :is="icon"
                class="size-4 shrink-0 text-muted-foreground"
            />
            <span class="min-w-0 flex-1 truncate">
                <SelectValue
                    :placeholder="
                        options.length === 0
                            ? (placeholder ?? `No ${label.toLowerCase()} found`)
                            : label
                    "
                />
            </span>
        </SelectTrigger>
        <SelectContent class="max-w-[min(24rem,90vw)]">
            <SelectItem
                v-for="option in options"
                :key="option.id"
                :value="option.id"
            >
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
