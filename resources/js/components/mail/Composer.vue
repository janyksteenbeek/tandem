<script setup lang="ts">
import Image from '@tiptap/extension-image';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import Underline from '@tiptap/extension-underline';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import {
    ChevronDown,
    ImagePlus,
    Lock,
    Paperclip,
    Send,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AttachmentChip from '@/components/mail/AttachmentChip.vue';
import RecipientInput from '@/components/mail/RecipientInput.vue';
import RichTextToolbar from '@/components/mail/RichTextToolbar.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useDraft, type DraftPatch } from '@/composables/useDraft';
import { cn } from '@/lib/utils';
import type { Draft, EmailAddress, InboxAccount } from '@/types';

const props = defineProps<{
    draft: Draft;
    accounts: InboxAccount[];
    isShared: boolean;
    showSubject?: boolean;
    compact?: boolean;
    fallbackFrom?: { name: string; email: string | null } | null;
}>();

const emit = defineEmits<{
    sent: [payload: { conversation_uuid: string }];
    discarded: [];
    typing: [];
    stoppedTyping: [];
}>();

const {
    draft,
    saving,
    sending,
    savedLabel,
    update,
    upload,
    removeAttachment,
    send,
    discard,
} = useDraft(props.draft);

const showCc = ref((props.draft.cc?.length ?? 0) > 0);
const showBcc = ref((props.draft.bcc?.length ?? 0) > 0);
const fileInput = ref<HTMLInputElement | null>(null);
const imageInput = ref<HTMLInputElement | null>(null);

const identities = computed(() =>
    props.accounts.flatMap((account) =>
        account.identities.map((identity) => ({
            ...identity,
            accountUuid: account.uuid,
            provider: account.provider,
        })),
    ),
);

const currentIdentity = computed(
    () =>
        identities.value.find(
            (i) => i.id === draft.value?.sender_identity_id,
        ) ?? identities.value[0],
);

const editor = useEditor({
    content: props.draft.html_body ?? '<p></p>',
    extensions: [
        StarterKit.configure({ heading: { levels: [2, 3] } }),
        Underline,
        Link.configure({
            openOnClick: false,
            autolink: true,
            HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
        }),
        Image.configure({ inline: true, allowBase64: false }),
        Placeholder.configure({
            placeholder: 'Write your message… (⌘↵ to send)',
        }),
    ],
    editorProps: {
        attributes: {
            class: cn(
                'prose-tandem scrollbar-thin px-4 py-3 outline-none',
                props.compact
                    ? 'max-h-72 min-h-[7rem] overflow-y-auto'
                    : 'max-h-[50vh] min-h-[12rem] overflow-y-auto',
            ),
        },
        handleKeyDown: (_view, event) => {
            if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                void submit('open');

                return true;
            }

            return false;
        },
        handleDrop: (_view, event) => {
            const files = Array.from(event.dataTransfer?.files ?? []);

            if (files.length === 0) {
                return false;
            }

            event.preventDefault();
            files.forEach(
                (file) => void addFile(file, file.type.startsWith('image/')),
            );

            return true;
        },
        handlePaste: (_view, event) => {
            const files = Array.from(event.clipboardData?.files ?? []);

            if (files.length === 0) {
                return false;
            }

            files.forEach(
                (file) => void addFile(file, file.type.startsWith('image/')),
            );

            return true;
        },
    },
    autofocus: 'start',
    onUpdate: ({ editor: instance }) => {
        update({ html_body: instance.getHTML() });
        emit('typing');
    },
    onBlur: () => emit('stoppedTyping'),
});

const setRecipients = (field: 'to' | 'cc' | 'bcc', value: EmailAddress[]) =>
    update({ [field]: value } as DraftPatch);

const addFile = async (file: File, inline = false) => {
    const attachment = await upload(file, inline);

    if (attachment && inline && attachment.url && editor.value) {
        editor.value
            .chain()
            .focus()
            .setImage({ src: attachment.url, alt: attachment.filename })
            .run();
    }
};

const onFiles = (event: Event, inline = false) => {
    const files = Array.from((event.target as HTMLInputElement).files ?? []);
    files.forEach((file) => void addFile(file, inline));
    (event.target as HTMLInputElement).value = '';
};

const canSend = computed(
    () => (draft.value?.to.length ?? 0) > 0 && !sending.value,
);

const submit = async (then: 'open' | 'close' | 'pending') => {
    if (!canSend.value) {
        return;
    }

    emit('stoppedTyping');
    const result = await send(then);

    if (result) {
        emit('sent', { conversation_uuid: result.conversation_uuid });
    }
};

const remove = async () => {
    emit('stoppedTyping');
    await discard();
    emit('discarded');
};

watch(
    () => props.draft.uuid,
    () => {
        draft.value = props.draft;
        editor.value?.commands.setContent(props.draft.html_body ?? '<p></p>');
    },
);

onBeforeUnmount(() => editor.value?.destroy());
</script>

<template>
    <div
        v-if="draft"
        class="flex flex-col rounded-xl border bg-card shadow-float"
    >
        <div
            v-if="draft.locked_by"
            class="flex items-center gap-2 rounded-t-xl border-b bg-warning/15 px-4 py-2 text-xs text-warning-foreground"
        >
            <Lock class="size-3.5" /> {{ draft.locked_by.name }} is editing this
            shared draft. Changes may collide.
        </div>

        <div class="px-4 pt-2">
            <div
                class="flex min-h-9 items-center gap-2 border-b border-border/70 text-sm"
            >
                <span class="w-10 shrink-0 text-xs text-muted-foreground"
                    >From</span
                >
                <DropdownMenu v-if="identities.length > 1">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-md py-1 pr-1 text-left hover:bg-accent"
                        >
                            <UserAvatar
                                :name="currentIdentity?.name"
                                :email="currentIdentity?.email"
                                size="xs"
                            />
                            <span class="truncate"
                                >{{ currentIdentity?.name }}
                                <span class="text-muted-foreground"
                                    >&lt;{{ currentIdentity?.email }}&gt;</span
                                ></span
                            >
                            <ChevronDown
                                class="size-3.5 text-muted-foreground"
                            />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-80">
                        <DropdownMenuItem
                            v-for="identity in identities"
                            :key="identity.id"
                            class="gap-2"
                            @click="
                                update({
                                    mail_account_uuid: identity.accountUuid,
                                    sender_identity_id: identity.id,
                                })
                            "
                        >
                            <UserAvatar
                                :name="identity.name"
                                :email="identity.email"
                                size="xs"
                            />
                            <span class="min-w-0"
                                ><span class="block truncate">{{
                                    identity.name
                                }}</span
                                ><span
                                    class="block truncate text-xs text-muted-foreground"
                                    >{{ identity.email }} ·
                                    {{ identity.provider }}</span
                                ></span
                            >
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <span v-else-if="currentIdentity" class="truncate text-sm"
                    >{{ currentIdentity.name }}
                    <span class="text-muted-foreground"
                        >&lt;{{ currentIdentity.email }}&gt;</span
                    ></span
                >
                <span v-else-if="fallbackFrom?.email" class="truncate text-sm"
                    >{{ fallbackFrom.name }}
                    <span class="text-muted-foreground"
                        >&lt;{{ fallbackFrom.email }}&gt;</span
                    ></span
                >
                <span v-else class="truncate text-sm text-warning-foreground"
                    >No sending address yet — add an identity or connect a
                    channel in Settings.</span
                >
                <div class="ml-auto flex items-center gap-2 text-[11px]">
                    <button
                        v-if="!showCc"
                        type="button"
                        class="text-muted-foreground hover:text-foreground"
                        @click="showCc = true"
                    >
                        Cc
                    </button>
                    <button
                        v-if="!showBcc"
                        type="button"
                        class="text-muted-foreground hover:text-foreground"
                        @click="showBcc = true"
                    >
                        Bcc
                    </button>
                </div>
            </div>

            <RecipientInput
                :model-value="draft.to"
                label="To"
                placeholder="Recipients"
                :autofocus="draft.to.length === 0"
                @update:model-value="(v) => setRecipients('to', v)"
            />
            <RecipientInput
                v-if="showCc"
                :model-value="draft.cc"
                label="Cc"
                @update:model-value="(v) => setRecipients('cc', v)"
            />
            <RecipientInput
                v-if="showBcc"
                :model-value="draft.bcc"
                label="Bcc"
                @update:model-value="(v) => setRecipients('bcc', v)"
            />

            <div
                v-if="
                    showSubject ||
                    draft.kind === 'new' ||
                    draft.kind === 'forward'
                "
                class="flex items-center gap-2 border-b border-border/70"
            >
                <span class="w-10 shrink-0 text-xs text-muted-foreground"
                    >Subject</span
                >
                <Input
                    :model-value="draft.subject ?? ''"
                    placeholder="Subject"
                    class="h-9 border-0 px-0 shadow-none focus-visible:ring-0"
                    @update:model-value="(v) => update({ subject: String(v) })"
                />
            </div>
        </div>

        <EditorContent :editor="editor" />

        <div
            v-if="draft.attachments.length"
            class="flex flex-wrap gap-2 px-4 pb-2"
        >
            <AttachmentChip
                v-for="attachment in draft.attachments.filter(
                    (a) => !a.is_inline,
                )"
                :key="attachment.uuid"
                :filename="attachment.filename"
                :mime-type="attachment.mime_type"
                :size="attachment.size_bytes"
                removable
                @remove="removeAttachment(attachment)"
            />
        </div>

        <div class="flex flex-wrap items-center gap-1 border-t px-2 py-2">
            <RichTextToolbar :editor="editor" />
            <span class="mx-1 h-5 w-px bg-border" />
            <Tooltip>
                <TooltipTrigger as-child
                    ><Button
                        variant="ghost"
                        size="icon"
                        class="size-7"
                        @click="fileInput?.click()"
                        ><Paperclip class="size-3.5" /></Button
                ></TooltipTrigger>
                <TooltipContent>Attach files</TooltipContent>
            </Tooltip>
            <Tooltip>
                <TooltipTrigger as-child
                    ><Button
                        variant="ghost"
                        size="icon"
                        class="size-7"
                        @click="imageInput?.click()"
                        ><ImagePlus class="size-3.5" /></Button
                ></TooltipTrigger>
                <TooltipContent>Insert inline image</TooltipContent>
            </Tooltip>
            <input
                ref="fileInput"
                type="file"
                multiple
                class="hidden"
                @change="onFiles($event)"
            />
            <input
                ref="imageInput"
                type="file"
                accept="image/*"
                class="hidden"
                @change="onFiles($event, true)"
            />

            <label
                v-if="isShared"
                class="ml-2 flex items-center gap-1.5 text-[11px] text-muted-foreground"
            >
                <Switch
                    :model-value="draft.is_shared"
                    class="scale-75"
                    @update:model-value="
                        (v) => update({ is_shared: Boolean(v) })
                    "
                />
                <Users class="size-3" /> Shared draft
            </label>

            <span
                class="ml-auto text-[11px] text-muted-foreground"
                :class="saving && 'animate-pulse'"
                >{{ savedLabel }}</span
            >

            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7 text-muted-foreground hover:text-destructive"
                        aria-label="Discard draft"
                        @click="remove"
                        ><Trash2 class="size-3.5"
                    /></Button>
                </TooltipTrigger>
                <TooltipContent>Discard draft</TooltipContent>
            </Tooltip>

            <div class="flex overflow-hidden rounded-lg">
                <Button
                    size="sm"
                    class="h-8 gap-1.5 rounded-r-none bg-brand text-brand-foreground hover:bg-brand/90"
                    :disabled="!canSend"
                    @click="submit('open')"
                >
                    <Send class="size-3.5" />
                    {{ sending ? 'Sending…' : 'Send' }}
                </Button>
                <DropdownMenu v-if="isShared">
                    <DropdownMenuTrigger as-child>
                        <Button
                            size="sm"
                            class="h-8 w-7 rounded-l-none border-l border-brand-foreground/20 bg-brand px-0 text-brand-foreground hover:bg-brand/90"
                            :disabled="!canSend"
                            aria-label="More send options"
                        >
                            <ChevronDown class="size-3.5" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem @click="submit('close')"
                            >Send &amp; mark done</DropdownMenuItem
                        >
                        <DropdownMenuItem @click="submit('pending')"
                            >Send &amp; mark waiting</DropdownMenuItem
                        >
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </div>
</template>
