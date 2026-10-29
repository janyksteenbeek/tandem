<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import {
    Archive,
    ArrowLeft,
    ArrowRightLeft,
    Forward,
    Inbox as InboxIcon,
    Mail,
    MailOpen,
    MessageSquareText,
    MoreHorizontal,
    PanelRight,
    Reply,
    ReplyAll,
    ShieldAlert,
    Star,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AssigneePicker from '@/components/mail/AssigneePicker.vue';
import CommentCard from '@/components/mail/CommentCard.vue';
import CommentComposer from '@/components/mail/CommentComposer.vue';
import Composer from '@/components/mail/Composer.vue';
import ContextSidebar from '@/components/mail/ContextSidebar.vue';
import EventRow from '@/components/mail/EventRow.vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import MessageCard from '@/components/mail/MessageCard.vue';
import PriorityPicker from '@/components/mail/PriorityPicker.vue';
import ShareDialog from '@/components/mail/ShareDialog.vue';
import SnoozeMenu from '@/components/mail/SnoozeMenu.vue';
import StatusControl from '@/components/mail/StatusControl.vue';
import TagPicker from '@/components/mail/TagPicker.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useConversationActions } from '@/composables/useConversationActions';
import { useDraft } from '@/composables/useDraft';
import { useKeyboard } from '@/composables/useKeyboard';
import { usePresence } from '@/composables/usePresence';
import { api, reportError } from '@/lib/http';
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type {
    Comment,
    Conversation,
    ConversationEvent,
    ConversationStatus,
    Draft,
    DraftKind,
    Inbox,
    Message,
    ShareTarget,
    Tag,
    UserSummary,
} from '@/types';

const props = defineProps<{
    conversation: Conversation;
    inbox: Inbox;
    members: UserSummary[];
    tags: Tag[];
    shareTargets: ShareTarget[] | null;
    isMobile: boolean;
}>();

const emit = defineEmits<{
    updated: [conversation: Conversation];
    back: [];
    removed: [uuid: string];
    loadShareTargets: [];
}>();

const page = usePage();
const me = computed<UserSummary>(() => ({
    id: page.props.auth.user.id,
    name: page.props.auth.user.name,
    email: page.props.auth.user.email,
    initials: (page.props.auth.user.initials as string) ?? '',
    avatar: (page.props.auth.user.avatar as string | null) ?? null,
}));

const local = ref<Conversation>(props.conversation);
const expanded = ref<Set<string>>(new Set());
const composerMode = ref<'none' | 'reply' | 'comment'>('none');
const activeDraft = ref<Draft | null>(null);
const contextOpen = ref(
    typeof window !== 'undefined' ? window.innerWidth >= 1400 : false,
);
const shareOpen = ref(false);
const shareBusy = ref(false);
const commentBusy = ref(false);
const editingComment = ref<Comment | null>(null);
const scroller = ref<HTMLElement | null>(null);
const commentComposer = ref<InstanceType<typeof CommentComposer> | null>(null);

const isShared = computed(() => props.inbox.type === 'shared');
const canAct = computed(() => local.value.can.act);
const conversationUuid = computed(() => local.value.uuid);

const { viewers, typing, whisperTyping, whisperStopped } = usePresence(
    conversationUuid,
    me,
);
const othersViewing = computed(() =>
    viewers.value.filter((v) => v.id !== me.value.id),
);
const someoneReplying = computed(() =>
    typing.value.find((t) => t.mode === 'reply'),
);

const actions = useConversationActions((updated) => {
    local.value = { ...local.value, ...updated };
    emit('updated', local.value);
});

const { create: createDraft } = useDraft();

const timeline = computed(() => {
    const items: Array<{
        key: string;
        at: string;
        kind: 'message' | 'comment' | 'event';
        message?: Message;
        comment?: Comment;
        event?: ConversationEvent;
    }> = [];

    local.value.messages.forEach((message) =>
        items.push({
            key: `m-${message.uuid}`,
            at: message.sent_at ?? '',
            kind: 'message',
            message,
        }),
    );
    local.value.comments.forEach((comment) =>
        items.push({
            key: `c-${comment.uuid}`,
            at: comment.created_at,
            kind: 'comment',
            comment,
        }),
    );
    local.value.events
        .filter(
            (event) =>
                !['message_received', 'message_sent', 'comment_added'].includes(
                    event.type,
                ),
        )
        .forEach((event) =>
            items.push({
                key: `e-${event.id}`,
                at: event.created_at,
                kind: 'event',
                event,
            }),
        );

    return items.sort((a, b) => a.at.localeCompare(b.at));
});

const resetExpanded = () => {
    const messages = local.value.messages;
    const set = new Set<string>();

    if (messages.length) {
        set.add(messages[messages.length - 1].uuid);
        messages.filter((m) => !m.is_read).forEach((m) => set.add(m.uuid));
    }

    expanded.value = set;
};

const scrollToBottom = () =>
    nextTick(() =>
        scroller.value?.scrollTo({
            top: scroller.value.scrollHeight,
            behavior: 'smooth',
        }),
    );

watch(
    () => props.conversation,
    (value) => {
        local.value = value;
        resetExpanded();
        composerMode.value = 'none';
        editingComment.value = null;
        activeDraft.value =
            value.drafts.find((d) => d.user.id === me.value.id) ??
            value.drafts.find((d) => d.is_shared) ??
            null;

        if (activeDraft.value) {
            composerMode.value = 'reply';
        }

        if (!value.is_read) {
            void actions.markRead(value.uuid, true, () => {
                local.value.is_read = true;

                return () => (local.value.is_read = false);
            });
        }
    },
    { immediate: true },
);

useEcho(
    `conversation.${props.conversation.uuid}`,
    [
        '.message.added',
        '.comment.created',
        '.comment.updated',
        '.comment.deleted',
        '.activity.added',
        '.message.delivery',
        '.conversation.updated',
    ],
    () => {
        void refresh();
    },
    [() => props.conversation.uuid],
);

let refreshing = false;

const refresh = async () => {
    if (refreshing) {
        return;
    }

    refreshing = true;

    try {
        const data = await api.get<{ conversation: Conversation }>(
            `/conversations/${local.value.uuid}`,
        );
        const hadMessages = local.value.messages.length;
        local.value = { ...data.conversation, drafts: local.value.drafts };
        emit('updated', local.value);

        if (data.conversation.messages.length > hadMessages) {
            expanded.value.add(
                data.conversation.messages[
                    data.conversation.messages.length - 1
                ].uuid,
            );
            scrollToBottom();
        }
    } finally {
        refreshing = false;
    }
};

const toggleMessage = (message: Message) => {
    if (expanded.value.has(message.uuid)) {
        expanded.value.delete(message.uuid);
    } else {
        expanded.value.add(message.uuid);
    }

    expanded.value = new Set(expanded.value);
};

const startReply = async (kind: DraftKind, message?: Message) => {
    if (!canAct.value) {
        return;
    }

    try {
        const draft = await createDraft({
            inbox:
                props.inbox.uuid === 'all'
                    ? local.value.inbox_uuid
                    : props.inbox.uuid,
            conversation: local.value.uuid,
            kind,
            in_reply_to: message?.uuid ?? null,
            is_shared: isShared.value,
        });

        activeDraft.value = draft;
        composerMode.value = 'reply';
        scrollToBottom();
    } catch (error) {
        reportError(error, 'Could not start a reply.');
    }
};

const onSent = async () => {
    activeDraft.value = null;
    composerMode.value = 'none';
    whisperStopped();
    await refresh();
};

const onDiscarded = () => {
    activeDraft.value = null;
    composerMode.value = 'none';
    local.value.drafts = local.value.drafts.filter(
        (d) => d.uuid !== activeDraft.value?.uuid,
    );
};

const openComment = async () => {
    composerMode.value = 'comment';
    await nextTick();
    commentComposer.value?.focus();
    scrollToBottom();
};

const postComment = async (body: string, mentions: number[]) => {
    commentBusy.value = true;

    try {
        if (editingComment.value) {
            const data = await api.patch<{ comment: Comment }>(
                `/comments/${editingComment.value.uuid}`,
                { body, mentions },
            );
            local.value.comments = local.value.comments.map((c) =>
                c.uuid === data.comment.uuid ? data.comment : c,
            );
            editingComment.value = null;
        } else {
            const data = await api.post<{ comment: Comment }>(
                `/conversations/${local.value.uuid}/comments`,
                { body, mentions },
            );
            local.value.comments = [...local.value.comments, data.comment];
            local.value.comment_count += 1;
        }

        whisperStopped();
        emit('updated', local.value);
        scrollToBottom();
    } catch (error) {
        reportError(error, 'Could not post comment.');
    } finally {
        commentBusy.value = false;
    }
};

const removeComment = async (comment: Comment) => {
    local.value.comments = local.value.comments.filter(
        (c) => c.uuid !== comment.uuid,
    );

    try {
        await api.delete(`/comments/${comment.uuid}`);
    } catch (error) {
        reportError(error);
    }
};

const setStatus = (status: ConversationStatus) => {
    void actions.setStatus(local.value.uuid, status, undefined, () => {
        const previous = local.value.status;
        local.value.status = status;

        return () => (local.value.status = previous);
    });
};

const snooze = (until: string) => {
    void actions.setStatus(local.value.uuid, 'snoozed', until, () => {
        const previous = {
            status: local.value.status,
            until: local.value.snoozed_until,
        };
        local.value.status = 'snoozed';
        local.value.snoozed_until = until;

        return () => {
            local.value.status = previous.status;
            local.value.snoozed_until = previous.until;
        };
    });
};

const assign = (userId: number | null) => {
    void actions.assign(local.value.uuid, userId, () => {
        const previous = local.value.assignee;
        local.value.assignee = userId
            ? (props.members.find((m) => m.id === userId) ??
              (userId === me.value.id ? me.value : null))
            : null;

        return () => (local.value.assignee = previous);
    });
};

const toggleStar = () => {
    void actions.star(local.value.uuid, !local.value.is_starred, () => {
        local.value.is_starred = !local.value.is_starred;

        return () => (local.value.is_starred = !local.value.is_starred);
    });
};

const mailbox = async (
    action: 'archive' | 'inbox' | 'trash' | 'restore' | 'spam' | 'not_spam',
) => {
    const result = await actions.mailbox(local.value.uuid, action);

    if (result && ['archive', 'trash', 'spam'].includes(action)) {
        emit('removed', local.value.uuid);
    }
};

const toggleTag = (tag: Tag | null, attached: boolean, name?: string) => {
    void actions.toggleTag(
        local.value.uuid,
        tag?.id ?? null,
        attached,
        name,
        () => {
            const previous = local.value.tags;

            if (tag) {
                local.value.tags = attached
                    ? [...previous, tag]
                    : previous.filter((t) => t.id !== tag.id);
            }

            return () => (local.value.tags = previous);
        },
    );
};

const share = async (
    inbox: string,
    mode: 'share' | 'transfer',
    note: string,
) => {
    shareBusy.value = true;
    const result = await actions.share(local.value.uuid, inbox, mode, note);
    shareBusy.value = false;

    if (result) {
        shareOpen.value = false;

        if (mode === 'transfer' && props.inbox.type === 'shared') {
            emit('removed', local.value.uuid);
        }
    }
};

const openShare = () => {
    if (!props.shareTargets) {
        emit('loadShareTargets');
    }

    setTimeout(() => (shareOpen.value = true), 60);
};

const viewSource = (message: Message) => {
    const win = window.open('', '_blank');

    if (!win) {
        toast.error('Pop-up blocked.');

        return;
    }

    win.document.write(
        `<pre style="white-space:pre-wrap;font:13px ui-monospace,monospace;padding:16px">${(message.text_body ?? '').replace(/</g, '&lt;')}</pre>`,
    );
};

const destroy = async () => {
    if (
        !window.confirm(
            'Delete this conversation permanently from Tandem? This does not delete it at your mail provider.',
        )
    ) {
        return;
    }

    if (await actions.destroy(local.value.uuid)) {
        emit('removed', local.value.uuid);
    }
};

useKeyboard(() => [
    { keys: 'r', description: 'Reply', handler: () => startReply('reply') },
    {
        keys: 'a',
        description: 'Reply all',
        handler: () => startReply('reply_all'),
    },
    { keys: 'f', description: 'Forward', handler: () => startReply('forward') },
    { keys: 'm', description: 'Comment', handler: () => void openComment() },
    {
        keys: 'e',
        description: 'Archive',
        handler: () => void mailbox('archive'),
    },
    {
        keys: 'shift+3',
        description: 'Trash',
        handler: () => void mailbox('trash'),
    },
    { keys: 's', description: 'Star', handler: toggleStar },
    {
        keys: 'u',
        description: 'Mark unread',
        handler: () => {
            void actions.markRead(local.value.uuid, false);
            emit('back');
        },
    },
    {
        keys: 'd',
        description: 'Done',
        handler: () => isShared.value && setStatus('closed'),
    },
    {
        keys: 'h',
        description: 'Snooze until tomorrow',
        handler: () => {
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            tomorrow.setHours(9, 0, 0, 0);
            snooze(tomorrow.toISOString());
        },
    },
    {
        keys: 'i',
        description: 'Assign to me',
        handler: () => isShared.value && assign(me.value.id),
    },
    {
        keys: 'escape',
        description: 'Back',
        handler: () => {
            if (composerMode.value !== 'none' && !activeDraft.value) {
                composerMode.value = 'none';
            } else {
                emit('back');
            }
        },
    },
]);

const go = (url: string) => router.visit(url);
</script>

<template>
    <div class="flex h-full min-w-0">
        <div class="flex min-w-0 flex-1 flex-col">
            <header
                class="border-b bg-surface/80 px-4 py-2.5 backdrop-blur supports-[backdrop-filter]:bg-surface/60"
            >
                <div class="flex items-center gap-2">
                    <Button
                        v-if="isMobile"
                        variant="ghost"
                        size="icon"
                        class="size-8 shrink-0"
                        aria-label="Back"
                        @click="emit('back')"
                        ><ArrowLeft class="size-4"
                    /></Button>
                    <h2
                        class="min-w-0 flex-1 truncate text-[15px] font-semibold tracking-tight"
                        :title="local.subject ?? ''"
                    >
                        {{ local.subject || '(no subject)' }}
                    </h2>

                    <div
                        v-if="othersViewing.length"
                        class="flex items-center -space-x-1.5"
                        title="Also viewing"
                    >
                        <Tooltip
                            v-for="viewer in othersViewing.slice(0, 4)"
                            :key="viewer.id"
                        >
                            <TooltipTrigger as-child
                                ><span
                                    class="rounded-full ring-2 ring-background"
                                    ><UserAvatar
                                        :name="viewer.name"
                                        :email="viewer.email"
                                        :src="viewer.avatar"
                                        :initials="viewer.initials"
                                        size="sm" /></span
                            ></TooltipTrigger>
                            <TooltipContent
                                >{{ viewer.name }} is viewing</TooltipContent
                            >
                        </Tooltip>
                    </div>

                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-8"
                                :aria-label="
                                    local.is_starred ? 'Unstar' : 'Star'
                                "
                                @click="toggleStar"
                            >
                                <Star
                                    :class="
                                        cn(
                                            'size-4',
                                            local.is_starred
                                                ? 'fill-highlight text-highlight'
                                                : 'text-muted-foreground',
                                        )
                                    "
                                />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent
                            >Star
                            <kbd class="ml-1 rounded border px-1 text-[10px]"
                                >S</kbd
                            ></TooltipContent
                        >
                    </Tooltip>

                    <template v-if="isShared">
                        <PriorityPicker
                            :priority="local.priority"
                            :disabled="!canAct"
                            @change="
                                (p) =>
                                    actions.setPriority(local.uuid, p, () => {
                                        const prev = local.priority;
                                        local.priority = p;
                                        return () => (local.priority = prev);
                                    })
                            "
                        />
                        <SnoozeMenu
                            :snoozed-until="
                                local.status === 'snoozed'
                                    ? local.snoozed_until
                                    : null
                            "
                            :disabled="!canAct"
                            as-icon
                            @snooze="snooze"
                            @wake="setStatus('open')"
                        />
                        <AssigneePicker
                            :assignee="local.assignee"
                            :members="members"
                            :me="me"
                            :disabled="!canAct"
                            @assign="assign"
                        />
                        <StatusControl
                            :status="local.status"
                            :disabled="!canAct"
                            @change="setStatus"
                        />
                    </template>
                    <template v-else>
                        <SnoozeMenu
                            :snoozed-until="
                                local.status === 'snoozed'
                                    ? local.snoozed_until
                                    : null
                            "
                            :disabled="!canAct"
                            as-icon
                            @snooze="snooze"
                            @wake="setStatus('open')"
                        />
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    :disabled="!canAct"
                                    aria-label="Archive"
                                    @click="
                                        mailbox(
                                            local.is_archived
                                                ? 'inbox'
                                                : 'archive',
                                        )
                                    "
                                >
                                    <InboxIcon
                                        v-if="local.is_archived"
                                        class="size-4"
                                    /><Archive v-else class="size-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent
                                >{{
                                    local.is_archived
                                        ? 'Move to inbox'
                                        : 'Archive'
                                }}
                                <kbd
                                    class="ml-1 rounded border px-1 text-[10px]"
                                    >E</kbd
                                ></TooltipContent
                            >
                        </Tooltip>
                    </template>

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child
                            ><Button
                                variant="ghost"
                                size="icon"
                                class="size-8"
                                aria-label="More"
                                ><MoreHorizontal class="size-4" /></Button
                        ></DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <DropdownMenuItem
                                v-if="local.can.share"
                                class="gap-2"
                                @click="openShare"
                                ><Users class="size-4" /> Share or transfer to a
                                team</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                class="gap-2"
                                @click="
                                    actions.markRead(local.uuid, false);
                                    emit('back');
                                "
                                ><Mail class="size-4" /> Mark as unread
                                <kbd
                                    class="ml-auto rounded border px-1 text-[10px]"
                                    >U</kbd
                                ></DropdownMenuItem
                            >
                            <DropdownMenuItem
                                v-if="isShared"
                                class="gap-2"
                                @click="
                                    mailbox(
                                        local.is_archived ? 'inbox' : 'archive',
                                    )
                                "
                                ><Archive class="size-4" />
                                {{
                                    local.is_archived
                                        ? 'Move to inbox'
                                        : 'Archive'
                                }}</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                class="gap-2"
                                @click="
                                    mailbox(local.is_spam ? 'not_spam' : 'spam')
                                "
                                ><ShieldAlert class="size-4" />
                                {{
                                    local.is_spam ? 'Not spam' : 'Mark as spam'
                                }}</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                v-if="local.is_trashed"
                                class="gap-2"
                                @click="mailbox('restore')"
                                ><MailOpen class="size-4" /> Restore from
                                trash</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                v-else
                                class="gap-2"
                                @click="mailbox('trash')"
                                ><Trash2 class="size-4" /> Move to trash
                                <kbd
                                    class="ml-auto rounded border px-1 text-[10px]"
                                    >#</kbd
                                ></DropdownMenuItem
                            >
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                class="gap-2"
                                @click="contextOpen = !contextOpen"
                                ><PanelRight class="size-4" />
                                {{
                                    contextOpen ? 'Hide' : 'Show'
                                }}
                                context</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                v-if="local.origin_inbox"
                                class="gap-2"
                                @click="
                                    go(
                                        `/${page.props.currentOrganization?.slug}/mail/${local.origin_inbox.uuid}/all/${local.uuid}`,
                                    )
                                "
                                ><ArrowRightLeft class="size-4" /> Open in
                                {{ local.origin_inbox.name }}</DropdownMenuItem
                            >
                            <DropdownMenuSeparator v-if="local.can.delete" />
                            <DropdownMenuItem
                                v-if="local.can.delete"
                                class="gap-2 text-destructive"
                                @click="destroy"
                                ><Trash2 class="size-4" /> Delete
                                permanently</DropdownMenuItem
                            >
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                    <span
                        class="flex items-center gap-1.5 rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground"
                    >
                        <InboxDot
                            :color="inbox.uuid === 'all' ? 'plum' : inbox.color"
                            size="sm"
                        />
                        {{ local.inbox_name }}
                        <span v-if="local.shares.length" class="text-brand"
                            >+{{ local.shares.length }} shared</span
                        >
                    </span>
                    <span
                        v-for="tag in local.tags"
                        :key="tag.id"
                        :class="
                            cn(
                                'group/tag flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium text-(--chip)',
                                chipClass(tag.color),
                            )
                        "
                        style="
                            background: color-mix(
                                in oklch,
                                var(--chip) 14%,
                                transparent
                            );
                        "
                    >
                        {{ tag.name }}
                        <button
                            v-if="canAct"
                            type="button"
                            class="hidden group-hover/tag:inline hover:opacity-70"
                            aria-label="Remove tag"
                            @click="toggleTag(tag, false)"
                        >
                            ×
                        </button>
                    </span>
                    <TagPicker
                        :selected="local.tags"
                        :available="tags"
                        :disabled="!canAct"
                        @toggle="toggleTag"
                    />
                    <span
                        v-if="local.status === 'snoozed' && local.snoozed_until"
                        class="ml-auto text-[11px] text-highlight-foreground"
                        >Snoozed · wakes
                        {{
                            new Date(local.snoozed_until).toLocaleString()
                        }}</span
                    >
                </div>
            </header>

            <div
                ref="scroller"
                class="scrollbar-thin flex-1 overflow-y-auto bg-surface-sunken/40 px-4 py-4"
            >
                <div class="mx-auto flex max-w-4xl flex-col gap-3">
                    <template v-for="item in timeline" :key="item.key">
                        <MessageCard
                            v-if="item.kind === 'message' && item.message"
                            :message="item.message"
                            :expanded="expanded.has(item.message.uuid)"
                            :can-act="canAct"
                            @toggle="toggleMessage(item.message)"
                            @reply="(kind) => startReply(kind, item.message)"
                            @view-source="viewSource(item.message)"
                        />
                        <CommentCard
                            v-else-if="item.kind === 'comment' && item.comment"
                            :comment="item.comment"
                            @edit="
                                (c) => {
                                    editingComment = c;
                                    composerMode = 'comment';
                                }
                            "
                            @remove="removeComment"
                        />
                        <EventRow
                            v-else-if="item.kind === 'event' && item.event"
                            :event="item.event"
                        />
                    </template>

                    <div
                        v-if="someoneReplying"
                        class="flex animate-rise items-center gap-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs text-warning-foreground"
                    >
                        <UserAvatar
                            :name="someoneReplying.user.name"
                            :email="someoneReplying.user.email"
                            :src="someoneReplying.user.avatar"
                            :initials="someoneReplying.user.initials"
                            size="xs"
                        />
                        <span class="font-medium"
                            >{{ someoneReplying.user.name }} is writing a
                            reply</span
                        >
                        <span class="flex gap-0.5"
                            ><span
                                class="size-1 animate-bounce rounded-full bg-current" /><span
                                class="size-1 animate-bounce rounded-full bg-current [animation-delay:120ms]" /><span
                                class="size-1 animate-bounce rounded-full bg-current [animation-delay:240ms]"
                        /></span>
                        <span class="ml-auto"
                            >Coordinate to avoid double replies.</span
                        >
                    </div>

                    <div
                        v-if="typing.some((t) => t.mode === 'comment')"
                        class="px-1 text-[11px] text-muted-foreground"
                    >
                        {{
                            typing
                                .filter((t) => t.mode === 'comment')
                                .map((t) => t.user.name)
                                .join(', ')
                        }}
                        is typing a comment…
                    </div>

                    <div
                        v-if="composerMode === 'reply' && activeDraft"
                        class="mt-2"
                    >
                        <Composer
                            :key="activeDraft.uuid"
                            :draft="activeDraft"
                            :accounts="inbox.accounts"
                            :is-shared="isShared"
                            :fallback-from="{
                                name: inbox.name,
                                email: inbox.email_address,
                            }"
                            compact
                            @sent="onSent"
                            @discarded="onDiscarded"
                            @typing="whisperTyping('reply')"
                            @stopped-typing="whisperStopped"
                        />
                    </div>

                    <div v-if="composerMode === 'comment'" class="mt-2">
                        <CommentComposer
                            ref="commentComposer"
                            :members="members"
                            :inbox-name="local.inbox_name"
                            :busy="commentBusy"
                            autofocus
                            @submit="postComment"
                            @typing="whisperTyping('comment')"
                            @cancel="
                                composerMode = 'none';
                                editingComment = null;
                            "
                        />
                    </div>
                </div>
            </div>

            <footer
                v-if="composerMode === 'none'"
                class="border-t bg-surface/80 px-4 py-2.5"
            >
                <div class="mx-auto flex max-w-4xl items-center gap-2">
                    <Button
                        v-if="canAct"
                        variant="outline"
                        class="h-9 min-w-0 flex-1 justify-start gap-2 rounded-xl text-sm text-muted-foreground shadow-none hover:text-foreground"
                        @click="startReply('reply')"
                    >
                        <Reply class="size-4 shrink-0" />
                        <span class="truncate"
                            >Reply to
                            {{
                                local.counterpart?.name ||
                                local.counterpart?.email ||
                                'thread'
                            }}…</span
                        >
                        <kbd
                            class="ml-auto hidden rounded border px-1 text-[10px] sm:inline"
                            >R</kbd
                        >
                    </Button>
                    <p v-else class="flex-1 text-xs text-muted-foreground">
                        You have read-only access to this inbox.
                    </p>
                    <Tooltip v-if="canAct">
                        <TooltipTrigger as-child
                            ><Button
                                variant="outline"
                                size="icon"
                                class="hidden size-9 shrink-0 rounded-xl shadow-none sm:inline-flex"
                                aria-label="Reply all"
                                @click="startReply('reply_all')"
                                ><ReplyAll class="size-4" /></Button
                        ></TooltipTrigger>
                        <TooltipContent
                            >Reply all
                            <kbd class="ml-1 rounded border px-1 text-[10px]"
                                >A</kbd
                            ></TooltipContent
                        >
                    </Tooltip>
                    <Tooltip v-if="canAct">
                        <TooltipTrigger as-child
                            ><Button
                                variant="outline"
                                size="icon"
                                class="hidden size-9 shrink-0 rounded-xl shadow-none sm:inline-flex"
                                aria-label="Forward"
                                @click="startReply('forward')"
                                ><Forward class="size-4" /></Button
                        ></TooltipTrigger>
                        <TooltipContent
                            >Forward
                            <kbd class="ml-1 rounded border px-1 text-[10px]"
                                >F</kbd
                            ></TooltipContent
                        >
                    </Tooltip>
                    <Tooltip v-if="canAct">
                        <TooltipTrigger as-child>
                            <Button
                                variant="outline"
                                class="h-9 shrink-0 gap-2 rounded-xl border-highlight/50 bg-highlight/10 text-sm shadow-none hover:bg-highlight/20"
                                @click="openComment"
                            >
                                <MessageSquareText
                                    class="size-4 text-highlight-foreground"
                                />
                                <span class="hidden sm:inline">Comment</span>
                                <kbd
                                    class="hidden rounded border px-1 text-[10px] sm:inline"
                                    >M</kbd
                                >
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent
                            >Internal note for teammates — never
                            emailed</TooltipContent
                        >
                    </Tooltip>
                </div>
            </footer>
        </div>

        <ContextSidebar
            v-if="contextOpen && !isMobile"
            :conversation="local"
            @close="contextOpen = false"
            @follow="(f) => actions.follow(local.uuid, f)"
            @unshare="(uuid) => actions.unshare(local.uuid, uuid)"
            @compose="() => {}"
        />

        <ShareDialog
            v-model:open="shareOpen"
            :targets="shareTargets ?? []"
            :can-move="local.can.move"
            :origin-is-personal="inbox.type === 'personal'"
            :busy="shareBusy"
            @confirm="share"
        />
    </div>
</template>
