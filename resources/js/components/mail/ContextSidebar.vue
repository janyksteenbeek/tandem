<script setup lang="ts">
import { Bell, BellOff, ExternalLink, Share2, X } from '@lucide/vue';
import { computed } from 'vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { displayName } from '@/lib/mail';
import { fullTime, relative } from '@/lib/time';
import type { Conversation } from '@/types';

const props = defineProps<{ conversation: Conversation }>();
const emit = defineEmits<{ close: []; follow: [following: boolean]; unshare: [inboxUuid: string]; compose: [email: string] }>();

const people = computed(() => props.conversation.participants);
const primary = computed(() => props.conversation.counterpart);
</script>

<template>
    <aside class="flex h-full w-72 shrink-0 flex-col border-l bg-surface/60">
        <div class="flex items-center justify-between px-4 py-3">
            <p class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Context</p>
            <Button variant="ghost" size="icon" class="size-7" aria-label="Close context" @click="emit('close')"><X class="size-3.5" /></Button>
        </div>

        <div class="flex-1 space-y-6 overflow-y-auto px-4 pb-6 scrollbar-thin">
            <section v-if="primary" class="rounded-xl border bg-card p-4 text-center shadow-pane">
                <UserAvatar :name="primary.name" :email="primary.email" size="xl" class="mx-auto" />
                <p class="mt-2 truncate text-sm font-semibold">{{ displayName(primary) }}</p>
                <p class="truncate text-xs text-muted-foreground">{{ primary.email }}</p>
                <div class="mt-3 flex justify-center gap-1">
                    <Button variant="outline" size="sm" class="h-7 text-xs" @click="emit('compose', primary.email)">New message</Button>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button variant="outline" size="icon" class="size-7" as="a" :href="`mailto:${primary.email}`"><ExternalLink class="size-3.5" /></Button>
                        </TooltipTrigger>
                        <TooltipContent>Open in default mail app</TooltipContent>
                    </Tooltip>
                </div>
            </section>

            <section>
                <p class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">People in this thread</p>
                <ul class="space-y-1.5">
                    <li v-for="person in people" :key="person.email" class="flex items-center gap-2 text-sm">
                        <UserAvatar :name="person.name" :email="person.email" size="sm" />
                        <span class="min-w-0">
                            <span class="block truncate text-[13px]">{{ displayName(person) }}</span>
                            <span class="block truncate text-[11px] text-muted-foreground">{{ person.email }}</span>
                        </span>
                    </li>
                </ul>
            </section>

            <section>
                <p class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Details</p>
                <dl class="space-y-1.5 text-xs">
                    <div class="flex justify-between gap-2"><dt class="text-muted-foreground">Inbox</dt><dd class="truncate font-medium">{{ conversation.inbox_name }}</dd></div>
                    <div v-if="conversation.origin_inbox" class="flex justify-between gap-2"><dt class="text-muted-foreground">Came from</dt><dd class="truncate">{{ conversation.origin_inbox.name }}</dd></div>
                    <div v-if="conversation.mail_account" class="flex justify-between gap-2"><dt class="text-muted-foreground">Account</dt><dd class="truncate">{{ conversation.mail_account.email }} <span class="text-muted-foreground">({{ conversation.mail_account.provider }})</span></dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-muted-foreground">Started</dt><dd>{{ fullTime(conversation.first_message_at) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-muted-foreground">Last activity</dt><dd>{{ relative(conversation.last_activity_at) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-muted-foreground">Messages</dt><dd>{{ conversation.message_count }} · {{ conversation.comment_count }} comments</dd></div>
                    <div v-if="conversation.closed_at" class="flex justify-between gap-2"><dt class="text-muted-foreground">Closed</dt><dd>{{ fullTime(conversation.closed_at) }}</dd></div>
                </dl>
            </section>

            <section v-if="conversation.shares.length">
                <p class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Shared with</p>
                <ul class="space-y-1.5">
                    <li v-for="share in conversation.shares" :key="share.inbox_uuid" class="flex items-start gap-2 rounded-lg border bg-card px-2.5 py-2 text-xs">
                        <Share2 class="mt-0.5 size-3.5 text-brand" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">{{ share.inbox_name }}</span>
                            <span class="block text-muted-foreground">by {{ share.shared_by?.name ?? 'someone' }} · {{ relative(share.created_at) }}</span>
                            <span v-if="share.note" class="mt-1 block rounded bg-muted px-2 py-1 italic">“{{ share.note }}”</span>
                        </span>
                        <button v-if="conversation.can.share" type="button" class="text-muted-foreground hover:text-destructive" aria-label="Stop sharing" @click="emit('unshare', share.inbox_uuid)"><X class="size-3.5" /></button>
                    </li>
                </ul>
            </section>

            <section>
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Followers</p>
                    <Button variant="ghost" size="sm" class="h-6 gap-1 px-1.5 text-[11px]" @click="emit('follow', !conversation.is_following)">
                        <BellOff v-if="conversation.is_following" class="size-3" /><Bell v-else class="size-3" />
                        {{ conversation.is_following ? 'Unfollow' : 'Follow' }}
                    </Button>
                </div>
                <div class="flex flex-wrap gap-1">
                    <Tooltip v-for="follower in conversation.followers" :key="follower.id">
                        <TooltipTrigger as-child><span><UserAvatar :name="follower.name" :email="follower.email" :src="follower.avatar" :initials="follower.initials" size="sm" /></span></TooltipTrigger>
                        <TooltipContent>{{ follower.name }}</TooltipContent>
                    </Tooltip>
                    <span v-if="conversation.followers.length === 0" class="text-xs text-muted-foreground">Nobody yet. Followers get notified about replies and comments.</span>
                </div>
            </section>

            <section v-if="conversation.tags.length">
                <p class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Tags</p>
                <div class="flex flex-wrap gap-1">
                    <span v-for="tag in conversation.tags" :key="tag.id" class="flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[11px]"><InboxDot :color="tag.color" size="sm" /> {{ tag.name }}</span>
                </div>
            </section>
        </div>
    </aside>
</template>
