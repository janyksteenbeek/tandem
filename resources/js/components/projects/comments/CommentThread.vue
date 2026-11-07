<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Send } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import IssueCommentCard from '@/components/projects/comments/IssueCommentCard.vue';
import ConfirmDialog from '@/components/projects/dialogs/ConfirmDialog.vue';
import SectionLabel from '@/components/projects/detail/SectionLabel.vue';
import RichTextEditor from '@/components/projects/editor/RichTextEditor.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { injectIssueActions } from '@/composables/projects/useIssueActions';
import type { Issue, IssueComment, UserSummary } from '@/types';

/**
 * Comments with one level of replies. New comments are inserted optimistically and swapped
 * for the server copy when it arrives; realtime arrivals go through `receive`.
 */
const props = defineProps<{
    issue: Issue;
    comments: IssueComment[];
    members: UserSummary[];
}>();
const emit = defineEmits<{ change: [comments: IssueComment[]] }>();

const page = usePage();
const me = computed(() => page.props.auth.user);
const actions = injectIssueActions();

const draft = ref('');
const replyTo = ref<IssueComment | null>(null);
const busy = ref(false);
const editor = ref<InstanceType<typeof RichTextEditor> | null>(null);
const pendingDelete = ref<IssueComment | null>(null);

const roots = computed(() => props.comments.filter((c) => !c.parent_uuid));
const repliesOf = (uuid: string) =>
    props.comments.filter((c) => c.parent_uuid === uuid);

const summary = (): UserSummary => ({
    id: me.value.id,
    name: me.value.name,
    email: me.value.email,
    initials: String(me.value.initials ?? ''),
    avatar: (me.value.avatar as string | null | undefined) ?? null,
});

const scrollTo = async (uuid: string) => {
    await nextTick();
    document
        .getElementById(`comment-${uuid}`)
        ?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
};

const submit = async () => {
    if (busy.value || !draft.value.trim() || editor.value?.isEmpty()) {
        return;
    }

    busy.value = true;
    const body = draft.value;
    const mentions = editor.value?.mentions() ?? [];
    const clientId = `c-${Date.now()}`;
    const optimistic: IssueComment = {
        uuid: `pending-${clientId}`,
        client_id: clientId,
        parent_uuid: replyTo.value?.uuid ?? null,
        body,
        mentions,
        user: summary(),
        reactions: [],
        attachments: [],
        created_at: new Date().toISOString(),
        edited_at: null,
        can: { update: false, delete: false },
    };

    emit('change', [...props.comments, optimistic]);
    draft.value = '';
    editor.value?.clear();
    const parent = replyTo.value;
    replyTo.value = null;
    void scrollTo(optimistic.uuid);

    const created = await actions.comments.create(
        props.issue,
        body,
        mentions,
        parent?.uuid,
    );

    if (created) {
        emit(
            'change',
            props.comments
                .filter(
                    (c) =>
                        c.uuid !== optimistic.uuid && c.uuid !== created.uuid,
                )
                .concat({ ...created, client_id: clientId }),
        );
    } else {
        emit(
            'change',
            props.comments.filter((c) => c.uuid !== optimistic.uuid),
        );
        draft.value = body;
    }

    busy.value = false;
};

const edit = async (
    comment: IssueComment,
    body: string,
    mentions: number[],
) => {
    const previous = comment.body;
    emit(
        'change',
        props.comments.map((c) =>
            c.uuid === comment.uuid ? { ...c, body } : c,
        ),
    );
    const updated = await actions.comments.update(comment, body, mentions);

    emit(
        'change',
        props.comments.map((c) =>
            c.uuid === comment.uuid ? (updated ?? { ...c, body: previous }) : c,
        ),
    );
};

const remove = async () => {
    const target = pendingDelete.value;
    pendingDelete.value = null;

    if (!target) {
        return;
    }

    const snapshot = props.comments;
    emit(
        'change',
        props.comments.filter(
            (c) => c.uuid !== target.uuid && c.parent_uuid !== target.uuid,
        ),
    );

    if (!(await actions.comments.remove(target))) {
        emit('change', snapshot);
    }
};

const react = async (comment: IssueComment, emoji: string, on: boolean) => {
    const mine = summary();
    const optimistic = (reactions: IssueComment['reactions']) => {
        const existing = reactions.find((r) => r.emoji === emoji);

        if (on) {
            return existing
                ? reactions.map((r) =>
                      r.emoji === emoji
                          ? {
                                ...r,
                                count: r.count + 1,
                                mine: true,
                                users: [
                                    ...r.users,
                                    { id: mine.id, name: mine.name },
                                ],
                            }
                          : r,
                  )
                : [
                      ...reactions,
                      {
                          emoji,
                          count: 1,
                          mine: true,
                          users: [{ id: mine.id, name: mine.name }],
                      },
                  ];
        }

        return reactions
            .map((r) =>
                r.emoji === emoji
                    ? {
                          ...r,
                          count: r.count - 1,
                          mine: false,
                          users: r.users.filter((u) => u.id !== mine.id),
                      }
                    : r,
            )
            .filter((r) => r.count > 0);
    };

    emit(
        'change',
        props.comments.map((c) =>
            c.uuid === comment.uuid
                ? { ...c, reactions: optimistic(c.reactions) }
                : c,
        ),
    );
    const updated = await actions.comments.react(comment, emoji, on);

    if (updated) {
        emit(
            'change',
            props.comments.map((c) => (c.uuid === comment.uuid ? updated : c)),
        );
    }
};

const startReply = (comment: IssueComment) => {
    replyTo.value = comment;
    editor.value?.focus();
};

defineExpose({ focus: () => editor.value?.focus() });
</script>

<template>
    <section>
        <SectionLabel label="Comments">
            <template #meta>
                <span v-if="comments.length" class="font-normal normal-case">{{
                    comments.length
                }}</span>
            </template>
        </SectionLabel>

        <div v-if="roots.length" class="divide-y">
            <div v-for="comment in roots" :key="comment.uuid">
                <IssueCommentCard
                    :comment="comment"
                    :members="members"
                    :can-react="issue.can.comment"
                    :can-reply="issue.can.comment"
                    @edit="(b, m) => edit(comment, b, m)"
                    @remove="pendingDelete = comment"
                    @react="(e, on) => react(comment, e, on)"
                    @reply="startReply(comment)"
                />
                <IssueCommentCard
                    v-for="child in repliesOf(comment.uuid)"
                    :key="child.uuid"
                    :comment="child"
                    :members="members"
                    :can-react="issue.can.comment"
                    :can-reply="false"
                    reply
                    @edit="(b, m) => edit(child, b, m)"
                    @remove="pendingDelete = child"
                    @react="(e, on) => react(child, e, on)"
                />
            </div>
        </div>
        <p v-else class="mb-3 text-[12.5px] text-muted-foreground">
            No comments yet. Start the conversation.
        </p>

        <div
            v-if="issue.can.comment"
            class="sticky bottom-0 mt-3 bg-background pt-2 pb-4"
        >
            <div
                class="flex gap-2.5 rounded-xl border bg-card px-3 py-2 shadow-pane transition focus-within:border-brand/50"
            >
                <UserAvatar
                    :name="me.name"
                    :email="me.email"
                    :src="(me.avatar as string | null) ?? null"
                    :initials="String(me.initials ?? '')"
                    size="sm"
                    class="mt-1"
                />
                <div class="min-w-0 flex-1">
                    <p
                        v-if="replyTo"
                        class="flex items-center gap-2 text-[11px] text-muted-foreground"
                    >
                        Replying to {{ replyTo.user?.name ?? 'comment' }}
                        <button
                            type="button"
                            class="hover:text-foreground"
                            @click="replyTo = null"
                        >
                            ×
                        </button>
                    </p>
                    <RichTextEditor
                        ref="editor"
                        v-model="draft"
                        placeholder="Leave a comment… @ to mention"
                        :members="members"
                        compact
                        submit-shortcut
                        min-height="2.25rem"
                        @submit="submit"
                    />
                    <div class="flex items-center justify-end gap-2 pb-1">
                        <span class="text-[11px] text-muted-foreground"
                            ><kbd class="rounded border px-1">⌘↵</kbd> to
                            post</span
                        >
                        <Button
                            size="sm"
                            class="h-7 gap-1"
                            :disabled="busy || !draft.trim()"
                            @click="submit"
                            ><Send class="size-3.5" /> Comment</Button
                        >
                    </div>
                </div>
            </div>
        </div>

        <ConfirmDialog
            :open="pendingDelete !== null"
            title="Delete this comment?"
            description="Replies to it are deleted as well."
            confirm-label="Delete"
            destructive
            @update:open="(v) => !v && (pendingDelete = null)"
            @confirm="remove"
        />
    </section>
</template>
