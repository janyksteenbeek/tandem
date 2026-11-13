<script setup lang="ts">
import {
    AlertOctagon,
    Crown,
    Link2,
    MoreHorizontal,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '@/components/projects/dialogs/ConfirmDialog.vue';
import LinkIssueDialog from '@/components/projects/dialogs/LinkIssueDialog.vue';
import {
    DatePicker,
    EntityPicker,
    EstimatePicker,
    MemberPicker,
    StatusPicker,
} from '@/components/projects/pickers';
import InboxDot from '@/components/shared/InboxDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { injectIssueActions } from '@/composables/projects/useIssueActions';
import { Calendar } from '@lucide/vue';
import { HttpError } from '@/lib/http';
import { cn } from '@/lib/utils';
import type {
    Issue,
    IssueListItem,
    IssueLookups,
    WorkflowStatus,
    Workstream,
} from '@/types';

/**
 * One team's part of the issue: its own status, people, estimate, due date, cycle and
 * dependencies. `compact` renders it as a single row for single-team issues.
 */
const props = defineProps<{
    issue: Issue;
    workstream: Workstream;
    lookups: IssueLookups;
    compact?: boolean;
    editable: boolean;
}>();

const actions = injectIssueActions();

const team = computed(() =>
    props.lookups.teams.find((t) => t.id === props.workstream.team.id),
);
const statuses = computed<WorkflowStatus[]>(
    () =>
        (team.value?.workflow_id &&
            props.lookups.workflows[team.value.workflow_id]?.statuses) ||
        [],
);
const cycles = computed(() =>
    props.lookups.cycles
        .filter((c) => c.team_id === props.workstream.team.id)
        .map((c) => ({ id: c.id, name: c.name, hint: c.status })),
);
const isLead = computed(
    () => props.issue.lead_team?.id === props.workstream.team.id,
);
const blockers = computed(() =>
    props.issue.workstream_dependencies.filter(
        (d) => d.workstream_uuid === props.workstream.uuid,
    ),
);
const unresolved = computed(() => blockers.value.filter((b) => !b.resolved));

const responsibility = ref(props.workstream.responsibility ?? '');
watch(
    () => props.workstream.responsibility,
    (value) => (responsibility.value = value ?? ''),
);

const saveResponsibility = () => {
    const next = responsibility.value.trim() || null;

    if (next !== (props.workstream.responsibility ?? null)) {
        void actions.updateWorkstream(props.issue, props.workstream, {
            responsibility: next,
        });
    }
};

const forceStatus = ref<WorkflowStatus | null>(null);

const setStatus = async (status: WorkflowStatus) => {
    const result = await actions.setWorkstreamStatus(
        props.issue,
        props.workstream,
        status,
    );

    if (
        result === null &&
        status.category === 'completed' &&
        props.workstream.blocked_by_count > 0
    ) {
        forceStatus.value = status;
    }
};

const forceComplete = async () => {
    if (forceStatus.value) {
        await actions.setWorkstreamStatus(
            props.issue,
            props.workstream,
            forceStatus.value,
            true,
        );
        forceStatus.value = null;
    }
};

const dependencyDialog = ref(false);
const removeDialog = ref(false);

const addDependency = (picked: IssueListItem) =>
    void actions.addDependency(props.issue, props.workstream, {
        identifier: picked.identifier,
    });

void HttpError;
</script>

<template>
    <div
        :class="
            cn(
                'group/ws',
                compact
                    ? 'flex flex-wrap items-center gap-1'
                    : 'rounded-lg border bg-card px-3 py-2.5 shadow-pane',
            )
        "
    >
        <div :class="cn('flex items-center gap-2', compact ? 'mr-1' : 'mb-2')">
            <InboxDot :color="workstream.team.color" size="sm" />
            <span class="text-[13px] font-medium">{{
                workstream.team.name
            }}</span>
            <Tooltip v-if="isLead">
                <TooltipTrigger as-child>
                    <Crown class="size-3 text-highlight-foreground" />
                </TooltipTrigger>
                <TooltipContent>Lead team</TooltipContent>
            </Tooltip>
            <span
                v-if="!workstream.is_required"
                class="rounded-full border px-1.5 text-[10px] text-muted-foreground"
                >optional</span
            >
            <Tooltip v-if="unresolved.length > 0">
                <TooltipTrigger as-child>
                    <span
                        class="inline-flex items-center gap-1 rounded-full bg-destructive/10 px-1.5 py-px text-[10.5px] font-medium text-destructive"
                    >
                        <AlertOctagon class="size-3" /> Blocked
                    </span>
                </TooltipTrigger>
                <TooltipContent>
                    Waiting for
                    <span
                        v-for="b in unresolved"
                        :key="b.id ?? b.issue?.uuid"
                        class="block"
                        >{{ b.issue?.identifier
                        }}<template v-if="b.depends_on_team">
                            · {{ b.depends_on_team.name }}</template
                        ></span
                    >
                </TooltipContent>
            </Tooltip>

            <div
                v-if="!compact"
                class="ml-auto flex items-center gap-1 opacity-0 transition-opacity group-hover/ws:opacity-100 focus-within:opacity-100"
            >
                <label
                    v-if="issue.can.manage_teams"
                    class="flex items-center gap-1 text-[11px] text-muted-foreground"
                >
                    <Switch
                        :model-value="workstream.is_required"
                        class="scale-[0.65]"
                        @update:model-value="
                            (v: boolean) =>
                                actions.updateWorkstream(issue, workstream, {
                                    is_required: v,
                                })
                        "
                    />
                    Required
                </label>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-6"
                            aria-label="Workstream actions"
                        >
                            <MoreHorizontal class="size-3.5" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-52">
                        <DropdownMenuItem
                            v-if="editable"
                            class="gap-2"
                            @click="dependencyDialog = true"
                            ><Link2 class="size-4" /> Wait for another
                            issue…</DropdownMenuItem
                        >
                        <template v-if="issue.can.manage_teams">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                class="gap-2 text-destructive focus:text-destructive"
                                @click="removeDialog = true"
                                ><Trash2 class="size-4" /> Remove
                                team</DropdownMenuItem
                            >
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <textarea
            v-if="!compact && (editable || responsibility)"
            v-model="responsibility"
            rows="1"
            :readonly="!editable"
            placeholder="What is this team responsible for?"
            class="mb-2 w-full resize-none rounded-md bg-transparent px-1 py-0.5 text-[13px] text-foreground outline-none placeholder:text-muted-foreground/60 read-only:cursor-default focus:bg-accent/40"
            @blur="saveResponsibility"
            @keydown.enter.prevent="
                ($event.target as HTMLTextAreaElement).blur()
            "
        />

        <div class="flex flex-wrap items-center gap-0.5">
            <StatusPicker
                :status="workstream.status"
                :options="statuses"
                :disabled="!editable"
                :compact="compact"
                @change="setStatus"
            />
            <MemberPicker
                :selected="workstream.assignees"
                :members="lookups.members"
                multiple
                placeholder="Assign…"
                empty-label="Unassigned"
                :disabled="!editable"
                :compact="compact"
                @change="
                    (users) =>
                        actions.setAssignees(
                            issue,
                            workstream,
                            users.map((u) => u.id),
                        )
                "
            />
            <EstimatePicker
                :value="workstream.estimate"
                :scale="team?.estimate_scale ?? 'fibonacci'"
                :disabled="!editable"
                :compact="compact"
                @change="
                    (v) =>
                        actions.updateWorkstream(issue, workstream, {
                            estimate: v,
                        })
                "
            />
            <DatePicker
                :value="workstream.due_date"
                label="Due"
                :category="workstream.status.category"
                :disabled="!editable"
                :compact="compact"
                @change="
                    (v) =>
                        actions.updateWorkstream(issue, workstream, {
                            due_date: v,
                        })
                "
            />
            <EntityPicker
                v-if="cycles.length > 0 || workstream.cycle"
                :selected="
                    workstream.cycle
                        ? [
                              {
                                  id: workstream.cycle.id,
                                  name: workstream.cycle.name,
                              },
                          ]
                        : []
                "
                :options="cycles"
                :icon="Calendar"
                placeholder="Cycle…"
                empty-label="No cycle"
                :disabled="!editable"
                :compact="compact"
                @change="
                    (list) =>
                        actions.setWorkstreamCycle(
                            issue,
                            workstream,
                            list[0]?.id ?? null,
                        )
                "
            />
            <DropdownMenu
                v-if="compact && (editable || issue.can.manage_teams)"
            >
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7"
                        aria-label="Workstream actions"
                    >
                        <MoreHorizontal class="size-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-52">
                    <DropdownMenuItem
                        v-if="editable"
                        class="gap-2"
                        @click="dependencyDialog = true"
                        ><Link2 class="size-4" /> Wait for another
                        issue…</DropdownMenuItem
                    >
                    <DropdownMenuItem
                        v-if="issue.can.manage_teams"
                        class="gap-2"
                        @click="
                            actions.updateWorkstream(issue, workstream, {
                                is_required: !workstream.is_required,
                            })
                        "
                    >
                        {{
                            workstream.is_required
                                ? 'Mark optional'
                                : 'Mark required'
                        }}
                    </DropdownMenuItem>
                    <template v-if="issue.can.manage_teams">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            class="gap-2 text-destructive focus:text-destructive"
                            @click="removeDialog = true"
                            ><Trash2 class="size-4" /> Remove
                            team</DropdownMenuItem
                        >
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <ConfirmDialog
            :open="forceStatus !== null"
            title="This workstream is still blocked"
            description="Something it waits for is not finished. Complete it anyway?"
            confirm-label="Complete anyway"
            @update:open="(v) => !v && (forceStatus = null)"
            @confirm="forceComplete"
        />
        <ConfirmDialog
            v-model:open="removeDialog"
            :title="`Remove ${workstream.team.name} from this issue?`"
            description="Their status, assignees and dependencies on this issue are discarded."
            confirm-label="Remove team"
            destructive
            @confirm="
                actions
                    .removeTeam(issue, workstream)
                    .then(() => (removeDialog = false))
            "
        />
        <LinkIssueDialog
            v-model:open="dependencyDialog"
            mode="dependency"
            :exclude="issue.identifier"
            @pick="addDependency"
        />
    </div>
</template>
