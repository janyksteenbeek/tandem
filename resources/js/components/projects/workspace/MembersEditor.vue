<script lang="ts">
import type { UserSummary } from '@/types';

export type MemberRow = UserSummary & { role: string };
</script>

<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed } from 'vue';
import { MemberPicker } from '@/components/projects/pickers';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';

/** Members with a role, for products (admin|member) and projects (lead|member). */
const props = defineProps<{
    members: MemberRow[];
    candidates: UserSummary[];
    roles: { value: string; label: string }[];
    disabled?: boolean;
}>();
const emit = defineEmits<{
    change: [members: { user_id: number; role: string }[]];
}>();

const available = computed(() =>
    props.candidates.filter((c) => !props.members.some((m) => m.id === c.id)),
);

const push = (next: MemberRow[]) =>
    emit(
        'change',
        next.map((m) => ({ user_id: m.id, role: m.role })),
    );
const add = (users: UserSummary[]) =>
    push([
        ...props.members,
        ...users.map((u) => ({
            ...u,
            role: props.roles[props.roles.length - 1].value,
        })),
    ]);
const setRole = (id: number, role: string) =>
    push(props.members.map((m) => (m.id === id ? { ...m, role } : m)));
const remove = (id: number) => push(props.members.filter((m) => m.id !== id));
</script>

<template>
    <div class="space-y-2">
        <ul v-if="members.length > 0" class="space-y-px">
            <li
                v-for="member in members"
                :key="member.id"
                class="flex h-9 items-center gap-2 rounded-lg px-2 text-[13px] hover:bg-accent/60"
            >
                <UserAvatar
                    :name="member.name"
                    :email="member.email"
                    :src="member.avatar"
                    :initials="member.initials"
                    size="sm"
                />
                <span class="min-w-0 flex-1 truncate">{{ member.name }}</span>
                <NativeSelect
                    :model-value="member.role"
                    class="text-[12px]"
                    :disabled="disabled"
                    @change="
                        (event: Event) =>
                            setRole(
                                member.id,
                                (event.target as HTMLSelectElement).value,
                            )
                    "
                >
                    <NativeSelectOption
                        v-for="role in roles"
                        :key="role.value"
                        :value="role.value"
                        >{{ role.label }}</NativeSelectOption
                    >
                </NativeSelect>
                <Button
                    v-if="!disabled"
                    variant="ghost"
                    size="icon"
                    class="size-6 text-muted-foreground"
                    aria-label="Remove member"
                    @click="remove(member.id)"
                    ><X class="size-3.5"
                /></Button>
            </li>
        </ul>
        <p v-else class="text-[12.5px] text-muted-foreground">
            No explicit members. Teams and the owner still have access.
        </p>
        <MemberPicker
            v-if="!disabled"
            :selected="[]"
            :members="available"
            multiple
            placeholder="Add people…"
            empty-label="Add people"
            @change="add"
        />
    </div>
</template>
