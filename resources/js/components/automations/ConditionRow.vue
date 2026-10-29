<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import ValueInput from '@/components/automations/ValueInput.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { coerceValue, fieldFor, operatorsForField, valueShapeOf } from '@/lib/automations';
import type { AutomationCatalog, AutomationCondition, AutomationLookups } from '@/types/automations';

const props = defineProps<{
    index: number;
    catalog: AutomationCatalog;
    lookups: AutomationLookups;
    conjunction: string;
    errors?: { field?: string; operator?: string; value?: string };
}>();

const condition = defineModel<AutomationCondition>({ required: true });
const emit = defineEmits<{ remove: [] }>();

const field = computed(() => fieldFor(props.catalog, condition.value.field));
const operators = computed(() => operatorsForField(props.catalog, condition.value.field));
const shape = computed(() => valueShapeOf(props.catalog, condition.value.operator));

const setField = (value: string) => {
    const allowed = operatorsForField(props.catalog, value);
    const operator = allowed.some((candidate) => candidate.value === condition.value.operator) ? condition.value.operator : (allowed[0]?.value ?? 'equals');
    const nextShape = valueShapeOf(props.catalog, operator);
    const previousType = field.value?.value_type;
    const nextType = fieldFor(props.catalog, value)?.value_type;
    const keepValue = previousType === nextType || (['text', 'email'].includes(previousType ?? '') && ['text', 'email'].includes(nextType ?? ''));

    condition.value = { field: value, operator, value: coerceValue(keepValue ? condition.value.value : '', nextShape) };
};

const setOperator = (value: string) => {
    condition.value = { ...condition.value, operator: value, value: coerceValue(condition.value.value, valueShapeOf(props.catalog, value)) };
};

const setValue = (value: AutomationCondition['value']) => {
    condition.value = { ...condition.value, value };
};
</script>

<template>
    <div class="group relative animate-rise rounded-xl border bg-card p-3 shadow-pane sm:pl-14">
        <span
            class="mb-2 inline-flex h-6 items-center rounded-full bg-surface-sunken px-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase sm:absolute sm:top-4 sm:left-3 sm:mb-0"
        >
            {{ index === 0 ? 'If' : conjunction }}
        </span>

        <div class="grid gap-2 sm:grid-cols-[minmax(0,9rem)_minmax(0,10rem)_minmax(0,1fr)_auto] sm:items-start">
            <div class="min-w-0">
                <Select :model-value="condition.field" @update:model-value="(v) => setField(String(v))">
                    <SelectTrigger class="w-full" :aria-invalid="errors?.field ? true : undefined" aria-label="Field">
                        <SelectValue placeholder="Field" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in catalog.fields" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
                    </SelectContent>
                </Select>
                <InputError class="mt-1" :message="errors?.field" />
            </div>

            <div class="min-w-0">
                <Select :model-value="condition.operator" @update:model-value="(v) => setOperator(String(v))">
                    <SelectTrigger class="w-full" :aria-invalid="errors?.operator ? true : undefined" aria-label="Operator">
                        <SelectValue placeholder="Operator" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in operators" :key="option.value" :value="option.value">{{ option.label }}</SelectItem>
                    </SelectContent>
                </Select>
                <InputError class="mt-1" :message="errors?.operator" />
            </div>

            <div class="min-w-0">
                <ValueInput
                    :model-value="condition.value"
                    :value-type="field?.value_type ?? 'text'"
                    :shape="shape"
                    :operator="condition.operator"
                    :catalog="catalog"
                    :lookups="lookups"
                    :invalid="Boolean(errors?.value)"
                    @update:model-value="setValue"
                />
                <InputError class="mt-1" :message="errors?.value" />
            </div>

            <Button type="button" variant="ghost" size="icon-sm" class="justify-self-end text-muted-foreground hover:text-destructive" aria-label="Remove condition" @click="emit('remove')">
                <Trash2 />
            </Button>
        </div>
    </div>
</template>
