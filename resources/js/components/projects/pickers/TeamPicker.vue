<script setup lang="ts">
import { Users } from '@lucide/vue';
import { computed } from 'vue';
import PickerShell from '@/components/projects/pickers/PickerShell.vue';
import PickerTrigger from '@/components/projects/pickers/PickerTrigger.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import type { TeamSummary } from '@/types';

const props = withDefaults(
    defineProps<{
        selected: TeamSummary[];
        options: TeamSummary[];
        multiple?: boolean;
        compact?: boolean;
        disabled?: boolean;
        placeholder?: string;
        emptyLabel?: string;
        exclude?: number[];
        align?: 'start' | 'center' | 'end';
    }>(),
    {
        multiple: false,
        compact: false,
        disabled: false,
        placeholder: 'Pick a team…',
        emptyLabel: 'No team',
        exclude: () => [],
        align: 'start',
    },
);
const emit = defineEmits<{ change: [teams: TeamSummary[]] }>();

const items = computed(() =>
    props.options
        .filter((t) => !props.exclude.includes(t.id))
        .map((t) => ({ value: String(t.id), label: t.name, team: t })),
);

const toggle = (id: number, on: boolean) => {
    const team = props.options.find((t) => t.id === id);

    if (!team) {
        return;
    }

    if (!props.multiple) {
        emit('change', on ? [team] : []);

        return;
    }

    emit(
        'change',
        on
            ? [...props.selected, team]
            : props.selected.filter((t) => t.id !== id),
    );
};
</script>

<template>
    <PickerShell
        :items="items"
        :selected="selected.map((t) => String(t.id))"
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
                    <span
                        v-if="selected.length > 0"
                        class="flex items-center -space-x-1"
                    >
                        <InboxDot
                            v-for="team in selected.slice(0, 3)"
                            :key="team.id"
                            :color="team.color"
                            size="sm"
                        />
                    </span>
                    <Users v-else class="size-3.5" />
                    <span v-if="!compact" class="truncate">{{
                        selected.length === 0
                            ? emptyLabel
                            : selected.length === 1
                              ? selected[0].name
                              : `${selected.length} teams`
                    }}</span>
                </PickerTrigger>
            </slot>
        </template>
        <template #item="{ item }">
            <InboxDot
                :color="(item as unknown as { team: TeamSummary }).team.color"
                size="sm"
            />
            <span class="truncate">{{ item.label }}</span>
        </template>
    </PickerShell>
</template>
