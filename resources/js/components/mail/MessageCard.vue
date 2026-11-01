<script setup lang="ts">
import {
    AlertTriangle,
    ChevronDown,
    Clock,
    Forward,
    MoreHorizontal,
    Reply,
    ReplyAll,
    Send,
    Star,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AttachmentChip from '@/components/mail/AttachmentChip.vue';
import MessageFrame from '@/components/mail/MessageFrame.vue';
import Collapse from '@/components/Collapse.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
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
import { displayName, formatAddressList } from '@/lib/mail';
import { fullTime, listTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { Message } from '@/types';

const props = defineProps<{
    message: Message;
    expanded: boolean;
    canAct: boolean;
}>();
const emit = defineEmits<{
    toggle: [];
    reply: [kind: 'reply' | 'reply_all' | 'forward'];
    viewSource: [];
}>();

const showDetails = ref(false);
const remoteImages = ref(true);

const name = computed(() =>
    props.message.direction === 'outbound' && props.message.author
        ? props.message.author.name
        : displayName(props.message.from),
);
const delivery = computed(() => props.message.delivery_status);
const attachments = computed(() =>
    props.message.attachments.filter((a) => !a.is_inline || !a.content_id),
);

const deliveryMeta: Record<
    string,
    { label: string; className: string; icon: unknown }
> = {
    queued: {
        label: 'Queued',
        className: 'text-muted-foreground',
        icon: Clock,
    },
    sending: {
        label: 'Sending…',
        className: 'text-muted-foreground',
        icon: Send,
    },
    failed: {
        label: 'Failed to send',
        className: 'text-destructive',
        icon: AlertTriangle,
    },
    bounced: {
        label: 'Bounced',
        className: 'text-destructive',
        icon: AlertTriangle,
    },
};
</script>

<template>
    <article
        :class="
            cn(
                'rounded-xl border bg-card transition-shadow',
                expanded ? 'shadow-pane' : 'cursor-pointer hover:bg-accent/40',
                message.direction === 'outbound' && 'border-brand/15',
            )
        "
    >
        <header
            class="flex items-start gap-3 px-4 py-3"
            @click="!expanded && emit('toggle')"
        >
            <UserAvatar
                :name="
                    message.direction === 'outbound'
                        ? message.author?.name
                        : message.from?.name
                "
                :email="message.from?.email"
                :src="
                    message.direction === 'outbound'
                        ? message.author?.avatar
                        : null
                "
                size="lg"
            />
            <div class="min-w-0 flex-1">
                <div class="flex items-baseline gap-2">
                    <span class="truncate text-sm font-semibold">{{
                        name
                    }}</span>
                    <span
                        v-if="message.direction === 'outbound'"
                        class="rounded bg-brand-soft px-1.5 text-[10px] font-medium text-brand-soft-foreground"
                        >you</span
                    >
                    <span class="truncate text-xs text-muted-foreground">{{
                        message.from?.email
                    }}</span>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <span
                                class="ml-auto shrink-0 text-xs text-muted-foreground tabular-nums"
                                >{{
                                    expanded
                                        ? fullTime(message.sent_at)
                                        : listTime(message.sent_at)
                                }}</span
                            >
                        </TooltipTrigger>
                        <TooltipContent>{{
                            message.sent_at
                                ? new Date(message.sent_at).toLocaleString()
                                : ''
                        }}</TooltipContent>
                    </Tooltip>
                </div>

                <template v-if="expanded">
                    <button
                        type="button"
                        class="mt-0.5 flex max-w-full items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                        @click.stop="showDetails = !showDetails"
                    >
                        <span class="truncate"
                            >to {{ formatAddressList(message.to) || '—'
                            }}<span v-if="message.cc.length"
                                >, cc {{ message.cc.length }}</span
                            ></span
                        >
                        <ChevronDown
                            class="size-3 shrink-0 transition"
                            :class="showDetails && 'rotate-180'"
                        />
                    </button>
                    <dl
                        v-if="showDetails"
                        class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg bg-muted/60 px-3 py-2 text-xs"
                    >
                        <dt class="text-muted-foreground">From</dt>
                        <dd class="break-all">
                            {{
                                message.from
                                    ? formatAddressList([message.from])
                                    : ''
                            }}
                        </dd>
                        <dt class="text-muted-foreground">To</dt>
                        <dd class="break-all">
                            {{ formatAddressList(message.to) }}
                        </dd>
                        <template v-if="message.cc.length"
                            ><dt class="text-muted-foreground">Cc</dt>
                            <dd class="break-all">
                                {{ formatAddressList(message.cc) }}
                            </dd></template
                        >
                        <template v-if="message.bcc.length"
                            ><dt class="text-muted-foreground">Bcc</dt>
                            <dd class="break-all">
                                {{ formatAddressList(message.bcc) }}
                            </dd></template
                        >
                        <template v-if="message.reply_to.length"
                            ><dt class="text-muted-foreground">Reply-to</dt>
                            <dd class="break-all">
                                {{ formatAddressList(message.reply_to) }}
                            </dd></template
                        >
                        <dt class="text-muted-foreground">Date</dt>
                        <dd>
                            {{
                                message.sent_at
                                    ? new Date(message.sent_at).toLocaleString()
                                    : ''
                            }}
                        </dd>
                        <template v-if="message.message_id"
                            ><dt class="text-muted-foreground">Message-ID</dt>
                            <dd class="font-mono text-[11px] break-all">
                                {{ message.message_id }}
                            </dd></template
                        >
                    </dl>
                </template>
                <p v-else class="mt-0.5 truncate text-xs text-muted-foreground">
                    {{ message.snippet }}
                </p>
            </div>

            <div
                v-if="expanded && canAct"
                class="flex shrink-0 items-center gap-0.5"
                @click.stop
            >
                <Tooltip>
                    <TooltipTrigger as-child
                        ><Button
                            variant="ghost"
                            size="icon"
                            class="size-7"
                            @click="emit('reply', 'reply')"
                            ><Reply class="size-4" /></Button
                    ></TooltipTrigger>
                    <TooltipContent
                        >Reply
                        <kbd class="ml-1 rounded border px-1 text-[10px]"
                            >R</kbd
                        ></TooltipContent
                    >
                </Tooltip>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child
                        ><Button variant="ghost" size="icon" class="size-7"
                            ><MoreHorizontal class="size-4" /></Button
                    ></DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            class="gap-2"
                            @click="emit('reply', 'reply_all')"
                            ><ReplyAll class="size-4" /> Reply
                            all</DropdownMenuItem
                        >
                        <DropdownMenuItem
                            class="gap-2"
                            @click="emit('reply', 'forward')"
                            ><Forward class="size-4" />
                            Forward</DropdownMenuItem
                        >
                        <DropdownMenuItem
                            class="gap-2"
                            @click="remoteImages = !remoteImages"
                            ><Star class="size-4" />
                            {{
                                remoteImages
                                    ? 'Block remote images'
                                    : 'Load remote images'
                            }}</DropdownMenuItem
                        >
                        <DropdownMenuItem
                            class="gap-2"
                            @click="emit('viewSource')"
                            >View original</DropdownMenuItem
                        >
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>

        <Collapse :open="expanded">
            <div class="px-4 pb-4">
                <div
                    v-if="deliveryMeta[delivery]"
                    :class="
                        cn(
                            'mb-3 flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs',
                            deliveryMeta[delivery].className,
                            delivery === 'failed' &&
                                'border-destructive/30 bg-destructive/5',
                        )
                    "
                >
                    <component
                        :is="deliveryMeta[delivery].icon"
                        class="size-3.5"
                    />
                    {{ deliveryMeta[delivery].label }}
                    <span
                        v-if="message.delivery_error"
                        class="truncate opacity-80"
                        >· {{ message.delivery_error }}</span
                    >
                </div>

                <MessageFrame
                    :html="message.html_body"
                    :text="message.text_body"
                    :allow-remote-images="remoteImages"
                />

                <div
                    v-if="attachments.length"
                    class="mt-3 flex flex-wrap gap-2 border-t pt-3"
                >
                    <AttachmentChip
                        v-for="attachment in attachments"
                        :key="attachment.uuid"
                        :filename="attachment.filename"
                        :mime-type="attachment.mime_type"
                        :size="attachment.size_bytes"
                        :url="attachment.url"
                        :download-url="attachment.download_url"
                    />
                </div>
            </div>
        </Collapse>
    </article>
</template>
