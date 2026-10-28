<script setup lang="ts">
import { ArrowUpRight, Clock, MessageSquareText, Paperclip, PenLine, Star } from '@lucide/vue';
import { computed } from 'vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { chipClass, displayName, PRIORITY_META } from '@/lib/mail';
import { listTime, snoozeLabel } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { ConversationListItem } from '@/types';

const props = defineProps<{
    item: ConversationListItem;
    active: boolean;
    selected: boolean;
    cursor: boolean;
    showInbox?: boolean;
    selectionMode: boolean;
}>();

const emit = defineEmits<{ open: [item: ConversationListItem]; toggle: [item: ConversationListItem, shift: boolean]; star: [item: ConversationListItem] }>();

const sender = computed(() => displayName(props.item.counterpart));
const unread = computed(() => !props.item.is_read);
const priority = computed(() => PRIORITY_META[props.item.priority]);
const isSnoozed = computed(() => props.item.status === 'snoozed' && props.item.snoozed_until);

const participantsLabel = computed(() => {
    const others = props.item.participants.filter((p) => p.email !== props.item.counterpart?.email);

    if (others.length === 0) {
        return null;
    }

    return others.length === 1 ? displayName(others[0]) : `${displayName(others[0])} +${others.length - 1}`;
});
</script>

<template>
    <article
        :class="
            cn(
                'group relative flex cursor-pointer gap-3 border-b border-border/70 px-3 py-2.5 transition-colors select-none',
                active ? 'bg-brand-soft/50 dark:bg-brand-soft/30' : 'hover:bg-accent/60',
                cursor && !active && 'bg-accent/40',
                selected && 'bg-brand-soft/40',
            )
        "
        :aria-current="active ? 'true' : undefined"
        @click="emit('open', item)"
    >
        <span v-if="active" class="absolute top-2 bottom-2 left-0 w-[3px] rounded-r-full bg-brand" />

        <div class="relative mt-0.5 shrink-0" @click.stop="emit('toggle', item, $event.shiftKey)">
            <UserAvatar
                :name="item.counterpart?.name"
                :email="item.counterpart?.email"
                size="lg"
                :class="cn('transition', (selectionMode || selected) && 'opacity-0')"
            />
            <span
                :class="cn('absolute inset-0 flex items-center justify-center rounded-full transition', selectionMode || selected ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 group-hover:bg-background/90')"
            >
                <Checkbox :model-value="selected" class="size-5" aria-label="Select conversation" />
            </span>
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <span v-if="unread" class="unread-dot" />
                <span :class="cn('truncate text-[13.5px]', unread ? 'font-semibold text-foreground' : 'font-medium text-foreground/90')">{{ sender }}</span>
                <span v-if="participantsLabel" class="flex items-center gap-0.5 truncate text-xs text-muted-foreground">
                    <ArrowUpRight class="size-3 shrink-0 opacity-60" /> {{ participantsLabel }}
                </span>
                <span class="ml-auto flex shrink-0 items-center gap-1.5 text-[11px] text-muted-foreground tabular-nums">
                    <span v-if="item.message_count > 1" class="rounded-full bg-muted px-1.5 text-[10px] font-medium">{{ item.message_count }}</span>
                    <span v-if="isSnoozed" class="flex items-center gap-0.5 text-highlight-foreground"><Clock class="size-3" />{{ snoozeLabel(item.snoozed_until) }}</span>
                    <span v-else>{{ listTime(item.last_message_at) }}</span>
                </span>
            </div>

            <div class="mt-0.5 flex items-center gap-1.5">
                <span v-if="showInbox" :class="cn('shrink-0 rounded-md px-1.5 py-px text-[10px] font-medium text-(--chip)', chipClass('plum'))" style="background: color-mix(in oklch, var(--chip) 14%, transparent)">
                    {{ item.inbox_name }}
                </span>
                <span :class="cn('truncate text-[13px]', unread ? 'font-medium text-foreground' : 'text-foreground/80')">{{ item.subject || '(no subject)' }}</span>
            </div>

            <div class="mt-0.5 flex items-center gap-2">
                <p class="min-w-0 flex-1 truncate text-xs text-muted-foreground">
                    <span v-if="item.last_direction === 'outbound'" class="text-muted-foreground/70">You: </span>{{ item.snippet }}
                </p>
                <span class="flex shrink-0 items-center gap-1">
                    <span v-if="item.has_draft" class="flex items-center gap-0.5 text-[10px] font-medium text-brand"><PenLine class="size-3" /> Draft</span>
                    <Paperclip v-if="item.has_attachments" class="size-3.5 text-muted-foreground" />
                    <span v-if="item.comment_count > 0" class="flex items-center gap-0.5 text-[11px] text-muted-foreground"><MessageSquareText class="size-3.5" />{{ item.comment_count }}</span>
                    <span v-if="item.priority !== 'none'" :class="cn('text-[10px] font-semibold uppercase', priority.className)">{{ priority.short }}</span>
                    <UserAvatar v-if="item.assignee" :name="item.assignee.name" :email="item.assignee.email" :src="item.assignee.avatar" :initials="item.assignee.initials" size="xs" :title="`Assigned to ${item.assignee.name}`" />
                </span>
            </div>

            <div v-if="item.tags.length" class="mt-1.5 flex flex-wrap gap-1">
                <span
                    v-for="tag in item.tags.slice(0, 3)"
                    :key="tag.id"
                    :class="cn('rounded-md px-1.5 py-px text-[10px] font-medium text-(--chip)', chipClass(tag.color))"
                    style="background: color-mix(in oklch, var(--chip) 14%, transparent)"
                >
                    {{ tag.name }}
                </span>
                <span v-if="item.tags.length > 3" class="text-[10px] text-muted-foreground">+{{ item.tags.length - 3 }}</span>
            </div>
        </div>

        <button
            type="button"
            :class="cn('absolute top-2.5 right-2 rounded p-0.5 transition', item.is_starred ? 'text-highlight opacity-100' : 'text-muted-foreground opacity-0 group-hover:opacity-100 hover:text-highlight')"
            :aria-label="item.is_starred ? 'Unstar' : 'Star'"
            @click.stop="emit('star', item)"
        >
            <Star class="size-3.5" :class="item.is_starred && 'fill-current'" />
        </button>

        <span v-if="item.shared_inbox_ids.length && !showInbox" class="absolute right-2 bottom-2" title="Shared with a team inbox">
            <InboxDot color="plum" size="sm" />
        </span>
    </article>
</template>
