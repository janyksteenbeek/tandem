<script setup lang="ts">
import PickerShell from '@/components/projects/pickers/PickerShell.vue';
import PickerTrigger from '@/components/projects/pickers/PickerTrigger.vue';
import PriorityIcon from '@/components/projects/shared/PriorityIcon.vue';
import { PRIORITY_META, PRIORITY_ORDER } from '@/lib/projects/meta';
import type { IssuePriority } from '@/types';

withDefaults(
    defineProps<{
        priority: IssuePriority;
        compact?: boolean;
        disabled?: boolean;
        align?: 'start' | 'center' | 'end';
    }>(),
    { compact: false, disabled: false, align: 'start' },
);
const emit = defineEmits<{ change: [priority: IssuePriority] }>();

const items = PRIORITY_ORDER.map((p, i) => ({
    value: p,
    label: PRIORITY_META[p].label,
    hint: String(4 - i),
}));
</script>

<template>
    <PickerShell
        :items="items"
        :selected="[priority]"
        placeholder="Set priority…"
        width="w-52"
        :disabled="disabled"
        :align="align"
        @select="(v) => emit('change', v as IssuePriority)"
    >
        <template #trigger>
            <slot name="trigger">
                <PickerTrigger
                    :compact="compact"
                    :disabled="disabled"
                    :empty="priority === 'none'"
                    label="Priority"
                >
                    <PriorityIcon :priority="priority" />
                    <span v-if="!compact" class="truncate">{{
                        PRIORITY_META[priority].label
                    }}</span>
                </PickerTrigger>
            </slot>
        </template>
        <template #item="{ item }">
            <PriorityIcon :priority="item.value as IssuePriority" />
            <span>{{ item.label }}</span>
        </template>
    </PickerShell>
</template>
