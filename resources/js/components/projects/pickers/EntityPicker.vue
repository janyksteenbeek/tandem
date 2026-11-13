<script lang="ts">
export type EntityOption = {
    id: number;
    name: string;
    color?: string | null;
    hint?: string | null;
    group?: string;
};
</script>

<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { X } from '@lucide/vue';
import { computed } from 'vue';
import PickerShell from '@/components/projects/pickers/PickerShell.vue';
import PickerTrigger from '@/components/projects/pickers/PickerTrigger.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import {
    CommandGroup,
    CommandItem,
    CommandSeparator,
} from '@/components/ui/command';

/**
 * One picker for products, projects, modules, milestones and cycles: a coloured dot and a
 * name, optional "none" row, single or multiple.
 */
const props = withDefaults(
    defineProps<{
        selected: EntityOption[];
        options: EntityOption[];
        icon: LucideIcon;
        placeholder: string;
        emptyLabel: string;
        multiple?: boolean;
        compact?: boolean;
        disabled?: boolean;
        clearable?: boolean;
        align?: 'start' | 'center' | 'end';
    }>(),
    {
        multiple: false,
        compact: false,
        disabled: false,
        clearable: true,
        align: 'start',
    },
);
const emit = defineEmits<{ change: [options: EntityOption[]] }>();

const items = computed(() =>
    props.options.map((o) => ({
        value: String(o.id),
        label: o.name,
        hint: o.hint ?? null,
        group: o.group,
        option: o,
    })),
);

const toggle = (id: number, on: boolean) => {
    const option = props.options.find((o) => o.id === id);

    if (!option) {
        return;
    }

    if (!props.multiple) {
        emit('change', on ? [option] : []);

        return;
    }

    emit(
        'change',
        on
            ? [...props.selected, option]
            : props.selected.filter((o) => o.id !== id),
    );
};
</script>

<template>
    <PickerShell
        :items="items"
        :selected="selected.map((o) => String(o.id))"
        :placeholder="placeholder"
        :multiple="multiple"
        :disabled="disabled"
        :align="align"
        @select="(v, on) => toggle(Number(v), on)"
    >
        <template #trigger>
            <slot name="trigger">
                <PickerTrigger
                    :compact="compact"
                    :disabled="disabled"
                    :empty="selected.length === 0"
                    :label="placeholder"
                >
                    <InboxDot
                        v-if="selected.length === 1 && selected[0].color"
                        :color="selected[0].color"
                        size="sm"
                    />
                    <component :is="icon" v-else class="size-3.5" />
                    <span v-if="!compact" class="truncate">{{
                        selected.length === 0
                            ? emptyLabel
                            : selected.length === 1
                              ? selected[0].name
                              : `${selected.length} selected`
                    }}</span>
                </PickerTrigger>
            </slot>
        </template>
        <template v-if="clearable && selected.length > 0 && !multiple" #before>
            <CommandGroup>
                <CommandItem
                    value="__none"
                    class="gap-2 text-muted-foreground"
                    @select="emit('change', [])"
                    ><X class="size-3.5" /> {{ emptyLabel }}</CommandItem
                >
            </CommandGroup>
            <CommandSeparator />
        </template>
        <template #item="{ item }">
            <InboxDot
                v-if="
                    (item as unknown as { option: EntityOption }).option.color
                "
                :color="
                    (item as unknown as { option: EntityOption }).option
                        .color ?? 'slate'
                "
                size="sm"
            />
            <component
                :is="icon"
                v-else
                class="size-3.5 text-muted-foreground"
            />
            <span class="truncate">{{ item.label }}</span>
        </template>
    </PickerShell>
</template>
