<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Bell,
    BellOff,
    Box,
    Flag,
    FolderKanban,
    GitBranch,
    Layers,
    Sparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/projects/dialogs/ConfirmDialog.vue';
import LinkIssueDialog from '@/components/projects/dialogs/LinkIssueDialog.vue';
import {
    DatePicker,
    EntityPicker,
    EstimatePicker,
    LabelPicker,
    MemberPicker,
    PriorityPicker,
    StatusPicker,
    TeamPicker,
} from '@/components/projects/pickers';
import AvatarStack from '@/components/projects/shared/AvatarStack.vue';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { injectIssueActions } from '@/composables/projects/useIssueActions';
import { fullTime, relative } from '@/lib/time';
import { cn } from '@/lib/utils';
import type {
    Issue,
    IssueListItem,
    IssueLookups,
    WorkflowStatus,
} from '@/types';

/**
 * The property panel: every global issue property as a borderless row with a picker. On
 * narrow screens the parent renders it as a collapsible block under the title.
 */
const props = defineProps<{
    issue: Issue;
    lookups: IssueLookups;
    orgSlug: string;
    stacked?: boolean;
}>();

const actions = injectIssueActions();

const statuses = computed<WorkflowStatus[]>(() => {
    const product = props.lookups.products.find(
        (p) => p.id === props.issue.product?.id,
    );
    const workflowId =
        product?.workflow_id ?? props.lookups.default_workflow_id;

    return (
        props.lookups.workflows[workflowId]?.statuses ?? props.lookups.statuses
    );
});

const modules = computed(() =>
    props.lookups.modules.filter(
        (m) => m.product_id === props.issue.product?.id,
    ),
);
const projectOptions = computed(() =>
    props.lookups.projects
        .filter(
            (p) =>
                !props.issue.product ||
                !p.product ||
                p.product.id === props.issue.product.id,
        )
        .map((p) => ({ id: p.id, name: p.name, color: p.color })),
);
const milestoneOptions = computed(() =>
    props.lookups.milestones
        .filter((m) => m.project_id === props.issue.project?.id)
        .map((m) => ({ id: m.id, name: m.name })),
);
const leadScale = computed(
    () =>
        props.lookups.teams.find((t) => t.id === props.issue.lead_team?.id)
            ?.estimate_scale ?? 'fibonacci',
);

const blocked = ref<{
    status: WorkflowStatus;
    required: number;
    done: number;
} | null>(null);
const reason = ref('');
const overriding = ref(false);

const setStatus = async (status: WorkflowStatus) => {
    const result = await actions.setStatus(props.issue, status);

    if (result && 'readiness' in result) {
        blocked.value = {
            status: result.status,
            required: result.readiness.required,
            done:
                result.readiness.required_completed +
                result.readiness.required_canceled,
        };
        reason.value = '';
    }
};

const override = async () => {
    if (!blocked.value) {
        return;
    }

    overriding.value = true;
    await actions.setStatus(
        props.issue,
        blocked.value.status,
        true,
        reason.value || undefined,
    );
    overriding.value = false;
    blocked.value = null;
};

const parentDialog = ref(false);
const setParent = (picked: IssueListItem) =>
    void actions.setParent(props.issue, picked.identifier);

const rowClass = 'flex min-h-7 items-center gap-2';
const labelClass = 'w-24 shrink-0 text-[12px] text-muted-foreground';
</script>

<template>
    <div :class="cn('space-y-0.5', stacked ? '' : 'px-4 py-4')">
        <div v-if="!stacked" class="mb-3 flex items-center justify-between">
            <IssueIdentifier
                :identifier="issue.identifier"
                class="text-[12px]"
                :muted="false"
            />
            <Button
                variant="ghost"
                size="sm"
                class="h-6 gap-1 px-1.5 text-[11.5px] text-muted-foreground"
                @click="actions.follow(issue, !issue.is_following)"
            >
                <BellOff v-if="issue.is_following" class="size-3" />
                <Bell v-else class="size-3" />
                {{ issue.is_following ? 'Following' : 'Follow' }}
            </Button>
        </div>

        <div :class="rowClass">
            <span :class="labelClass">Status</span>
            <StatusPicker
                :status="issue.status"
                :options="statuses"
                :disabled="!issue.can.set_status"
                @change="setStatus"
            />
        </div>
        <div
            v-if="
                issue.readiness.ready && issue.status_category !== 'completed'
            "
            class="ml-24 flex items-center gap-1.5 rounded-md bg-brand-soft/60 px-2 py-1 text-[11.5px] text-brand-soft-foreground"
        >
            <Sparkles class="size-3" /> Every required team is done — ready to
            complete
        </div>
        <div
            v-else-if="
                issue.suggested_status &&
                issue.suggested_status.id !== issue.status.id &&
                issue.status_category !== 'completed' &&
                issue.status_category !== 'canceled'
            "
            class="ml-24 flex items-center gap-1.5 text-[11.5px] text-muted-foreground"
        >
            <StatusIcon :status="issue.suggested_status" :size="12" /> Teams
            suggest {{ issue.suggested_status.name }}
        </div>

        <div :class="rowClass">
            <span :class="labelClass">Priority</span>
            <PriorityPicker
                :priority="issue.priority"
                :disabled="!issue.can.update"
                @change="(p) => actions.setPriority(issue, p)"
            />
        </div>
        <div :class="rowClass">
            <span :class="labelClass">Owner</span>
            <MemberPicker
                :selected="issue.owner ? [issue.owner] : []"
                :members="lookups.members"
                placeholder="Owner…"
                empty-label="No owner"
                :disabled="!issue.can.update"
                @change="
                    (users) =>
                        actions.setOwner(
                            issue,
                            users[0]?.id ?? null,
                            users[0] ?? null,
                        )
                "
            />
        </div>
        <div :class="rowClass">
            <span :class="labelClass">Lead team</span>
            <TeamPicker
                :selected="issue.lead_team ? [issue.lead_team] : []"
                :options="lookups.teams"
                placeholder="Lead team…"
                empty-label="No lead team"
                :disabled="!issue.can.update"
                @change="
                    (teams) => actions.setLeadTeam(issue, teams[0]?.id ?? null)
                "
            />
        </div>

        <div class="my-2 border-t" />

        <div :class="rowClass">
            <span :class="labelClass">Product</span>
            <EntityPicker
                :selected="
                    issue.product
                        ? [
                              {
                                  id: issue.product.id,
                                  name: issue.product.name,
                                  color: issue.product.color,
                              },
                          ]
                        : []
                "
                :options="
                    lookups.products.map((p) => ({
                        id: p.id,
                        name: p.name,
                        color: p.color,
                        hint: p.key,
                    }))
                "
                :icon="Box"
                placeholder="Product…"
                empty-label="No product"
                :disabled="!issue.can.move"
                @change="
                    (list) =>
                        actions.move(
                            issue,
                            list[0]?.id ?? null,
                            list[0] &&
                                issue.project?.product &&
                                issue.project.product.id !== list[0].id
                                ? null
                                : (issue.project?.id ?? null),
                        )
                "
            />
        </div>
        <div v-if="issue.product" :class="rowClass">
            <span :class="labelClass">Modules</span>
            <EntityPicker
                :selected="
                    issue.modules.map((m) => ({
                        id: m.id,
                        name: m.name,
                        color: m.color,
                    }))
                "
                :options="
                    modules.map((m) => ({
                        id: m.id,
                        name: m.name,
                        color: m.color,
                    }))
                "
                :icon="Layers"
                multiple
                placeholder="Modules…"
                empty-label="No module"
                :disabled="!issue.can.update"
                @change="
                    (list) =>
                        actions.setModules(
                            issue,
                            list.map((m) => m.id),
                        )
                "
            />
        </div>
        <div :class="rowClass">
            <span :class="labelClass">Project</span>
            <EntityPicker
                :selected="
                    issue.project
                        ? [
                              {
                                  id: issue.project.id,
                                  name: issue.project.name,
                                  color: issue.project.color,
                              },
                          ]
                        : []
                "
                :options="projectOptions"
                :icon="FolderKanban"
                placeholder="Project…"
                empty-label="No project"
                :disabled="!issue.can.move"
                @change="
                    (list) =>
                        actions.move(
                            issue,
                            issue.product?.id ?? null,
                            list[0]?.id ?? null,
                        )
                "
            />
        </div>
        <div
            v-if="issue.project && milestoneOptions.length > 0"
            :class="rowClass"
        >
            <span :class="labelClass">Milestone</span>
            <EntityPicker
                :selected="
                    milestoneOptions.filter((m) => m.id === issue.milestone?.id)
                "
                :options="milestoneOptions"
                :icon="Flag"
                placeholder="Milestone…"
                empty-label="No milestone"
                :disabled="!issue.can.update"
                @change="
                    (list) => actions.setMilestone(issue, list[0]?.id ?? null)
                "
            />
        </div>
        <div :class="rowClass">
            <span :class="labelClass">Labels</span>
            <LabelPicker
                :selected="issue.labels"
                :available="lookups.labels"
                :product-id="issue.product?.id ?? null"
                :disabled="!issue.can.update"
                @toggle="
                    (label, attached, name) =>
                        actions.toggleLabel(
                            issue,
                            label?.id ?? null,
                            attached,
                            name,
                        )
                "
            />
        </div>

        <div class="my-2 border-t" />

        <div :class="rowClass">
            <span :class="labelClass">Estimate</span>
            <EstimatePicker
                :value="issue.estimate"
                :scale="leadScale"
                :disabled="!issue.can.update"
                @change="(v) => actions.setEstimate(issue, v)"
            />
        </div>
        <div :class="rowClass">
            <span :class="labelClass">Start</span>
            <DatePicker
                :value="issue.start_date"
                label="Start date"
                :disabled="!issue.can.update"
                @change="(v) => actions.setDates(issue, { start_date: v })"
            />
        </div>
        <div :class="rowClass">
            <span :class="labelClass">Due</span>
            <DatePicker
                :value="issue.due_date"
                label="Due date"
                :category="issue.status_category"
                :disabled="!issue.can.update"
                @change="(v) => actions.setDates(issue, { due_date: v })"
            />
        </div>
        <div :class="rowClass">
            <span :class="labelClass">Parent</span>
            <div class="flex min-w-0 items-center gap-1">
                <Link
                    v-if="issue.parent"
                    :href="`/${orgSlug}/issues/${issue.parent.identifier}`"
                    class="flex min-w-0 items-center gap-1.5 rounded-md px-1.5 py-1 text-[12.5px] hover:bg-accent"
                >
                    <GitBranch class="size-3.5 text-muted-foreground" />
                    <IssueIdentifier :identifier="issue.parent.identifier" />
                    <span class="truncate">{{ issue.parent.title }}</span>
                </Link>
                <button
                    v-else-if="issue.can.update"
                    type="button"
                    class="flex h-7 items-center gap-1.5 rounded-md px-1.5 text-[12.5px] text-muted-foreground hover:bg-accent"
                    @click="parentDialog = true"
                >
                    <GitBranch class="size-3.5" /> Set parent
                </button>
                <span v-else class="px-1.5 text-[12.5px] text-muted-foreground"
                    >None</span
                >
                <Tooltip v-if="issue.parent && issue.can.update">
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="rounded px-1 text-[11px] text-muted-foreground hover:text-foreground"
                            @click="actions.setParent(issue, null)"
                        >
                            ×
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>Remove parent</TooltipContent>
                </Tooltip>
            </div>
        </div>

        <div class="my-2 border-t" />

        <div :class="rowClass">
            <span :class="labelClass">Followers</span>
            <div class="flex items-center gap-2 px-1.5">
                <AvatarStack
                    v-if="issue.followers.length"
                    :users="issue.followers"
                    :max="5"
                />
                <span v-else class="text-[12.5px] text-muted-foreground"
                    >Nobody yet</span
                >
            </div>
        </div>

        <div class="mt-3 space-y-0.5 text-[11.5px] text-muted-foreground">
            <p v-if="issue.created_by">
                Created by {{ issue.created_by.name }}
                <Tooltip
                    ><TooltipTrigger as-child
                        ><span>{{
                            relative(issue.created_at)
                        }}</span></TooltipTrigger
                    ><TooltipContent>{{
                        fullTime(issue.created_at)
                    }}</TooltipContent></Tooltip
                >
            </p>
            <p v-else>Created {{ relative(issue.created_at) }}</p>
            <p>Updated {{ relative(issue.updated_at) }}</p>
            <p v-if="issue.completed_at">
                Completed {{ relative(issue.completed_at) }}
            </p>
        </div>

        <ConfirmDialog
            :open="blocked !== null"
            title="Not every required team is done"
            :description="
                blocked
                    ? `${blocked.done} of ${blocked.required} required workstreams are finished. Completing now overrides the teams' status and is recorded in the audit log.`
                    : ''
            "
            confirm-label="Complete anyway"
            :busy="overriding"
            @update:open="(v) => !v && (blocked = null)"
            @confirm="override"
        >
            <Textarea
                v-if="issue.can.override_completion"
                v-model="reason"
                placeholder="Why is it done anyway? (optional)"
                rows="2"
                class="text-[13px]"
            />
            <p v-else class="text-[12.5px] text-muted-foreground">
                Only the issue owner, a lead or an admin can complete it early.
            </p>
        </ConfirmDialog>

        <LinkIssueDialog
            v-model:open="parentDialog"
            mode="parent"
            :exclude="issue.identifier"
            @pick="setParent"
        />
    </div>
</template>
