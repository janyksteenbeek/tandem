<script setup lang="ts">
import { Tag as TagIcon } from '@lucide/vue';
import { computed } from 'vue';
import PickerShell from '@/components/projects/pickers/PickerShell.vue';
import PickerTrigger from '@/components/projects/pickers/PickerTrigger.vue';
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type { Label } from '@/types';

const props = withDefaults(
    defineProps<{
        selected: Label[];
        available: Label[];
        productId?: number | null;
        compact?: boolean;
        disabled?: boolean;
        canCreate?: boolean;
        align?: 'start' | 'center' | 'end';
    }>(),
    {
        compact: false,
        disabled: false,
        canCreate: true,
        productId: null,
        align: 'start',
    },
);
const emit = defineEmits<{
    toggle: [label: Label | null, attached: boolean, name?: string];
}>();

const options = computed(() =>
    props.available.filter(
        (l) => !l.product_id || l.product_id === props.productId,
    ),
);
const items = computed(() =>
    options.value.map((l) => ({
        value: String(l.id),
        label: l.name,
        label_: l,
    })),
);

const toggle = (id: number, on: boolean) => {
    const label = options.value.find((l) => l.id === id);

    if (label) {
        emit('toggle', label, on);
    }
};
</script>

<template>
    <PickerShell
        :items="items"
        :selected="selected.map((l) => String(l.id))"
        placeholder="Add label…"
        multiple
        :creatable="canCreate"
        create-label="Create label"
        :disabled="disabled"
        :align="align"
        @select="(v, on) => toggle(Number(v), on)"
        @create="(name) => emit('toggle', null, true, name)"
    >
        <template #trigger>
            <slot name="trigger">
                <PickerTrigger
                    :compact="compact"
                    :disabled="disabled"
                    :empty="selected.length === 0"
                    label="Labels"
                >
                    <template v-if="selected.length > 0 && !compact">
                        <span
                            v-for="label in selected.slice(0, 3)"
                            :key="label.id"
                            :class="
                                cn(
                                    'inline-flex items-center gap-1 rounded-full border px-1.5 py-px text-[11px]',
                                    chipClass(label.color),
                                )
                            "
                        >
                            <span class="size-1.5 rounded-full bg-(--chip)" />{{
                                label.name
                            }}
                        </span>
                        <span
                            v-if="selected.length > 3"
                            class="text-[11px] text-muted-foreground"
                            >+{{ selected.length - 3 }}</span
                        >
                    </template>
                    <template v-else-if="selected.length > 0">
                        <span class="flex items-center -space-x-0.5">
                            <span
                                v-for="label in selected.slice(0, 4)"
                                :key="label.id"
                                :class="
                                    cn(
                                        'size-2 rounded-full ring-1 ring-background',
                                        chipClass(label.color),
                                        'bg-(--chip)',
                                    )
                                "
                            />
                        </span>
                    </template>
                    <template v-else>
                        <TagIcon class="size-3.5" />
                        <span v-if="!compact">Add label</span>
                    </template>
                </PickerTrigger>
            </slot>
        </template>
        <template #item="{ item }">
            <span
                :class="
                    cn(
                        'size-2.5 rounded-full bg-(--chip)',
                        chipClass(
                            (item as unknown as { label_: Label }).label_.color,
                        ),
                    )
                "
            />
            <span class="truncate">{{ item.label }}</span>
        </template>
    </PickerShell>
</template>
