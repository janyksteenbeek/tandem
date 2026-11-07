<script lang="ts">
export type PickerItem = {
    value: string;
    label: string;
    keywords?: string;
    hint?: string | null;
    group?: string;
    disabled?: boolean;
};
</script>

<script setup lang="ts">
import { Check, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/**
 * Popover + Command list every picker is built on. Single or multi select, optional
 * "create" row, custom item rendering through the `item` slot. Keep the trigger small:
 * pickers appear inline in rows and property panels.
 */
const props = withDefaults(
    defineProps<{
        items: PickerItem[];
        selected: string[];
        placeholder?: string;
        multiple?: boolean;
        creatable?: boolean;
        createLabel?: string;
        align?: 'start' | 'center' | 'end';
        width?: string;
        disabled?: boolean;
        /** Close the popover after picking (single select default). */
        closeOnSelect?: boolean;
        emptyText?: string;
    }>(),
    {
        placeholder: 'Search…',
        multiple: false,
        creatable: false,
        align: 'start',
        width: 'w-64',
        createLabel: 'Create',
        emptyText: 'Nothing found.',
    },
);

const emit = defineEmits<{
    select: [value: string, selected: boolean];
    create: [name: string];
    'update:open': [open: boolean];
}>();

const open = defineModel<boolean>('open', { default: false });
const query = ref('');

watch(open, (value) => {
    if (!value) {
        query.value = '';
    }

    emit('update:open', value);
});

const groups = computed(() => {
    const map = new Map<string, PickerItem[]>();

    for (const item of props.items) {
        const key = item.group ?? '';
        map.set(key, [...(map.get(key) ?? []), item]);
    }

    return [...map.entries()];
});

const canCreate = computed(
    () =>
        props.creatable &&
        query.value.trim().length > 0 &&
        !props.items.some(
            (i) => i.label.toLowerCase() === query.value.trim().toLowerCase(),
        ),
);

const pick = (item: PickerItem) => {
    if (item.disabled) {
        return;
    }

    const isSelected = props.selected.includes(item.value);
    emit('select', item.value, !isSelected);

    if (props.closeOnSelect ?? !props.multiple) {
        open.value = false;
    }
};

const onInput = (event: Event) => {
    const target = event.target;

    if (target instanceof HTMLInputElement) {
        query.value = target.value;
    }
};

const create = () => {
    emit('create', query.value.trim());
    query.value = '';

    if (!props.multiple) {
        open.value = false;
    }
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child :disabled="disabled">
            <slot name="trigger" :open="open" />
        </PopoverTrigger>
        <PopoverContent
            :class="cn('p-0', width)"
            :align="align"
            @open-auto-focus.prevent
        >
            <Command @input="onInput">
                <CommandInput :placeholder="placeholder" auto-focus />
                <CommandList class="max-h-72">
                    <CommandEmpty>{{ emptyText }}</CommandEmpty>
                    <slot name="before" />
                    <template
                        v-for="([group, list], index) in groups"
                        :key="group || 'default'"
                    >
                        <CommandSeparator v-if="index > 0" />
                        <CommandGroup :heading="group || undefined">
                            <CommandItem
                                v-for="item in list"
                                :key="item.value"
                                :value="item.value"
                                :disabled="item.disabled"
                                class="gap-2"
                                @select="pick(item)"
                            >
                                <slot
                                    name="item"
                                    :item="item"
                                    :selected="selected.includes(item.value)"
                                >
                                    <span class="truncate">{{
                                        item.label
                                    }}</span>
                                </slot>
                                <span v-if="item.keywords" class="sr-only">{{
                                    item.keywords
                                }}</span>
                                <span
                                    v-if="item.hint"
                                    class="ml-auto text-[11px] text-muted-foreground"
                                    >{{ item.hint }}</span
                                >
                                <Check
                                    v-if="selected.includes(item.value)"
                                    :class="
                                        cn(
                                            'size-3.5 text-brand',
                                            item.hint ? '' : 'ml-auto',
                                        )
                                    "
                                />
                            </CommandItem>
                        </CommandGroup>
                    </template>
                    <template v-if="canCreate">
                        <CommandSeparator />
                        <CommandGroup>
                            <CommandItem
                                :value="`__create__${query}`"
                                class="gap-2 text-brand"
                                @select="create"
                            >
                                <Plus class="size-3.5" /> {{ createLabel }} “{{
                                    query.trim()
                                }}”
                            </CommandItem>
                        </CommandGroup>
                    </template>
                    <slot name="after" />
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
