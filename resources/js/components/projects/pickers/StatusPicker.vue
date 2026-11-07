<script setup lang="ts">
import { computed } from 'vue';
import PickerShell from '@/components/projects/pickers/PickerShell.vue';
import PickerTrigger from '@/components/projects/pickers/PickerTrigger.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import { CATEGORY_META, CATEGORY_ORDER } from '@/lib/projects/meta';
import type { WorkflowStatus } from '@/types';

/**
 * Picks a status from one workflow: overall statuses on product/project pages, a team's own
 * statuses for a workstream. Grouped by category so the meaning stays visible.
 */
const props = withDefaults(
    defineProps<{
        status: WorkflowStatus | null;
        options: WorkflowStatus[];
        compact?: boolean;
        disabled?: boolean;
        align?: 'start' | 'center' | 'end';
    }>(),
    {
        compact: false,
        disabled: false,
        align: 'start',
    },
);

const emit = defineEmits<{ change: [status: WorkflowStatus] }>();

const items = computed(() =>
    [...props.options]
        .sort(
            (a, b) =>
                CATEGORY_ORDER.indexOf(a.category) -
                    CATEGORY_ORDER.indexOf(b.category) ||
                a.position - b.position,
        )
        .map((status) => ({
            value: String(status.id),
            label: status.name,
            keywords: status.category,
            group: CATEGORY_META[status.category].label,
            status,
        })),
);

const pick = (value: string) => {
    const status = props.options.find((s) => String(s.id) === value);

    if (status && status.id !== props.status?.id) {
        emit('change', status);
    }
};
</script>

<template>
    <PickerShell
        :items="items"
        :selected="status ? [String(status.id)] : []"
        placeholder="Change status…"
        :disabled="disabled"
        :align="align"
        @select="pick"
    >
        <template #trigger>
            <slot name="trigger">
                <PickerTrigger
                    :compact="compact"
                    :disabled="disabled"
                    :empty="!status"
                    label="Status"
                >
                    <StatusIcon :status="status" :size="14" />
                    <span v-if="!compact" class="truncate">{{
                        status?.name ?? 'No status'
                    }}</span>
                </PickerTrigger>
            </slot>
        </template>
        <template #item="{ item }">
            <StatusIcon
                :status="(item as unknown as { status: WorkflowStatus }).status"
                :size="14"
            />
            <span class="truncate">{{ item.label }}</span>
        </template>
    </PickerShell>
</template>
