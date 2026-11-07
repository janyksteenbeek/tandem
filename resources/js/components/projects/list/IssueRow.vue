<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Box, FolderKanban, Layers, OctagonAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import {
    DatePicker,
    MemberPicker,
    PriorityPicker,
    StatusPicker,
} from '@/components/projects/pickers';
import AvatarStack from '@/components/projects/shared/AvatarStack.vue';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { injectIssueActions } from '@/composables/projects/useIssueActions';
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type {
    IssueColumn,
    IssueListItem,
    IssueLookups,
    UserSummary,
    WorkflowStatus,
} from '@/types';

/**
 * One compact issue row. Status, priority, assignees and due date are inline pickers; the
 * rest are quiet chips. On team pages (`focusTeamId`) the status shown is that team's own
 * workstream status, so the same issue reads differently per team.
 */
const props = withDefaults(
    defineProps<{
        issue: IssueListItem;
        columns?: IssueColumn[];
        selected: boolean;
        cursor: boolean;
        workflow: WorkflowStatus[] | null;
        lookups?: IssueLookups | null;
        focusTeamId?: number | null;
        selectable?: boolean;
        density?: 'compact' | 'cozy';
    }>(),
    {
        columns: undefined,
        lookups: null,
        focusTeamId: null,
        selectable: true,
        density: 'compact',
    },
);

const emit = defineEmits<{
    open: [issue: IssueListItem];
    toggleSelect: [issue: IssueListItem, shift: boolean];
    contextmenu: [issue: IssueListItem, event: MouseEvent];
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const actions = injectIssueActions();

const show = (column: IssueColumn) =>
    !props.columns || props.columns.includes(column);

const focusWorkstream = computed(() =>
    props.focusTeamId ? (props.issue.workstream ?? null) : null,
);
const status = computed<WorkflowStatus>(
    () => focusWorkstream.value?.status ?? props.issue.status,
);
const statusOptions = computed<WorkflowStatus[]>(() => {
    if (focusWorkstream.value && props.lookups) {
        const team = props.lookups.teams.find(
            (t) => t.id === props.focusTeamId,
        );
        const workflow = team?.workflow_id
            ? props.lookups.workflows[team.workflow_id]
            : null;

        if (workflow) {
            return workflow.statuses;
        }
    }

    if (props.workflow && props.workflow.length > 0) {
        return props.workflow;
    }

    if (props.lookups) {
        const id = props.issue.product?.id
            ? props.lookups.products.find(
                  (p) => p.id === props.issue.product?.id,
              )?.workflow_id
            : props.lookups.default_workflow_id;

        return (
            (id && props.lookups.workflows[id]?.statuses) ||
            props.lookups.statuses
        );
    }

    return [status.value];
});

const assigneeWorkstream = computed(() => focusWorkstream.value ?? null);
const assignees = computed<UserSummary[]>(() =>
    assigneeWorkstream.value
        ? assigneeWorkstream.value.assignees
        : props.issue.assignees,
);
const members = computed(() => props.lookups?.members ?? []);
const blocked = computed(
    () =>
        props.issue.counts.blocked_by > 0 ||
        props.issue.counts.blocked_workstreams > 0 ||
        Boolean(
            focusWorkstream.value && focusWorkstream.value.blocked_by_count > 0,
        ),
);
const subProgress = computed(() =>
    props.issue.counts.sub_issues > 0
        ? props.issue.counts.sub_completed / props.issue.counts.sub_issues
        : 0,
);
const terminal = computed(
    () =>
        status.value.category === 'completed' ||
        status.value.category === 'canceled',
);

const statusOpen = ref(false);
const priorityOpen = ref(false);
const assigneeOpen = ref(false);
const dateOpen = ref(false);

const changeStatus = (next: WorkflowStatus) => {
    if (focusWorkstream.value) {
        void actions.setWorkstreamStatus(
            props.issue,
            focusWorkstream.value,
            next,
        );
    } else {
        void actions.setStatus(props.issue, next).then((result) => {
            if (result && 'readiness' in result) {
                window.dispatchEvent(
                    new CustomEvent('tandem:completion-blocked', {
                        detail: {
                            issue: props.issue,
                            status: next,
                            readiness: result.readiness,
                        },
                    }),
                );
            }
        });
    }
};

const changeAssignees = (users: UserSummary[]) => {
    const workstream =
        assigneeWorkstream.value ?? props.issue.workstream ?? null;

    if (workstream) {
        void actions.setAssignees(
            props.issue,
            workstream,
            users.map((u) => u.id),
        );

        return;
    }

    void actions.setOwner(props.issue, users[0]?.id ?? null, users[0] ?? null);
};

defineExpose({
    openPicker: (kind: 'status' | 'priority' | 'assignee' | 'date') => {
        ({
            status: statusOpen,
            priority: priorityOpen,
            assignee: assigneeOpen,
            date: dateOpen,
        })[kind].value = true;
    },
});

const href = computed(() => `/${slug.value}/issues/${props.issue.identifier}`);
</script>

<template>
    <article
        :data-uuid="issue.uuid"
        :aria-selected="selected || undefined"
        tabindex="-1"
        :class="
            cn(
                'group relative flex items-center gap-2 rounded-lg px-2 text-[13px] transition-colors select-none hover:bg-accent/60',
                density === 'compact' ? 'h-9' : 'h-11',
                cursor && 'bg-accent/50 ring-1 ring-border ring-inset',
                selected && 'bg-brand-soft/40 hover:bg-brand-soft/50',
            )
        "
        @contextmenu="emit('contextmenu', issue, $event)"
        @dblclick="emit('open', issue)"
    >
        <span
            v-if="selectable"
            :class="
                cn(
                    'flex size-4 shrink-0 items-center justify-center transition-opacity',
                    selected
                        ? 'opacity-100'
                        : 'opacity-0 group-hover:opacity-100',
                )
            "
            @click.stop="emit('toggleSelect', issue, $event.shiftKey)"
        >
            <Checkbox
                :model-value="selected"
                class="size-3.5"
                aria-label="Select issue"
            />
        </span>

        <PriorityPicker
            v-if="show('priority')"
            v-model:open="priorityOpen"
            :priority="issue.priority"
            compact
            @change="(p) => actions.setPriority(issue, p)"
        />

        <IssueIdentifier
            v-if="show('identifier')"
            :identifier="issue.identifier"
            class="w-[4.5rem]"
        />

        <StatusPicker
            v-if="show('status')"
            v-model:open="statusOpen"
            :status="status"
            :options="statusOptions"
            compact
            @change="changeStatus"
        />

        <Link
            :href="href"
            prefetch="hover"
            :class="
                cn(
                    'min-w-0 flex-1 truncate text-foreground',
                    terminal && 'text-muted-foreground',
                )
            "
            @click.prevent="emit('open', issue)"
        >
            <span v-if="issue.parent" class="mr-1 text-muted-foreground/70"
                >{{ issue.parent.identifier }} ›</span
            >
            {{ issue.title }}
        </Link>

        <span class="ml-auto flex shrink-0 items-center gap-2">
            <Tooltip v-if="blocked">
                <TooltipTrigger as-child>
                    <OctagonAlert class="size-3.5 text-destructive" />
                </TooltipTrigger>
                <TooltipContent>{{
                    issue.counts.blocked_by > 0
                        ? `Blocked by ${issue.counts.blocked_by} issue${issue.counts.blocked_by === 1 ? '' : 's'}`
                        : 'A workstream is waiting on a dependency'
                }}</TooltipContent>
            </Tooltip>

            <span
                v-if="show('labels') && issue.labels.length"
                class="hidden items-center gap-1 lg:flex"
            >
                <Tooltip
                    v-for="label in issue.labels.slice(0, 3)"
                    :key="label.id"
                >
                    <TooltipTrigger as-child>
                        <span
                            :class="
                                cn(
                                    'inline-flex h-5 items-center gap-1 rounded-full border px-1.5 text-[10.5px] text-muted-foreground',
                                    chipClass(label.color),
                                )
                            "
                        >
                            <span class="size-1.5 rounded-full bg-(--chip)" />{{
                                label.name
                            }}
                        </span>
                    </TooltipTrigger>
                    <TooltipContent>{{ label.name }}</TooltipContent>
                </Tooltip>
                <span
                    v-if="issue.labels.length > 3"
                    class="text-[10.5px] text-muted-foreground"
                    >+{{ issue.labels.length - 3 }}</span
                >
            </span>

            <span
                v-if="show('project') && issue.project"
                class="hidden max-w-36 items-center gap-1 truncate text-[11.5px] text-muted-foreground xl:flex"
                :title="issue.project.name"
            >
                <FolderKanban class="size-3 shrink-0" />
                <span class="truncate">{{ issue.project.name }}</span>
            </span>
            <span
                v-else-if="show('module') && issue.modules.length"
                class="hidden max-w-36 items-center gap-1 truncate text-[11.5px] text-muted-foreground xl:flex"
                :title="issue.modules.map((m) => m.name).join(', ')"
            >
                <Layers class="size-3 shrink-0" />
                <span class="truncate">{{ issue.modules[0].name }}</span>
                <span v-if="issue.modules.length > 1"
                    >+{{ issue.modules.length - 1 }}</span
                >
            </span>
            <span
                v-else-if="show('product') && issue.product"
                class="hidden items-center gap-1 text-[11.5px] text-muted-foreground xl:flex"
            >
                <Box class="size-3 shrink-0" />
                {{ issue.product.key }}
            </span>

            <span
                v-if="show('teams') && issue.teams.length > 1"
                class="flex items-center -space-x-1"
            >
                <Tooltip v-for="team in issue.teams.slice(0, 4)" :key="team.id">
                    <TooltipTrigger as-child>
                        <span
                            :class="
                                cn(
                                    'size-3 rounded-full ring-2 ring-background',
                                    chipClass(team.color),
                                    'bg-(--chip)',
                                    team.blocked &&
                                        'outline outline-1 outline-offset-1 outline-destructive',
                                )
                            "
                            :style="{
                                opacity:
                                    team.status_category === 'completed'
                                        ? 0.45
                                        : team.status_category ===
                                                'unstarted' ||
                                            team.status_category === 'backlog'
                                          ? 0.7
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

            <span
                v-if="issue.counts.sub_issues > 0"
                class="flex items-center gap-1 text-[11px] text-muted-foreground tabular-nums"
            >
                <ProgressRing :value="subProgress" :size="12" :stroke="2" />
                {{ issue.counts.sub_completed }}/{{ issue.counts.sub_issues }}
            </span>

            <DatePicker
                v-if="show('due_date')"
                v-model:open="dateOpen"
                :value="issue.due_date"
                :category="issue.status_category"
                compact
                align="end"
                @change="(v) => actions.setDates(issue, { due_date: v })"
            >
                <template v-if="!issue.due_date" #trigger>
                    <span class="hidden group-hover:inline-flex">
                        <span
                            class="inline-flex h-7 items-center rounded-md px-1 text-[11px] text-muted-foreground/70 hover:bg-accent"
                            >Due</span
                        >
                    </span>
                </template>
            </DatePicker>

            <MemberPicker
                v-if="show('assignees')"
                v-model:open="assigneeOpen"
                :selected="assignees"
                :members="members"
                :multiple="Boolean(assigneeWorkstream ?? issue.workstream)"
                compact
                align="end"
                :placeholder="
                    (assigneeWorkstream ?? issue.workstream)
                        ? 'Assign…'
                        : 'Owner…'
                "
                @change="changeAssignees"
            >
                <template v-if="assignees.length === 0" #trigger>
                    <button
                        type="button"
                        class="flex size-5 items-center justify-center rounded-full border border-dashed border-border text-muted-foreground/60 transition-colors hover:border-foreground/40 hover:text-foreground"
                        aria-label="Assign"
                    >
                        <span class="text-[10px] leading-none">+</span>
                    </button>
                </template>
                <template v-else #trigger>
                    <button
                        type="button"
                        class="rounded-full"
                        aria-label="Assignees"
                    >
                        <AvatarStack :users="assignees" :max="2" />
                    </button>
                </template>
            </MemberPicker>
        </span>
    </article>
</template>
