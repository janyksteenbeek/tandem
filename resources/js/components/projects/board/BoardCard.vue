<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { OctagonAlert } from '@lucide/vue';
import { computed } from 'vue';
import AvatarStack from '@/components/projects/shared/AvatarStack.vue';
import DueDateBadge from '@/components/projects/shared/DueDateBadge.vue';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import PriorityIcon from '@/components/projects/shared/PriorityIcon.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type { IssueListItem } from '@/types';

const props = withDefaults(
    defineProps<{
        issue: IssueListItem;
        focusTeamId?: number | null;
        dragging?: boolean;
    }>(),
    { focusTeamId: null, dragging: false },
);
const emit = defineEmits<{ open: [issue: IssueListItem] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const workstream = computed(() =>
    props.focusTeamId ? (props.issue.workstream ?? null) : null,
);
const assignees = computed(
    () => workstream.value?.assignees ?? props.issue.assignees,
);
const blocked = computed(
    () =>
        props.issue.counts.blocked_by > 0 ||
        props.issue.counts.blocked_workstreams > 0 ||
        Boolean(workstream.value && workstream.value.blocked_by_count > 0),
);
const otherTeams = computed(() =>
    props.issue.teams.filter((t) => t.id !== props.focusTeamId),
);
</script>

<template>
    <article
        :data-uuid="issue.uuid"
        :class="
            cn(
                'group rounded-lg border bg-card p-2.5 shadow-pane transition-[box-shadow,transform] hover:shadow-float',
                dragging && 'rotate-[1deg] shadow-float',
            )
        "
        @dblclick="emit('open', issue)"
    >
        <div class="mb-1 flex items-center gap-1.5">
            <IssueIdentifier :identifier="issue.identifier" />
            <Tooltip v-if="blocked">
                <TooltipTrigger as-child
                    ><OctagonAlert class="size-3 text-destructive"
                /></TooltipTrigger>
                <TooltipContent>Blocked</TooltipContent>
            </Tooltip>
            <span
                v-if="issue.project"
                class="ml-auto max-w-[45%] truncate text-[10.5px] text-muted-foreground"
                >{{ issue.project.name }}</span
            >
        </div>
        <Link
            :href="`/${slug}/issues/${issue.identifier}`"
            prefetch="hover"
            class="line-clamp-2 text-[13px] leading-snug text-foreground"
            @click.prevent="emit('open', issue)"
            >{{ issue.title }}</Link
        >
        <div class="mt-2 flex items-center gap-1.5">
            <PriorityIcon :priority="issue.priority" :size="13" />
            <span
                v-if="issue.labels.length"
                class="flex items-center -space-x-0.5"
            >
                <span
                    v-for="label in issue.labels.slice(0, 4)"
                    :key="label.id"
                    :class="
                        cn(
                            'size-2 rounded-full bg-(--chip) ring-1 ring-card',
                            chipClass(label.color),
                        )
                    "
                    :title="label.name"
                />
            </span>
            <span v-if="otherTeams.length" class="flex items-center -space-x-1">
                <Tooltip v-for="team in otherTeams.slice(0, 3)" :key="team.id">
                    <TooltipTrigger as-child>
                        <span
                            :class="
                                cn(
                                    'size-2.5 rounded-full bg-(--chip) ring-1 ring-card',
                                    chipClass(team.color),
                                )
                            "
                            :style="{
                                opacity:
                                    team.status_category === 'completed'
                                        ? 0.4
                                        : 1,
                            }"
                        />
                    </TooltipTrigger>
                    <TooltipContent
                        >{{ team.name }} ·
                        {{ team.status_name }}</TooltipContent
                    >
                </Tooltip>
            </span>
            <DueDateBadge
                :date="issue.due_date"
                :category="issue.status_category"
                compact
                class="ml-auto"
            />
            <AvatarStack
                v-if="assignees.length"
                :users="assignees"
                :max="2"
                :class="issue.due_date ? '' : 'ml-auto'"
            />
        </div>
    </article>
</template>
