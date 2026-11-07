<script setup lang="ts">
import { Bot } from '@lucide/vue';
import { computed } from 'vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { fullTime, relative } from '@/lib/time';
import type { Activity } from '@/types';

const props = defineProps<{ activity: Activity }>();

const str = (key: string): string => {
    const value = props.activity.payload[key];

    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
};

const who = computed(
    () =>
        props.activity.actor?.name ??
        (props.activity.type === 'automation_ran' || !props.activity.actor
            ? 'Tandem'
            : 'Someone'),
);
const team = computed(() => props.activity.team?.name ?? str('team'));

const sentence = computed<string | null>(() => {
    const p = props.activity.payload;
    const t = team.value;

    switch (props.activity.type) {
        case 'created':
            return str('source') === 'automation'
                ? 'created this issue from an automation'
                : str('source') === 'email'
                  ? 'created this issue from an email'
                  : 'created this issue';
        case 'updated': {
            const changes =
                (p.changes as Record<string, unknown> | undefined) ?? {};
            const parts = Object.keys(changes).map((k) =>
                k === 'description'
                    ? 'the description'
                    : k === 'title'
                      ? 'the title'
                      : k,
            );

            return parts.length
                ? `changed ${parts.join(' and ')}`
                : 'made a change';
        }
        case 'status_changed':
            return `${str('source') === 'auto' ? 'automatically ' : ''}moved this from ${str('from')} to ${str('to')}${p.reopened ? ' (reopened)' : ''}`;
        case 'status_overridden':
            return `completed this anyway (${str('to')})${str('reason') ? ` — “${str('reason')}”` : ''}`;
        case 'completion_ready':
            return 'every required team finished — ready to complete';
        case 'completion_conflict':
            return 'a team reopened its work while the issue is marked done';
        case 'priority_changed':
            return `set priority to ${str('to') || 'none'}`;
        case 'owner_changed':
            return str('to_name')
                ? `made ${str('to_name')} the owner`
                : 'removed the owner';
        case 'lead_team_changed':
            return str('to')
                ? `made ${str('to')} the lead team`
                : 'removed the lead team';
        case 'team_added':
            return `added ${t}${p.is_required === false ? ' (optional)' : ''}`;
        case 'team_removed':
            return `removed ${t}`;
        case 'workstream_updated': {
            const changes =
                (p.changes as Record<string, unknown> | undefined) ?? {};

            return `updated ${t}'s ${Object.keys(changes).join(', ').replace(/_/g, ' ')}`;
        }
        case 'workstream_status_changed':
            return `${t}: ${str('from')} → ${str('to')}`;
        case 'workstream_status_overridden':
            return `${t}: completed while still blocked (${str('to')})`;
        case 'workstream_assigned':
            return `assigned ${str('user')} for ${t}`;
        case 'workstream_unassigned':
            return `unassigned ${str('user')} from ${t}`;
        case 'workstream_cycle_changed':
            return str('to')
                ? `scheduled ${t} in ${str('to')}`
                : `took ${t} out of ${str('from')}`;
        case 'cycle_carried_over':
            return `${t}: carried over from ${str('from')} to ${str('to')}`;
        case 'workstream_required_changed':
            return `marked ${t} ${p.is_required ? 'required' : 'optional'}`;
        case 'label_added':
            return `added label ${str('label')}`;
        case 'label_removed':
            return `removed label ${str('label')}`;
        case 'modules_changed':
            return 'changed the modules';
        case 'moved': {
            const to = [str('to_product'), str('to_project')]
                .filter(Boolean)
                .join(' › ');

            return to ? `moved this to ${to}` : 'moved this out of its project';
        }
        case 'parent_changed':
            return str('to_identifier')
                ? `made this a sub-issue of ${str('to_identifier')}`
                : 'detached this from its parent';
        case 'milestone_changed':
            return str('to')
                ? `set milestone ${str('to')}`
                : 'removed the milestone';
        case 'release_changed':
            return 'changed the release';
        case 'dates_changed': {
            const d = p as Record<string, { to?: string | null } | undefined>;
            const parts: string[] = [];

            if (d.start_date) {
                parts.push(
                    d.start_date.to
                        ? `start ${d.start_date.to}`
                        : 'cleared the start date',
                );
            }

            if (d.due_date) {
                parts.push(
                    d.due_date.to
                        ? `due ${d.due_date.to}`
                        : 'cleared the due date',
                );
            }

            return parts.length
                ? `set ${parts.join(', ')}`
                : 'changed the dates';
        }
        case 'estimate_changed':
            return str('to')
                ? `estimated ${str('to')} points`
                : 'cleared the estimate';
        case 'relation_added':
            return `linked ${str('issue')} ${str('type') === 'blocks' ? 'blocks' : `as ${str('type')} to`} ${str('related')}`;
        case 'relation_removed':
            return `unlinked ${str('issue')} and ${str('related')}`;
        case 'dependency_added':
            return `${t} now waits for ${str('depends_on')}`;
        case 'dependency_removed':
            return `${t} no longer waits for ${str('depends_on')}`;
        case 'dependency_resolved':
            return `${t ? `${t} is` : 'this is'} unblocked — ${str('resolved_by')} is done`;
        case 'dependency_reopened':
            return `${t ? `${t} is` : 'this is'} blocked again — ${str('reopened_by')} reopened`;
        case 'handoff':
            return `${str('from_team')} handed over to ${str('to_team')}`;
        case 'attachment_added':
            return `attached ${str('filename')}${p.from_mail ? ' from an email' : ''}`;
        case 'attachment_removed':
            return `removed ${str('filename')}`;
        case 'conversation_linked':
            return str('kind') === 'created_from'
                ? 'created this issue from a conversation'
                : 'linked a conversation';
        case 'conversation_unlinked':
            return 'unlinked a conversation';
        case 'content_shared':
            return str('kind') === 'attachment'
                ? `shared ${str('filename')} from an email`
                : 'shared an email excerpt';
        case 'follower_added':
            return str('user')
                ? `${str('user')} is now following`
                : 'started following';
        case 'follower_removed':
            return str('user')
                ? `${str('user')} stopped following`
                : 'stopped following';
        case 'automation_ran':
            return `ran the automation “${str('rule_name')}”`;
        case 'deleted':
            return 'deleted this issue';
        case 'restored':
            return 'restored this issue';
        case 'comment_added':
            return null;
        default:
            return props.activity.type.replace(/_/g, ' ');
    }
});

const isSystem = computed(() => !props.activity.actor);
</script>

<template>
    <div v-if="sentence" class="flex items-start gap-2.5 py-1 text-[12.5px]">
        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center">
            <Bot v-if="isSystem" class="size-3.5 text-muted-foreground" />
            <UserAvatar
                v-else
                :name="activity.actor?.name"
                :email="activity.actor?.email"
                :src="activity.actor?.avatar"
                :initials="activity.actor?.initials"
                size="xs"
            />
        </span>
        <p class="min-w-0 flex-1 text-muted-foreground">
            <span class="font-medium text-foreground">{{ who }}</span>
            {{ ' ' }}{{ sentence }}
        </p>
        <Tooltip>
            <TooltipTrigger as-child>
                <span
                    class="shrink-0 text-[11px] text-muted-foreground/80 tabular-nums"
                    >{{ relative(activity.created_at) }}</span
                >
            </TooltipTrigger>
            <TooltipContent>{{ fullTime(activity.created_at) }}</TooltipContent>
        </Tooltip>
    </div>
</template>
