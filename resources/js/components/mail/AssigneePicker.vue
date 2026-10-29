<script setup lang="ts">
import { Check, UserPlus, UserX } from '@lucide/vue';
import { computed, ref } from 'vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import { Button } from '@/components/ui/button';
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
import type { UserSummary } from '@/types';

const props = defineProps<{
    assignee: UserSummary | null;
    members: UserSummary[];
    me: UserSummary;
    disabled?: boolean;
    compact?: boolean;
}>();
const emit = defineEmits<{ assign: [userId: number | null] }>();

const open = ref(false);
const label = computed(() =>
    props.assignee
        ? props.assignee.id === props.me.id
            ? 'You'
            : props.assignee.name
        : 'Unassigned',
);

const pick = (id: number | null) => {
    open.value = false;
    emit('assign', id);
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                size="sm"
                :disabled="disabled"
                :class="
                    cn(
                        'h-8 max-w-44 gap-1.5 rounded-full px-2 text-xs font-medium',
                        !assignee && 'border-dashed text-muted-foreground',
                    )
                "
            >
                <UserAvatar
                    v-if="assignee"
                    :name="assignee.name"
                    :email="assignee.email"
                    :src="assignee.avatar"
                    :initials="assignee.initials"
                    size="xs"
                />
                <UserPlus v-else class="size-3.5" />
                <span v-if="!compact" class="truncate">{{ label }}</span>
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-64 p-0" align="end">
            <Command>
                <CommandInput placeholder="Assign to…" />
                <CommandList>
                    <CommandEmpty>No teammate found.</CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            value="me"
                            class="gap-2"
                            @select="pick(me.id)"
                        >
                            <UserAvatar
                                :name="me.name"
                                :email="me.email"
                                :src="me.avatar"
                                :initials="me.initials"
                                size="sm"
                            />
                            Assign to me
                            <kbd class="ml-auto rounded border px-1 text-[10px]"
                                >I</kbd
                            >
                        </CommandItem>
                        <CommandItem
                            v-if="assignee"
                            value="unassign"
                            class="gap-2 text-muted-foreground"
                            @select="pick(null)"
                        >
                            <UserX class="size-4" /> Unassign
                        </CommandItem>
                    </CommandGroup>
                    <CommandSeparator />
                    <CommandGroup heading="Teammates">
                        <CommandItem
                            v-for="member in members.filter(
                                (m) => m.id !== me.id,
                            )"
                            :key="member.id"
                            :value="`${member.name} ${member.email}`"
                            class="gap-2"
                            @select="pick(member.id)"
                        >
                            <UserAvatar
                                :name="member.name"
                                :email="member.email"
                                :src="member.avatar"
                                :initials="member.initials"
                                size="sm"
                            />
                            <span class="truncate">{{ member.name }}</span>
                            <Check
                                v-if="assignee?.id === member.id"
                                class="ml-auto size-3.5 text-brand"
                            />
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
