<script setup lang="ts">
import { Gauge } from '@lucide/vue';
import { computed } from 'vue';
import PickerShell from '@/components/projects/pickers/PickerShell.vue';
import PickerTrigger from '@/components/projects/pickers/PickerTrigger.vue';
import { ESTIMATE_SCALES, formatEstimate } from '@/lib/projects/meta';
import type { EstimateScale } from '@/types';

const props = withDefaults(
    defineProps<{
        value: number | null;
        scale?: EstimateScale;
        compact?: boolean;
        disabled?: boolean;
        align?: 'start' | 'center' | 'end';
    }>(),
    { scale: 'fibonacci', compact: false, disabled: false, align: 'start' },
);
const emit = defineEmits<{ change: [value: number | null] }>();

const items = computed(() => [
    { value: '__none', label: 'No estimate' },
    ...(ESTIMATE_SCALES[props.scale] ?? ESTIMATE_SCALES.fibonacci).map((n) => ({
        value: String(n),
        label: formatEstimate(n, props.scale),
        hint: props.scale === 'tshirt' ? `${n} pt` : null,
    })),
]);
</script>

<template>
    <PickerShell
        :items="items"
        :selected="[value === null ? '__none' : String(value)]"
        placeholder="Estimate…"
        width="w-44"
        :disabled="disabled"
        :align="align"
        @select="(v) => emit('change', v === '__none' ? null : Number(v))"
    >
        <template #trigger>
            <slot name="trigger">
                <PickerTrigger
                    :compact="compact"
                    :disabled="disabled"
                    :empty="value === null"
                    label="Estimate"
                >
                    <Gauge class="size-3.5" />
                    <span
                        v-if="!compact || value !== null"
                        class="truncate tabular-nums"
                        >{{
                            value === null
                                ? 'Estimate'
                                : formatEstimate(value, scale)
                        }}</span
                    >
                </PickerTrigger>
            </slot>
        </template>
    </PickerShell>
</template>
