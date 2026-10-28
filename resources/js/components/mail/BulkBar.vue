<script setup lang="ts">
import { Archive, CheckCircle2, Clock, MailOpen, Tag as TagIcon, Trash2, UserCheck, X } from '@lucide/vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import type { Tag, UserSummary } from '@/types';

defineProps<{ count: number; isShared: boolean; members: UserSummary[]; tags: Tag[] }>();

const emit = defineEmits<{
    clear: [];
    action: [action: string, params?: Record<string, unknown>];
}>();
</script>

<template>
    <div class="flex items-center gap-1 border-b border-brand/20 bg-brand-soft/60 px-3 py-2 text-xs animate-rise">
        <span class="mr-1 font-medium text-brand-soft-foreground">{{ count }} selected</span>
        <Button variant="ghost" size="sm" class="h-7 gap-1 px-2" @click="emit('action', 'read')"><MailOpen class="size-3.5" /> Read</Button>
        <Button variant="ghost" size="sm" class="h-7 gap-1 px-2" @click="emit('action', 'archive')"><Archive class="size-3.5" /> Archive</Button>
        <Button v-if="isShared" variant="ghost" size="sm" class="h-7 gap-1 px-2" @click="emit('action', 'status', { status: 'closed' })"><CheckCircle2 class="size-3.5" /> Done</Button>
        <DropdownMenu v-if="isShared">
            <DropdownMenuTrigger as-child>
                <Button variant="ghost" size="sm" class="h-7 gap-1 px-2"><UserCheck class="size-3.5" /> Assign</Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent>
                <DropdownMenuItem @click="emit('action', 'assign', { assignee_id: null })">Unassign</DropdownMenuItem>
                <DropdownMenuItem v-for="m in members" :key="m.id" class="gap-2" @click="emit('action', 'assign', { assignee_id: m.id })">
                    <UserAvatar :name="m.name" :email="m.email" :src="m.avatar" :initials="m.initials" size="xs" /> {{ m.name }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
        <DropdownMenu v-if="tags.length">
            <DropdownMenuTrigger as-child>
                <Button variant="ghost" size="sm" class="h-7 gap-1 px-2"><TagIcon class="size-3.5" /> Tag</Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent>
                <DropdownMenuItem v-for="t in tags" :key="t.id" @click="emit('action', 'tag', { tag_id: t.id })">{{ t.name }}</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
        <Button v-if="isShared" variant="ghost" size="sm" class="h-7 gap-1 px-2" @click="emit('action', 'status', { status: 'snoozed', snoozed_until: new Date(Date.now() + 86400000).toISOString() })"><Clock class="size-3.5" /> Tomorrow</Button>
        <Button variant="ghost" size="sm" class="h-7 gap-1 px-2 text-destructive hover:text-destructive" @click="emit('action', 'trash')"><Trash2 class="size-3.5" /> Trash</Button>
        <Button variant="ghost" size="icon" class="ml-auto size-7" aria-label="Clear selection" @click="emit('clear')"><X class="size-3.5" /></Button>
    </div>
</template>
