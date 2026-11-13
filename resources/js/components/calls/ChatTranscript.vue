<script setup lang="ts">
import { CornerDownRight, Download, FileText } from '@lucide/vue';
import LinkifiedText from '@/components/calls/LinkifiedText.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { formatSize } from '@/lib/calls/format';
import { fullTime } from '@/lib/time';
import type { CallMessage } from '@/types';

/**
 * The meeting chat as it was left, read-only: who said what and when, replies with the
 * message they answer, reactions, shared files, and a quiet marker where a message was deleted.
 */
defineProps<{ messages: CallMessage[] }>();

const names = (message: CallMessage['reactions'][number]) =>
    message.participants.map((participant) => participant.name).join(', ');
</script>

<template>
    <ol class="space-y-4" aria-label="Meeting chat">
        <li
            v-for="message in messages"
            :key="message.uuid"
            class="flex gap-3"
            :data-test="message.deleted ? 'chat-deleted' : 'chat-message'"
        >
            <UserAvatar
                :name="message.author?.name ?? 'Someone'"
                :src="message.author?.avatar ?? null"
                :initials="message.author?.initials ?? null"
                size="sm"
                class="mt-0.5"
            />
            <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-baseline gap-x-2 text-[12px]">
                    <span class="text-[13px] font-medium text-foreground">{{
                        message.author?.name ?? 'Someone'
                    }}</span>
                    <span
                        v-if="message.author?.is_guest"
                        class="rounded-full border px-1.5 text-[10px] text-muted-foreground"
                        >Guest</span
                    >
                    <time
                        v-if="message.created_at"
                        :datetime="message.created_at"
                        class="text-muted-foreground tabular-nums"
                        >{{ fullTime(message.created_at) }}</time
                    >
                    <span
                        v-if="message.edited_at && !message.deleted"
                        class="text-muted-foreground"
                        >edited</span
                    >
                </p>

                <p
                    v-if="message.deleted"
                    class="mt-0.5 text-[13px] text-muted-foreground italic"
                >
                    This message was deleted.
                </p>

                <template v-else>
                    <p
                        v-if="message.parent"
                        class="mt-1 flex items-start gap-1.5 border-l-2 border-brand/30 pl-2 text-[12px] text-muted-foreground"
                    >
                        <CornerDownRight class="mt-0.5 size-3 shrink-0" />
                        <span class="min-w-0">
                            <span class="font-medium">{{
                                message.parent.author ?? 'Someone'
                            }}</span
                            >:
                            <span
                                v-if="message.parent.excerpt !== null"
                                class="break-words"
                                >{{ message.parent.excerpt }}</span
                            >
                            <span v-else class="italic">deleted message</span>
                        </span>
                    </p>

                    <p
                        v-if="message.body"
                        class="mt-0.5 text-[13.5px] leading-relaxed text-foreground"
                    >
                        <LinkifiedText :text="message.body" />
                    </p>

                    <ul
                        v-if="message.files.length > 0"
                        class="mt-2 flex flex-wrap gap-2"
                    >
                        <li v-for="file in message.files" :key="file.uuid">
                            <a
                                :href="file.download_url"
                                class="group flex max-w-xs items-center gap-2 rounded-lg border bg-card px-2.5 py-1.5 text-[12px] transition-colors hover:border-brand/40"
                            >
                                <img
                                    v-if="file.is_image"
                                    :src="file.url"
                                    alt=""
                                    loading="lazy"
                                    class="size-8 rounded object-cover"
                                />
                                <span
                                    v-else
                                    class="flex size-8 items-center justify-center rounded bg-muted text-muted-foreground"
                                    ><FileText class="size-4"
                                /></span>
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{
                                        file.filename
                                    }}</span>
                                    <span
                                        class="block text-[11px] text-muted-foreground"
                                        >{{ formatSize(file.size_bytes) }}</span
                                    >
                                </span>
                                <Download
                                    class="size-3.5 shrink-0 text-muted-foreground group-hover:text-brand"
                                    aria-label="Download"
                                />
                            </a>
                        </li>
                    </ul>

                    <ul
                        v-if="message.reactions.length > 0"
                        class="mt-1.5 flex flex-wrap gap-1"
                        aria-label="Reactions"
                    >
                        <li
                            v-for="reaction in message.reactions"
                            :key="reaction.emoji"
                            class="flex h-6 items-center gap-1 rounded-full border bg-muted/40 px-2 text-[12px]"
                            :title="names(reaction)"
                        >
                            <span>{{ reaction.emoji }}</span>
                            <span class="text-muted-foreground tabular-nums">{{
                                reaction.count
                            }}</span>
                            <span class="sr-only"
                                >from {{ names(reaction) }}</span
                            >
                        </li>
                    </ul>
                </template>
            </div>
        </li>
    </ol>
</template>
