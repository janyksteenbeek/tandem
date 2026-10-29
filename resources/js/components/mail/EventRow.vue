<script setup lang="ts">
import {
    Archive,
    ArrowRightLeft,
    Bot,
    CheckCircle2,
    Clock,
    Flag,
    RotateCcw,
    Share2,
    Tag as TagIcon,
    Trash2,
    UserCheck,
    UserMinus,
    UserPlus,
    Zap,
} from '@lucide/vue';
import { computed } from 'vue';
import { fullTime } from '@/lib/time';
import type { ConversationEvent } from '@/types';

const props = defineProps<{ event: ConversationEvent }>();

const actor = computed(() => props.event.actor?.name ?? 'Tandem');
const p = computed(() => props.event.payload as Record<string, any>);

const describe = computed((): { icon: unknown; text: string } => {
    switch (props.event.type) {
        case 'assigned':
            return {
                icon: UserCheck,
                text: `${actor.value} assigned this to ${p.value.assignee?.name ?? 'someone'}`,
            };
        case 'unassigned':
            return {
                icon: UserMinus,
                text: `${actor.value} unassigned ${p.value.previous?.name ?? 'the conversation'}`,
            };
        case 'status_changed':
            return {
                icon: p.value.to === 'closed' ? CheckCircle2 : RotateCcw,
                text:
                    p.value.reason === 'new_reply'
                        ? 'Reopened by a new reply'
                        : `${actor.value} marked this ${({ open: 'open', pending: 'waiting', closed: 'done', snoozed: 'later' } as Record<string, string>)[p.value.to] ?? p.value.to}`,
            };
        case 'snoozed':
            return {
                icon: Clock,
                text: `${actor.value} snoozed this until ${p.value.until ? fullTime(p.value.until) : 'later'}`,
            };
        case 'unsnoozed':
            return { icon: Clock, text: 'Back from snooze' };
        case 'priority_changed':
            return {
                icon: Flag,
                text: `${actor.value} set priority to ${p.value.to}`,
            };
        case 'tag_added':
            return {
                icon: TagIcon,
                text: `${actor.value} added tag “${p.value.tag?.name}”`,
            };
        case 'tag_removed':
            return {
                icon: TagIcon,
                text: `${actor.value} removed tag “${p.value.tag?.name}”`,
            };
        case 'moved':
            return {
                icon: ArrowRightLeft,
                text: `${actor.value} moved this from ${p.value.from?.name} to ${p.value.to?.name}`,
            };
        case 'shared':
            return {
                icon: Share2,
                text: `${actor.value} shared this with ${p.value.inbox?.name}${p.value.note ? ` — “${p.value.note}”` : ''}`,
            };
        case 'unshared':
            return {
                icon: Share2,
                text: `${actor.value} stopped sharing with ${p.value.inbox?.name}`,
            };
        case 'archived':
            return { icon: Archive, text: `${actor.value} archived this` };
        case 'trashed':
            return { icon: Trash2, text: `${actor.value} moved this to trash` };
        case 'restored':
            return { icon: RotateCcw, text: `${actor.value} restored this` };
        case 'follower_added':
            return {
                icon: UserPlus,
                text: `${p.value.user?.name ?? actor.value} started following`,
            };
        case 'follower_removed':
            return {
                icon: UserMinus,
                text: `${p.value.user?.name ?? actor.value} stopped following`,
            };
        case 'automation_ran':
            return {
                icon: Bot,
                text: `Automation “${p.value.rule_name}” ran${Array.isArray(p.value.actions) && p.value.actions.length ? ` (${p.value.actions.length} action${p.value.actions.length === 1 ? '' : 's'})` : ''}`,
            };
        case 'message_failed':
            return {
                icon: Zap,
                text: `Delivery failed: ${p.value.error ?? 'unknown error'}`,
            };
        default:
            return { icon: Zap, text: props.event.type.replace(/_/g, ' ') };
    }
});
</script>

<template>
    <div class="flex items-center gap-2 px-3 text-xs text-muted-foreground">
        <span
            class="flex size-5 items-center justify-center rounded-full bg-muted"
            ><component :is="describe.icon" class="size-3"
        /></span>
        <span class="truncate">{{ describe.text }}</span>
        <span class="ml-auto shrink-0 tabular-nums">{{
            fullTime(event.created_at)
        }}</span>
    </div>
</template>
