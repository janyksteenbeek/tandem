<script setup lang="ts">
import { ArrowDown, MessagesSquare, Upload } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import ChatComposer from '@/components/meet/ChatComposer.vue';
import ChatMessageItem from '@/components/meet/ChatMessageItem.vue';
import SidePanel from '@/components/meet/SidePanel.vue';
import { Spinner } from '@/components/ui/spinner';
import type { Chat } from '@/composables/meet/useChat';
import { reportError } from '@/lib/http';
import { continuesPrevious } from '@/lib/meet/messages';
import type { LocalMessage } from '@/lib/meet/types';
import type { CallMe } from '@/types';

/**
 * The chat beside the meeting. Stays pinned to the newest message unless the reader has
 * scrolled up; then new messages announce themselves with a pill instead of yanking the
 * view away.
 */
const props = defineProps<{
    chat: Chat;
    me: CallMe | null;
}>();

const emit = defineEmits<{ close: []; reading: [atBottom: boolean] }>();

const scroller = ref<HTMLElement | null>(null);
const composer = ref<InstanceType<typeof ChatComposer> | null>(null);
const atBottom = ref(true);
const newBelow = ref(0);
const dragging = ref(false);
let dragDepth = 0;

const messages = computed(() => props.chat.messages.value);
const canChat = computed(() => props.me?.can.chat ?? false);
const canUpload = computed(() => props.me?.can.upload ?? false);

const toBottom = (smooth = false) => {
    const element = scroller.value;

    if (!element) {
        return;
    }

    element.scrollTo({
        top: element.scrollHeight,
        behavior: smooth ? 'smooth' : 'auto',
    });
    newBelow.value = 0;
};

const onScroll = () => {
    const element = scroller.value;

    if (!element) {
        return;
    }

    atBottom.value =
        element.scrollHeight - element.scrollTop - element.clientHeight < 48;
    emit('reading', atBottom.value);

    if (atBottom.value) {
        newBelow.value = 0;
        props.chat.markRead();
    }

    // Near the top: bring in older history, keeping what is on screen where it is.
    if (
        element.scrollTop < 80 &&
        props.chat.hasOlder.value &&
        !props.chat.loadingOlder.value
    ) {
        const before = element.scrollHeight;

        void props.chat.loadOlder().then(() =>
            nextTick(() => {
                element.scrollTop += element.scrollHeight - before;
            }),
        );
    }
};

watch(
    () => messages.value.length,
    (length, previous) => {
        const added = length - (previous ?? 0);

        if (added <= 0) {
            return;
        }

        const newest = messages.value[length - 1];
        const mine = newest?.author?.uuid === props.me?.uuid;

        if (atBottom.value || mine) {
            void nextTick(() => {
                toBottom();
                props.chat.markRead();
            });
        } else if (newest?.state === 'sent') {
            newBelow.value += 1;
        }
    },
);

onMounted(async () => {
    if (!props.chat.loaded.value) {
        await props.chat.load();
    }

    await nextTick();
    toBottom();
    emit('reading', true);
    props.chat.markRead();
    composer.value?.focus();
});

const run = async (action: () => Promise<void>) => {
    try {
        await action();
    } catch (error) {
        reportError(error);
    }
};

const startReply = (message: LocalMessage) => {
    props.chat.editing.value = null;
    props.chat.replyingTo.value = message;
};

const startEdit = (message: LocalMessage) => {
    props.chat.replyingTo.value = null;
    props.chat.editing.value = message;
};

// --- Drag and drop ---------------------------------------------------------------------

const hasFiles = (event: DragEvent) =>
    [...(event.dataTransfer?.types ?? [])].includes('Files');

const onDragEnter = (event: DragEvent) => {
    if (!canUpload.value || !hasFiles(event)) {
        return;
    }

    dragDepth++;
    dragging.value = true;
};

const onDragLeave = () => {
    dragDepth = Math.max(0, dragDepth - 1);
    dragging.value = dragDepth > 0;
};

const onDrop = (event: DragEvent) => {
    dragDepth = 0;
    dragging.value = false;

    const files = [...(event.dataTransfer?.files ?? [])];

    if (canUpload.value && files.length > 0) {
        props.chat.addFiles(files);
        composer.value?.focus();
    }
};
</script>

<template>
    <SidePanel
        title="Chat"
        subtitle="Saved with the meeting"
        @close="emit('close')"
    >
        <div
            class="relative flex min-h-0 flex-1 flex-col"
            @dragenter.prevent="onDragEnter"
            @dragover.prevent
            @dragleave.prevent="onDragLeave"
            @drop.prevent="onDrop"
        >
            <div
                ref="scroller"
                class="min-h-0 flex-1 overflow-y-auto overscroll-contain pt-4 pb-2"
                role="log"
                aria-live="polite"
                aria-label="Messages"
                data-testid="chat-log"
                @scroll.passive="onScroll"
            >
                <div
                    v-if="chat.loadingOlder.value"
                    class="flex justify-center py-3"
                >
                    <Spinner class="size-4 text-muted-foreground" />
                </div>

                <div
                    v-if="!chat.loaded.value"
                    class="space-y-4 px-4 py-3"
                    aria-label="Loading messages"
                >
                    <div
                        v-for="row in 4"
                        :key="row"
                        class="flex animate-pulse gap-2.5"
                    >
                        <div class="size-7 rounded-full bg-muted" />
                        <div class="flex-1 space-y-1.5">
                            <div class="h-3 w-24 rounded bg-muted" />
                            <div
                                class="h-3 rounded bg-muted"
                                :style="{ width: `${40 + row * 12}%` }"
                            />
                        </div>
                    </div>
                </div>

                <div
                    v-else-if="messages.length === 0"
                    class="flex h-full flex-col items-center justify-center gap-3 px-8 text-center"
                >
                    <span
                        class="flex size-12 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                    >
                        <MessagesSquare class="size-5" />
                    </span>
                    <div class="space-y-1">
                        <p class="text-sm font-semibold">No messages yet</p>
                        <p
                            class="text-[13px] leading-relaxed text-muted-foreground"
                        >
                            Links, notes and files shared here stay with the
                            meeting after it ends.
                        </p>
                    </div>
                </div>

                <template v-else>
                    <ChatMessageItem
                        v-for="(message, index) in messages"
                        :key="message.client_id ?? message.uuid"
                        :message="message"
                        :my-uuid="me?.uuid ?? null"
                        :compact="
                            continuesPrevious(message, messages[index - 1])
                        "
                        :can-interact="canChat"
                        :can-moderate="me?.can.manage ?? false"
                        @reply="startReply(message)"
                        @edit="startEdit(message)"
                        @delete="run(() => chat.destroy(message))"
                        @react="
                            (emoji) => run(() => chat.react(message, emoji))
                        "
                        @retry="chat.retry(message)"
                        @discard="chat.discard(message)"
                    />
                </template>
            </div>

            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="translate-y-2 opacity-0"
                leave-active-class="transition duration-150 ease-in"
                leave-to-class="translate-y-2 opacity-0"
            >
                <button
                    v-if="newBelow > 0"
                    type="button"
                    class="absolute bottom-[7.5rem] left-1/2 flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-brand px-3 py-1.5 text-xs font-semibold text-brand-foreground shadow-float outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @click="toBottom(true)"
                >
                    <ArrowDown class="size-3.5" />
                    {{ newBelow }} new
                    {{ newBelow === 1 ? 'message' : 'messages' }}
                </button>
            </Transition>

            <ChatComposer
                ref="composer"
                :chat="chat"
                :can-upload="canUpload"
                :disabled="!canChat"
            />

            <div
                v-if="dragging"
                class="pointer-events-none absolute inset-2 z-10 flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-brand bg-brand-soft/90 text-brand-soft-foreground"
            >
                <Upload class="size-6" />
                <p class="text-sm font-semibold">Drop files to share them</p>
            </div>
        </div>
    </SidePanel>
</template>
