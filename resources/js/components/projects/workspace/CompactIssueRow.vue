<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AvatarStack from '@/components/projects/shared/AvatarStack.vue';
import DueDateBadge from '@/components/projects/shared/DueDateBadge.vue';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import PriorityIcon from '@/components/projects/shared/PriorityIcon.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { IssueListItem } from '@/types';

/** A dense, read-only issue row for overview lists; the full list lives in IssueWorkspaceBody. */
const props = withDefaults(
    defineProps<{
        issue: IssueListItem;
        focusTeamId?: number | null;
        showTeams?: boolean;
    }>(),
    { focusTeamId: null, showTeams: true },
);

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const status = computed(() =>
    props.focusTeamId && props.issue.workstream
        ? props.issue.workstream.status
        : props.issue.status,
);
</script>

<template>
    <Link
        :href="`/${slug}/issues/${issue.identifier}`"
        class="group/compact -mx-2 issue-row"
    >
        <PriorityIcon :priority="issue.priority" :size="13" />
        <IssueIdentifier :identifier="issue.identifier" />
        <StatusIcon :status="status" :size="13" />
        <span
            class="min-w-0 flex-1 truncate group-hover/compact:text-foreground"
            >{{ issue.title }}</span
        >
        <span
            v-if="showTeams && issue.teams.length > 0"
            class="flex items-center -space-x-0.5"
        >
            <Tooltip v-for="team in issue.teams.slice(0, 3)" :key="team.id">
                <TooltipTrigger as-child
                    ><span
                        ><InboxDot
                            :color="team.color"
                            size="sm"
                            :class="
                                team.blocked ? 'ring-1 ring-destructive' : ''
                            " /></span
                ></TooltipTrigger>
                <TooltipContent
                    >{{ team.name }} · {{ team.status_name }}</TooltipContent
                >
            </Tooltip>
        </span>
        <DueDateBadge
            :date="issue.due_date"
            :category="issue.status_category"
            compact
        />
        <AvatarStack
            v-if="issue.assignees.length > 0"
            :users="issue.assignees"
            :max="2"
        />
    </Link>
</template>
