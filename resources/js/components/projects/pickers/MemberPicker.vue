<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { UserPlus, UserX } from '@lucide/vue';
import { computed } from 'vue';
import PickerShell from '@/components/projects/pickers/PickerShell.vue';
import PickerTrigger from '@/components/projects/pickers/PickerTrigger.vue';
import AvatarStack from '@/components/projects/shared/AvatarStack.vue';
import {
    CommandGroup,
    CommandItem,
    CommandSeparator,
} from '@/components/ui/command';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import type { UserSummary } from '@/types';

/**
 * Owner (single) or assignees (multiple). "Assign to me" first, then everyone in the
 * organization or team.
 */
const props = withDefaults(
    defineProps<{
        selected: UserSummary[];
        members: UserSummary[];
        multiple?: boolean;
        compact?: boolean;
        disabled?: boolean;
        placeholder?: string;
        emptyLabel?: string;
        align?: 'start' | 'center' | 'end';
    }>(),
    {
        multiple: false,
        compact: false,
        disabled: false,
        placeholder: 'Assign…',
        emptyLabel: 'Unassigned',
        align: 'start',
    },
);

const emit = defineEmits<{ change: [users: UserSummary[]] }>();

const page = usePage();
const meId = computed(() => page.props.auth.user.id);
const me = computed(
    () => props.members.find((m) => m.id === meId.value) ?? null,
);

const items = computed(() =>
    props.members
        .filter((m) => m.id !== meId.value)
        .map((m) => ({
            value: String(m.id),
            label: m.name,
            keywords: m.email,
            user: m,
        })),
);
const selectedIds = computed(() => props.selected.map((u) => String(u.id)));

const toggle = (id: number, on: boolean) => {
    const user = props.members.find((m) => m.id === id);

    if (!user) {
        return;
    }

    if (!props.multiple) {
        emit('change', on ? [user] : []);

        return;
    }

    emit(
        'change',
        on
            ? [...props.selected, user]
            : props.selected.filter((u) => u.id !== id),
    );
};

const label = computed(() => {
    if (props.selected.length === 0) {
        return props.emptyLabel;
    }

    if (props.selected.length === 1) {
        return props.selected[0].id === meId.value
            ? 'You'
            : props.selected[0].name;
    }

    return `${props.selected.length} people`;
});
</script>

<template>
    <PickerShell
        :items="items"
        :selected="selectedIds"
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
                    <AvatarStack
                        v-if="selected.length > 0"
                        :users="selected"
                        :max="compact ? 2 : 3"
                    />
                    <UserPlus v-else class="size-3.5" />
                    <span v-if="!compact" class="truncate">{{ label }}</span>
                </PickerTrigger>
            </slot>
        </template>
        <template #before>
            <CommandGroup>
                <CommandItem
                    v-if="me"
                    value="__me"
                    class="gap-2"
                    @select="
                        toggle(me.id, !selectedIds.includes(String(me.id)))
                    "
                >
                    <UserAvatar
                        :name="me.name"
                        :email="me.email"
                        :src="me.avatar"
                        :initials="me.initials"
                        size="sm"
                    />
                    {{
                        selectedIds.includes(String(me.id))
                            ? 'Unassign me'
                            : 'Assign to me'
                    }}
                    <kbd class="ml-auto rounded border px-1 text-[10px]">I</kbd>
                </CommandItem>
                <CommandItem
                    v-if="selected.length > 0 && !multiple"
                    value="__none"
                    class="gap-2 text-muted-foreground"
                    @select="emit('change', [])"
                >
                    <UserX class="size-4" /> {{ emptyLabel }}
                </CommandItem>
            </CommandGroup>
            <CommandSeparator />
        </template>
        <template #item="{ item }">
            <UserAvatar
                :name="(item as unknown as { user: UserSummary }).user.name"
                :email="(item as unknown as { user: UserSummary }).user.email"
                :src="(item as unknown as { user: UserSummary }).user.avatar"
                :initials="
                    (item as unknown as { user: UserSummary }).user.initials
                "
                size="sm"
            />
            <span class="truncate">{{ item.label }}</span>
        </template>
    </PickerShell>
</template>
