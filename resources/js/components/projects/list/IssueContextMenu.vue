<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Copy, ExternalLink, Link2, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import PriorityIcon from '@/components/projects/shared/PriorityIcon.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import {
    ContextMenu,
    ContextMenuCheckboxItem,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuSeparator,
    ContextMenuShortcut,
    ContextMenuSub,
    ContextMenuSubContent,
    ContextMenuSubTrigger,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import { injectIssueActions } from '@/composables/projects/useIssueActions';
import { PRIORITY_META, PRIORITY_ORDER } from '@/lib/projects/meta';
import { chipClass } from '@/lib/mail';
import type {
    IssueListItem,
    IssueLookups,
    UserSummary,
    WorkflowStatus,
} from '@/types';

/**
 * Right-click menu shared by rows and board cards. Mutations go through the injected actions.
 */
const props = withDefaults(
    defineProps<{
        issue: IssueListItem;
        lookups: IssueLookups | null;
        workflow: WorkflowStatus[];
        focusTeamId?: number | null;
    }>(),
    { focusTeamId: null },
);
const emit = defineEmits<{
    open: [issue: IssueListItem];
    deleted: [issue: IssueListItem];
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const actions = injectIssueActions();

const workstream = computed(() =>
    props.focusTeamId ? (props.issue.workstream ?? null) : null,
);
const status = computed(() => workstream.value?.status ?? props.issue.status);
const statuses = computed<WorkflowStatus[]>(() => {
    if (workstream.value && props.lookups) {
        const team = props.lookups.teams.find(
            (t) => t.id === props.focusTeamId,
        );
        const wf = team?.workflow_id
            ? props.lookups.workflows[team.workflow_id]
            : null;

        if (wf) {
            return wf.statuses;
        }
    }

    return props.workflow;
});

const members = computed(() => props.lookups?.members ?? []);
const assignees = computed(
    () =>
        (workstream.value ?? props.issue.workstream)?.assignees ??
        props.issue.assignees,
);
const projects = computed(() =>
    (props.lookups?.projects ?? []).filter(
        (p) =>
            !props.issue.product ||
            !p.product ||
            p.product.id === props.issue.product.id,
    ),
);

const url = computed(() => `/${slug.value}/issues/${props.issue.identifier}`);
const absoluteUrl = () => `${window.location.origin}${url.value}`;

const copy = async (text: string, label: string) => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`${label} copied`);
    } catch {
        toast.error('Could not copy');
    }
};

const setStatus = (next: WorkflowStatus) => {
    if (workstream.value) {
        void actions.setWorkstreamStatus(props.issue, workstream.value, next);
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

const toggleAssignee = (user: UserSummary, on: boolean) => {
    const ws = workstream.value ?? props.issue.workstream;

    if (ws) {
        const ids = on
            ? [...ws.assignees.map((u) => u.id), user.id]
            : ws.assignees.filter((u) => u.id !== user.id).map((u) => u.id);
        void actions.setAssignees(props.issue, ws, ids);

        return;
    }

    void actions.setOwner(props.issue, on ? user.id : null, on ? user : null);
};

const remove = async () => {
    if (
        !window.confirm(
            `Delete ${props.issue.identifier}? This can be undone by an admin.`,
        )
    ) {
        return;
    }

    if (await actions.destroy(props.issue)) {
        emit('deleted', props.issue);
    }
};
</script>

<template>
    <ContextMenu>
        <ContextMenuTrigger as-child>
            <slot />
        </ContextMenuTrigger>
        <ContextMenuContent class="w-56">
            <ContextMenuItem class="gap-2" @select="emit('open', issue)">
                <ExternalLink class="size-3.5" /> Open
                <ContextMenuShortcut>↵</ContextMenuShortcut>
            </ContextMenuItem>
            <ContextMenuItem
                class="gap-2"
                @select="router.visit(url, { viewTransition: true })"
            >
                <ExternalLink class="size-3.5" /> Open in new tab
            </ContextMenuItem>
            <ContextMenuSeparator />

            <ContextMenuSub>
                <ContextMenuSubTrigger class="gap-2"
                    ><StatusIcon :status="status" :size="13" />
                    Status</ContextMenuSubTrigger
                >
                <ContextMenuSubContent class="w-48">
                    <ContextMenuCheckboxItem
                        v-for="s in statuses"
                        :key="s.id"
                        :model-value="s.id === status.id"
                        class="gap-2"
                        @select="setStatus(s)"
                    >
                        <StatusIcon :status="s" :size="13" /> {{ s.name }}
                    </ContextMenuCheckboxItem>
                </ContextMenuSubContent>
            </ContextMenuSub>

            <ContextMenuSub>
                <ContextMenuSubTrigger class="gap-2"
                    ><PriorityIcon :priority="issue.priority" :size="13" />
                    Priority</ContextMenuSubTrigger
                >
                <ContextMenuSubContent class="w-44">
                    <ContextMenuCheckboxItem
                        v-for="p in PRIORITY_ORDER"
                        :key="p"
                        :model-value="p === issue.priority"
                        class="gap-2"
                        @select="actions.setPriority(issue, p)"
                    >
                        <PriorityIcon :priority="p" :size="13" />
                        {{ PRIORITY_META[p].label }}
                    </ContextMenuCheckboxItem>
                </ContextMenuSubContent>
            </ContextMenuSub>

            <ContextMenuSub v-if="members.length">
                <ContextMenuSubTrigger class="gap-2"
                    >Assign</ContextMenuSubTrigger
                >
                <ContextMenuSubContent class="max-h-72 w-56 overflow-y-auto">
                    <ContextMenuCheckboxItem
                        v-for="member in members"
                        :key="member.id"
                        :model-value="assignees.some((a) => a.id === member.id)"
                        class="gap-2"
                        @select="
                            toggleAssignee(
                                member,
                                !assignees.some((a) => a.id === member.id),
                            )
                        "
                    >
                        <UserAvatar
                            :name="member.name"
                            :email="member.email"
                            :src="member.avatar"
                            :initials="member.initials"
                            size="xs"
                        />
                        <span class="truncate">{{ member.name }}</span>
                    </ContextMenuCheckboxItem>
                </ContextMenuSubContent>
            </ContextMenuSub>

            <ContextMenuSub v-if="lookups?.labels.length">
                <ContextMenuSubTrigger class="gap-2"
                    >Labels</ContextMenuSubTrigger
                >
                <ContextMenuSubContent class="max-h-72 w-52 overflow-y-auto">
                    <ContextMenuCheckboxItem
                        v-for="label in lookups.labels.filter(
                            (l) =>
                                !l.product_id ||
                                l.product_id === issue.product?.id,
                        )"
                        :key="label.id"
                        :model-value="
                            issue.labels.some((l) => l.id === label.id)
                        "
                        class="gap-2"
                        @select="
                            actions.toggleLabel(
                                issue,
                                label.id,
                                !issue.labels.some((l) => l.id === label.id),
                            )
                        "
                    >
                        <span
                            :class="[
                                'size-2 rounded-full bg-(--chip)',
                                chipClass(label.color),
                            ]"
                        />
                        {{ label.name }}
                    </ContextMenuCheckboxItem>
                </ContextMenuSubContent>
            </ContextMenuSub>

            <ContextMenuSub v-if="projects.length">
                <ContextMenuSubTrigger class="gap-2"
                    >Move to project</ContextMenuSubTrigger
                >
                <ContextMenuSubContent class="max-h-72 w-56 overflow-y-auto">
                    <ContextMenuCheckboxItem
                        :model-value="!issue.project"
                        @select="
                            actions.move(issue, issue.product?.id ?? null, null)
                        "
                        >No project</ContextMenuCheckboxItem
                    >
                    <ContextMenuCheckboxItem
                        v-for="project in projects"
                        :key="project.id"
                        :model-value="issue.project?.id === project.id"
                        class="gap-2"
                        @select="
                            actions.move(
                                issue,
                                project.product?.id ??
                                    issue.product?.id ??
                                    null,
                                project.id,
                            )
                        "
                    >
                        <InboxDot :color="project.color" size="sm" />
                        <span class="truncate">{{ project.name }}</span>
                    </ContextMenuCheckboxItem>
                </ContextMenuSubContent>
            </ContextMenuSub>

            <ContextMenuSeparator />
            <ContextMenuItem
                class="gap-2"
                @select="copy(issue.identifier, 'ID')"
                ><Copy class="size-3.5" /> Copy ID</ContextMenuItem
            >
            <ContextMenuItem class="gap-2" @select="copy(absoluteUrl(), 'Link')"
                ><Link2 class="size-3.5" /> Copy link</ContextMenuItem
            >
            <ContextMenuSeparator />
            <ContextMenuItem
                class="gap-2 text-destructive focus:text-destructive"
                @select="remove"
                ><Trash2 class="size-3.5" /> Delete</ContextMenuItem
            >
        </ContextMenuContent>
    </ContextMenu>
</template>
