<script setup lang="ts">
import { MoreHorizontal, Pencil, Reply, Trash2 } from '@lucide/vue';
import DOMPurify from 'dompurify';
import { computed, ref } from 'vue';
import ReactionBar from '@/components/projects/comments/ReactionBar.vue';
import RichTextEditor from '@/components/projects/editor/RichTextEditor.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { fullTime, relative } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { IssueComment, UserSummary } from '@/types';

const props = defineProps<{
    comment: IssueComment;
    members: UserSummary[];
    canReact: boolean;
    canReply: boolean;
    reply?: boolean;
}>();
const emit = defineEmits<{
    edit: [body: string, mentions: number[]];
    remove: [];
    react: [emoji: string, on: boolean];
    reply: [];
}>();

const editing = ref(false);
const draft = ref(props.comment.body);
const editor = ref<InstanceType<typeof RichTextEditor> | null>(null);
const busy = ref(false);

const html = computed(() =>
    DOMPurify.sanitize(props.comment.body, { USE_PROFILES: { html: true } }),
);
const pending = computed(
    () =>
        Boolean(props.comment.client_id) &&
        props.comment.uuid.startsWith('pending-'),
);

const startEdit = () => {
    draft.value = props.comment.body;
    editing.value = true;
};

const save = () => {
    if (busy.value || !draft.value.trim()) {
        return;
    }

    busy.value = true;
    emit('edit', draft.value, editor.value?.mentions() ?? []);
    editing.value = false;
    busy.value = false;
};
</script>

<template>
    <article
        :id="`comment-${comment.uuid}`"
        :class="
            cn(
                'group/comment flex gap-2.5 py-2',
                reply && 'ml-8',
                pending && 'opacity-60',
            )
        "
    >
        <UserAvatar
            :name="comment.user?.name"
            :email="comment.user?.email"
            :src="comment.user?.avatar"
            :initials="comment.user?.initials"
            size="sm"
            class="mt-0.5"
        />
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <span class="text-[13px] font-medium">{{
                    comment.user?.name ?? 'Someone'
                }}</span>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span
                            class="text-[11px] text-muted-foreground tabular-nums"
                            >{{ relative(comment.created_at) }}</span
                        >
                    </TooltipTrigger>
                    <TooltipContent>{{
                        fullTime(comment.created_at)
                    }}</TooltipContent>
                </Tooltip>
                <span
                    v-if="comment.edited_at"
                    class="text-[11px] text-muted-foreground"
                    >(edited)</span
                >
                <div
                    class="ml-auto flex items-center gap-0.5 opacity-0 transition-opacity group-hover/comment:opacity-100 focus-within:opacity-100"
                >
                    <Button
                        v-if="canReply && !reply"
                        variant="ghost"
                        size="icon"
                        class="size-6"
                        aria-label="Reply"
                        @click="emit('reply')"
                        ><Reply class="size-3.5"
                    /></Button>
                    <DropdownMenu
                        v-if="comment.can.update || comment.can.delete"
                    >
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-6"
                                aria-label="Comment actions"
                                ><MoreHorizontal class="size-3.5"
                            /></Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-40">
                            <DropdownMenuItem
                                v-if="comment.can.update"
                                class="gap-2"
                                @click="startEdit"
                                ><Pencil class="size-4" />
                                Edit</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                v-if="comment.can.delete"
                                class="gap-2 text-destructive focus:text-destructive"
                                @click="emit('remove')"
                                ><Trash2 class="size-4" />
                                Delete</DropdownMenuItem
                            >
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <div
                v-if="editing"
                class="mt-1 rounded-lg border bg-card px-3 py-1 shadow-pane"
            >
                <RichTextEditor
                    ref="editor"
                    v-model="draft"
                    :members="members"
                    compact
                    submit-shortcut
                    min-height="2.5rem"
                    @submit="save"
                />
                <div class="flex justify-end gap-1 pb-1.5">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-7"
                        @click="editing = false"
                        >Cancel</Button
                    >
                    <Button
                        size="sm"
                        class="h-7"
                        :disabled="!draft.trim()"
                        @click="save"
                        >Save</Button
                    >
                </div>
            </div>
            <div
                v-else
                class="prose-tandem mt-0.5 text-[13.5px]"
                v-html="html"
            />

            <ReactionBar
                :reactions="comment.reactions"
                :can-react="canReact && !pending"
                @toggle="(emoji, on) => emit('react', emoji, on)"
            />
        </div>
    </article>
</template>
