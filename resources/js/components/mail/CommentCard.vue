<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Lock, MoreHorizontal, Pencil, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { fullTime } from '@/lib/time';
import type { Comment } from '@/types';

const props = defineProps<{ comment: Comment }>();
const emit = defineEmits<{
    edit: [comment: Comment];
    remove: [comment: Comment];
}>();

const page = usePage();
const mine = computed(() => props.comment.user.id === page.props.auth.user.id);
</script>

<template>
    <article
        class="flex gap-3 rounded-xl border border-highlight/40 bg-highlight/10 px-4 py-3"
    >
        <UserAvatar
            :name="comment.user.name"
            :email="comment.user.email"
            :src="comment.user.avatar"
            :initials="comment.user.initials"
            size="md"
            class="mt-0.5"
        />
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <span class="text-sm font-semibold">{{
                    comment.user.name
                }}</span>
                <span
                    class="flex items-center gap-1 rounded bg-highlight/30 px-1.5 text-[10px] font-medium text-highlight-foreground"
                    ><Lock class="size-2.5" /> internal</span
                >
                <span class="text-xs text-muted-foreground"
                    >{{ fullTime(comment.created_at)
                    }}<span v-if="comment.edited_at"> · edited</span></span
                >
                <DropdownMenu v-if="mine">
                    <DropdownMenuTrigger as-child
                        ><Button
                            variant="ghost"
                            size="icon"
                            class="ml-auto size-6"
                            ><MoreHorizontal class="size-3.5" /></Button
                    ></DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            class="gap-2"
                            @click="emit('edit', comment)"
                            ><Pencil class="size-3.5" /> Edit</DropdownMenuItem
                        >
                        <DropdownMenuItem
                            class="gap-2 text-destructive"
                            @click="emit('remove', comment)"
                            ><Trash2 class="size-3.5" />
                            Delete</DropdownMenuItem
                        >
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            <div class="prose-tandem mt-1 text-sm" v-html="comment.body" />
        </div>
    </article>
</template>
