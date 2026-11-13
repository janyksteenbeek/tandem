<script setup lang="ts">
import {
    CornerUpLeft,
    FileText,
    Paperclip,
    Pencil,
    RotateCw,
    SendHorizontal,
    X,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import type { Chat } from '@/composables/meet/useChat';
import { formatBytes } from '@/lib/meet/format';
import { cn } from '@/lib/utils';

/**
 * Where messages are written. Enter sends, Shift+Enter starts a new line, Escape backs out
 * of a reply or an edit. Files upload as soon as they are chosen and go out with the next
 * message.
 */
const props = defineProps<{
    chat: Chat;
    canUpload: boolean;
    disabled: boolean;
}>();

const text = ref('');
const textarea = ref<HTMLTextAreaElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const saving = ref(false);

const uploads = computed(() => props.chat.uploads.value);
const editing = computed(() => props.chat.editing.value);
const replyingTo = computed(() => props.chat.replyingTo.value);

const canSend = computed(() => {
    if (props.disabled || saving.value || props.chat.uploading.value) {
        return false;
    }

    if (editing.value) {
        return text.value.trim() !== '';
    }

    return (
        text.value.trim() !== '' ||
        uploads.value.some((upload) => upload.state === 'done')
    );
});

const resize = () => {
    const element = textarea.value;

    if (!element) {
        return;
    }

    element.style.height = 'auto';
    element.style.height = `${Math.min(element.scrollHeight, 144)}px`;
};

const focus = () => nextTick(() => textarea.value?.focus());

watch(text, () => nextTick(resize));

watch(editing, (message) => {
    text.value = message?.body ?? '';
    focus();
});

watch(replyingTo, (message) => {
    if (message) {
        focus();
    }
});

const submit = async () => {
    if (!canSend.value) {
        return;
    }

    if (editing.value) {
        saving.value = true;

        try {
            await props.chat.saveEdit(text.value.trim());
            text.value = '';
        } finally {
            saving.value = false;
        }

        return;
    }

    props.chat.send(text.value);
    text.value = '';
    focus();
};

const cancel = () => {
    if (editing.value) {
        props.chat.editing.value = null;
        text.value = '';
    } else {
        props.chat.replyingTo.value = null;
    }
};

const onKeydown = (event: KeyboardEvent) => {
    // Leave Enter alone while an input method is composing a character.
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void submit();
    } else if (event.key === 'Escape' && (editing.value || replyingTo.value)) {
        event.preventDefault();
        event.stopPropagation();
        cancel();
    }
};

const pick = (event: Event) => {
    const input = event.target as HTMLInputElement;

    if (input.files?.length) {
        props.chat.addFiles([...input.files]);
    }

    input.value = '';
};

const onPaste = (event: ClipboardEvent) => {
    const files = [...(event.clipboardData?.files ?? [])];

    if (files.length > 0 && props.canUpload) {
        event.preventDefault();
        props.chat.addFiles(files);
    }
};

defineExpose({ focus });
</script>

<template>
    <div class="border-t bg-card/60 p-3">
        <!-- Reply / edit context -->
        <div
            v-if="editing || replyingTo"
            class="mb-2 flex items-start gap-2 rounded-lg bg-brand-soft/60 px-2.5 py-1.5 text-xs"
        >
            <Pencil v-if="editing" class="mt-0.5 size-3 shrink-0 text-brand" />
            <CornerUpLeft v-else class="mt-0.5 size-3 shrink-0 text-brand" />
            <span class="min-w-0 flex-1">
                <span class="font-semibold">{{
                    editing
                        ? 'Editing message'
                        : `Replying to ${replyingTo?.author?.name ?? 'someone'}`
                }}</span>
                <span
                    v-if="!editing"
                    class="block truncate text-muted-foreground"
                    >{{ replyingTo?.body ?? 'Attachment' }}</span
                >
            </span>
            <button
                type="button"
                aria-label="Cancel"
                class="rounded p-0.5 text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                @click="cancel"
            >
                <X class="size-3.5" />
            </button>
        </div>

        <!-- Uploads -->
        <ul
            v-if="uploads.length"
            class="mb-2 flex flex-col gap-1.5"
            aria-label="Attachments"
        >
            <li
                v-for="upload in uploads"
                :key="upload.key"
                :class="
                    cn(
                        'relative flex items-center gap-2.5 overflow-hidden rounded-lg border bg-card px-2.5 py-1.5',
                        upload.state === 'failed' && 'border-destructive/40',
                    )
                "
                :data-state="upload.state"
                data-testid="upload"
            >
                <img
                    v-if="upload.previewUrl"
                    :src="upload.previewUrl"
                    alt=""
                    class="size-8 shrink-0 rounded-md object-cover"
                />
                <span
                    v-else
                    class="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"
                >
                    <FileText class="size-4" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-xs font-medium">{{
                        upload.name
                    }}</span>
                    <span
                        :class="
                            cn(
                                'block text-[11px]',
                                upload.state === 'failed'
                                    ? 'text-destructive'
                                    : 'text-muted-foreground',
                            )
                        "
                    >
                        {{
                            upload.state === 'failed'
                                ? upload.error
                                : upload.state === 'uploading'
                                  ? `Uploading… ${Math.round(upload.progress * 100)}%`
                                  : formatBytes(upload.size)
                        }}
                    </span>
                </span>
                <button
                    v-if="upload.state === 'failed'"
                    type="button"
                    aria-label="Retry upload"
                    class="rounded p-1 text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                    @click="chat.retryUpload(upload.key)"
                >
                    <RotateCw class="size-3.5" />
                </button>
                <button
                    type="button"
                    :aria-label="`Remove ${upload.name}`"
                    class="rounded p-1 text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                    @click="chat.removeUpload(upload.key)"
                >
                    <X class="size-3.5" />
                </button>
                <span
                    v-if="upload.state === 'uploading'"
                    class="absolute inset-x-0 bottom-0 h-0.5 bg-brand/15"
                    role="progressbar"
                    :aria-valuenow="Math.round(upload.progress * 100)"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <span
                        class="block h-full bg-brand transition-[width] duration-150"
                        :style="{ width: `${upload.progress * 100}%` }"
                    />
                </span>
            </li>
        </ul>

        <div
            class="flex items-end gap-1.5 rounded-xl border bg-background px-1.5 py-1.5 transition-shadow focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/30"
        >
            <template v-if="canUpload && !editing">
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    class="sr-only"
                    tabindex="-1"
                    aria-hidden="true"
                    data-testid="chat-file-input"
                    @change="pick"
                />
                <button
                    type="button"
                    aria-label="Attach files"
                    :disabled="disabled"
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60 disabled:opacity-40"
                    @click="fileInput?.click()"
                >
                    <Paperclip class="size-4" />
                </button>
            </template>
            <textarea
                ref="textarea"
                v-model="text"
                rows="1"
                :disabled="disabled"
                :placeholder="disabled ? 'Chat is closed' : 'Message everyone'"
                aria-label="Message"
                class="max-h-36 min-h-8 flex-1 resize-none bg-transparent px-1.5 py-1.5 text-[13.5px] leading-snug outline-none placeholder:text-muted-foreground disabled:opacity-50"
                data-testid="chat-input"
                @keydown="onKeydown"
                @paste="onPaste"
            />
            <button
                type="button"
                :aria-label="editing ? 'Save changes' : 'Send message'"
                :disabled="!canSend"
                class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand text-brand-foreground transition-[opacity,transform] outline-none hover:bg-brand/90 focus-visible:ring-2 focus-visible:ring-ring/60 active:scale-95 disabled:opacity-30"
                data-testid="chat-send"
                @click="submit"
            >
                <SendHorizontal class="size-4" />
            </button>
        </div>
        <p class="mt-1.5 px-1 text-[11px] text-muted-foreground">
            <kbd class="font-sans">Enter</kbd> to send ·
            <kbd class="font-sans">Shift</kbd>+<kbd class="font-sans"
                >Enter</kbd
            >
            for a new line
        </p>
    </div>
</template>
