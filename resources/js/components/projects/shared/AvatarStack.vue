<script setup lang="ts">
import UserAvatar from '@/components/shared/UserAvatar.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { UserSummary } from '@/types';

withDefaults(
    defineProps<{
        users: UserSummary[];
        max?: number;
        size?: 'xs' | 'sm';
        class?: string;
    }>(),
    { max: 3, size: 'xs' },
);
</script>

<template>
    <div :class="cn('flex items-center -space-x-1.5', $props.class)">
        <Tooltip v-for="user in users.slice(0, max)" :key="user.id">
            <TooltipTrigger as-child>
                <span class="rounded-full ring-2 ring-background">
                    <UserAvatar
                        :name="user.name"
                        :email="user.email"
                        :src="user.avatar"
                        :initials="user.initials"
                        :size="size"
                    />
                </span>
            </TooltipTrigger>
            <TooltipContent>{{ user.name }}</TooltipContent>
        </Tooltip>
        <span
            v-if="users.length > max"
            :class="
                cn(
                    'flex items-center justify-center rounded-full bg-muted font-medium text-muted-foreground ring-2 ring-background',
                    size === 'xs' ? 'size-5 text-[9px]' : 'size-6 text-[10px]',
                )
            "
            >+{{ users.length - max }}</span
        >
    </div>
</template>
