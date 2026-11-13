<script setup lang="ts">
import {
    AlertCircle,
    CornerUpLeft,
    Download,
    FileText,
    MoreHorizontal,
    Pencil,
    SmilePlus,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import MessageBody from '@/components/meet/MessageBody.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatBytes, formatClock, localPath } from '@/lib/meet/format';
import type { LocalMessage } from '@/lib/meet/types';
import { cn } from '@/lib/utils';

const props = defineProps<{
    message: LocalMessage;
    myUuid: string | null;
    /** Same author as the message above, moments later: no name or avatar again. */
    compact: boolean;
    canInteract: boolean;
    canModerate: boolean;
}>();

const emit = defineEmits<{
    reply: [];
    edit: [];
    delete: [];
    react: [emoji: string];
    retry: [];
    discard: [];
}>();

const QUICK_REACTIONS = ['👍', '❤️', '😂', '🎉', '👀', '✅', '🙏', '🔥'];

const reactionsOpen = ref(false);

const mine = computed(() => props.message.author?.uuid === props.myUuid);
const sent = computed(() => props.message.state === 'sent');
const canEdit = computed(
    () =>
        mine.value &&
        sent.value &&
        !props.message.deleted &&
        props.message.body !== null,
);
const canDelete = computed(
    () =>
        sent.value &&
        !props.message.deleted &&
        (mine.value || props.canModerate),
);

const react = (emoji: string) => {
    reactionsOpen.value = false;
    emit('react', emoji);
};

const reactedByMe = (participants: { uuid: string }[]) =>
    participants.some((p) => p.uuid === props.myUuid);
</script>

<template>
    <article
        :class="
            cn(
                'group/message relative flex gap-2.5 px-4 transition-colors hover:bg-foreground/[0.025]',
                compact ? 'py-0.5' : 'pt-2.5 pb-0.5',
                message.state === 'pending' && 'opacity-60',
            )
        "
        :data-state="message.state"
        data-testid="chat-message"
    >
        <div class="w-7 shrink-0">
            <UserAvatar
                v-if="!compact"
                :name="message.author?.name ?? 'Someone'"
                :src="message.author?.avatar ?? null"
                :initials="message.author?.initials ?? null"
                size="sm"
                class="mt-0.5 size-7"
            />
        </div>

        <div class="min-w-0 flex-1">
            <header v-if="!compact" class="flex items-baseline gap-2">
                <span class="truncate text-[13px] font-semibold">
                    {{ message.author?.name ?? 'Someone' }}
                </span>
                <span
                    v-if="message.author?.is_guest"
                    class="rounded bg-foreground/[0.06] px-1 text-[10px] font-medium text-muted-foreground"
                    >Guest</span
                >
                <time
                    :datetime="message.created_at ?? undefined"
                    class="shrink-0 text-[11px] text-muted-foreground tabular-nums"
                    >{{ formatClock(message.created_at) }}</time
                >
            </header>

            <p
                v-if="message.deleted"
                class="text-[13px] text-muted-foreground italic"
            >
                Message deleted
            </p>

            <template v-else>
                <div
                    v-if="message.parent"
                    class="mt-0.5 mb-1 flex items-start gap-1.5 border-l-2 border-brand/40 pl-2 text-xs text-muted-foreground"
                >
                    <CornerUpLeft class="mt-0.5 size-3 shrink-0" />
                    <span class="line-clamp-2">
                        <span class="font-medium text-foreground/80">{{
                            message.parent.author ?? 'Someone'
                        }}</span>
                        {{ message.parent.excerpt ?? 'Message deleted' }}
                    </span>
                </div>

                <MessageBody
                    v-if="message.body"
                    :text="message.body"
                    :edited="message.edited_at !== null"
                />

                <!-- Files -->
                <div
                    v-if="message.files.length"
                    class="mt-1.5 flex flex-col items-start gap-1.5"
                >
                    <template v-for="file in message.files" :key="file.uuid">
                        <a
                            v-if="file.is_image"
                            :href="localPath(file.url)"
                            target="_blank"
                            rel="noopener"
                            class="block overflow-hidden rounded-xl border bg-muted outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            :title="file.filename"
                        >
                            <img
                                :src="localPath(file.url)"
                                :alt="file.filename"
                                loading="lazy"
                                class="max-h-52 max-w-full object-contain"
                            />
                        </a>
                        <a
                            v-else
                            :href="localPath(file.download_url)"
                            class="flex max-w-full items-center gap-2.5 rounded-xl border bg-card px-3 py-2 transition-colors outline-none hover:border-brand/40 hover:bg-brand-soft/40 focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            data-testid="chat-file"
                        >
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand-soft-foreground"
                            >
                                <FileText class="size-4" />
                            </span>
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-[13px] font-medium"
                                    >{{ file.filename }}</span
                                >
                                <span
                                    class="block text-[11px] text-muted-foreground"
                                    >{{ formatBytes(file.size_bytes) }}</span
                                >
                            </span>
                            <Download
                                class="size-3.5 shrink-0 text-muted-foreground"
                            />
                        </a>
                    </template>
                </div>

                <!-- Reactions -->
                <div
                    v-if="message.reactions.length"
                    class="mt-1.5 flex flex-wrap gap-1"
                >
                    <button
                        v-for="reaction in message.reactions"
                        :key="reaction.emoji"
                        type="button"
                        :disabled="!canInteract"
                        :aria-pressed="reactedByMe(reaction.participants)"
                        :title="
                            reaction.participants.map((p) => p.name).join(', ')
                        "
                        :class="
                            cn(
                                'flex h-6 items-center gap-1 rounded-full border px-1.5 text-xs tabular-nums transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/60',
                                reactedByMe(reaction.participants)
                                    ? 'border-brand/40 bg-brand-soft text-brand-soft-foreground'
                                    : 'bg-card hover:bg-accent',
                            )
                        "
                        @click="emit('react', reaction.emoji)"
                    >
                        <span>{{ reaction.emoji }}</span>
                        <span class="font-medium">{{ reaction.count }}</span>
                    </button>
                </div>
            </template>

            <!-- Not sent -->
            <div
                v-if="message.state === 'failed'"
                class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-destructive"
                role="alert"
            >
                <AlertCircle class="size-3.5" />
                <span>{{ message.error ?? 'Not sent.' }}</span>
                <button
                    type="button"
                    class="font-semibold underline underline-offset-2"
                    @click="emit('retry')"
                >
                    Retry
                </button>
                <button
                    type="button"
                    class="text-muted-foreground underline underline-offset-2"
                    @click="emit('discard')"
                >
                    Discard
                </button>
            </div>
        </div>

        <!-- Actions -->
        <div
            v-if="sent && !message.deleted && canInteract"
            :class="
                cn(
                    'absolute -top-3 right-3 flex items-center rounded-lg border bg-popover p-0.5 shadow-sm transition-opacity',
                    reactionsOpen
                        ? 'opacity-100'
                        : 'opacity-0 group-focus-within/message:opacity-100 group-hover/message:opacity-100',
                )
            "
        >
            <Popover v-model:open="reactionsOpen">
                <PopoverTrigger as-child>
                    <button
                        type="button"
                        aria-label="Add reaction"
                        class="flex size-6 items-center justify-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                    >
                        <SmilePlus class="size-3.5" />
                    </button>
                </PopoverTrigger>
                <PopoverContent side="top" align="end" class="w-auto p-1">
                    <div class="flex gap-0.5">
                        <button
                            v-for="emoji in QUICK_REACTIONS"
                            :key="emoji"
                            type="button"
                            :aria-label="`React with ${emoji}`"
                            class="flex size-8 items-center justify-center rounded-md text-lg transition-transform outline-none hover:scale-125 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring/60"
                            @click="react(emoji)"
                        >
                            {{ emoji }}
                        </button>
                    </div>
                </PopoverContent>
            </Popover>
            <button
                type="button"
                aria-label="Reply"
                class="flex size-6 items-center justify-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                @click="emit('reply')"
            >
                <CornerUpLeft class="size-3.5" />
            </button>
            <DropdownMenu v-if="canEdit || canDelete">
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        aria-label="More"
                        class="flex size-6 items-center justify-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                    >
                        <MoreHorizontal class="size-3.5" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-36">
                    <DropdownMenuItem
                        v-if="canEdit"
                        class="gap-2"
                        @click="emit('edit')"
                    >
                        <Pencil class="size-3.5" /> Edit
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="canDelete"
                        class="gap-2 text-destructive focus:text-destructive"
                        @click="emit('delete')"
                    >
                        <Trash2 class="size-3.5" /> Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </article>
</template>
