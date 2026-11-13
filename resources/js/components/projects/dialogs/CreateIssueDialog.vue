<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    Box,
    FolderKanban,
    Layers,
    Mail,
    Maximize2,
    Minimize2,
    Users,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import RichTextEditor from '@/components/projects/editor/RichTextEditor.vue';
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
import { useIssueLookups } from '@/composables/projects/useIssueLookups';
import { useShortcutScope } from '@/composables/useKeyboard';
import { api, HttpError } from '@/lib/http';
import { PRIORITY } from '@/lib/keyboard';
import { issueStore } from '@/lib/projects/issueStore';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Switch } from '@/components/ui/switch';
import type {
    CreateIssuePrefill,
    Issue,
    IssuePriority,
    Label,
    TeamSummary,
    UserSummary,
    WorkflowStatus,
} from '@/types';

/**
 * The one Create Issue dialog, mounted in the app shell and opened from the sidebar, the C
 * shortcut, the palette and mail conversations. Lookups load lazily so mail pages stay light.
 */
const open = defineModel<boolean>('open', { default: false });
const props = defineProps<{ prefill?: CreateIssuePrefill | null }>();
const emit = defineEmits<{ created: [issue: Issue] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const { lookups, load } = useIssueLookups();

const title = ref('');
const description = ref('');
const productId = ref<number | null>(null);
const moduleIds = ref<number[]>([]);
const projectId = ref<number | null>(null);
const milestoneId = ref<number | null>(null);
const statusId = ref<number | null>(null);
const priority = ref<IssuePriority>('none');
const ownerId = ref<number | null>(null);
const teamIds = ref<number[]>([]);
const assigneeIds = ref<number[]>([]);
const labelIds = ref<number[]>([]);
const dueDate = ref<string | null>(null);
const estimate = ref<number | null>(null);
const parentIdentifier = ref<string | null>(null);
const conversationUuid = ref<string | null>(null);
const conversationSubject = ref<string | null>(null);
const linkConversation = ref(true);
const createMore = ref(false);
const expanded = ref(false);
const busy = ref(false);
const errors = ref<Record<string, string[]>>({});
const titleInput = ref<HTMLInputElement | null>(null);

useShortcutScope('Create issue', open, PRIORITY.dialog, () => [
    {
        keys: 'mod+enter',
        description: 'Create',
        allowInInput: true,
        handler: () => submit(),
        hidden: true,
    },
]);

const reset = (prefill?: CreateIssuePrefill | null) => {
    title.value = prefill?.title ?? '';
    description.value = prefill?.description_html ?? '';
    productId.value = prefill?.product_id ?? null;
    moduleIds.value = prefill?.module_ids ?? [];
    projectId.value = prefill?.project_id ?? null;
    milestoneId.value = prefill?.milestone_id ?? null;
    statusId.value = prefill?.status_id ?? null;
    priority.value = prefill?.priority ?? 'none';
    ownerId.value = prefill?.owner_id ?? null;
    teamIds.value =
        prefill?.team_ids ??
        (prefill?.lead_team_id ? [prefill.lead_team_id] : []);
    assigneeIds.value = prefill?.assignee_ids ?? [];
    labelIds.value = prefill?.label_ids ?? [];
    dueDate.value = prefill?.due_date ?? null;
    estimate.value = null;
    parentIdentifier.value = prefill?.parent_identifier ?? null;
    conversationUuid.value = prefill?.conversation_uuid ?? null;
    conversationSubject.value = prefill?.conversation_subject ?? null;
    linkConversation.value = true;
    errors.value = {};
};

watch(open, async (value) => {
    if (!value) {
        return;
    }

    reset(props.prefill);
    await load();
    await nextTick();
    titleInput.value?.focus();
});

const product = computed(
    () => lookups.value?.products.find((p) => p.id === productId.value) ?? null,
);
const project = computed(
    () => lookups.value?.projects.find((p) => p.id === projectId.value) ?? null,
);
const workflowStatuses = computed<WorkflowStatus[]>(() => {
    const id = product.value?.workflow_id ?? lookups.value?.default_workflow_id;

    return (
        (id && lookups.value?.workflows[id]?.statuses) ||
        lookups.value?.statuses ||
        []
    );
});
const status = computed(
    () =>
        workflowStatuses.value.find((s) => s.id === statusId.value) ??
        workflowStatuses.value.find((s) => s.is_default) ??
        null,
);
const teams = computed<TeamSummary[]>(() =>
    (lookups.value?.teams ?? []).filter((t) => teamIds.value.includes(t.id)),
);
const owner = computed<UserSummary[]>(() =>
    (lookups.value?.members ?? []).filter((m) => m.id === ownerId.value),
);
const assignees = computed<UserSummary[]>(() =>
    (lookups.value?.members ?? []).filter((m) =>
        assigneeIds.value.includes(m.id),
    ),
);
const labels = computed<Label[]>(() =>
    (lookups.value?.labels ?? []).filter((l) => labelIds.value.includes(l.id)),
);
const modules = computed(() =>
    (lookups.value?.modules ?? []).filter(
        (m) => m.product_id === productId.value,
    ),
);
const selectedModules = computed(() =>
    modules.value.filter((m) => moduleIds.value.includes(m.id)),
);
const projectOptions = computed(() =>
    (lookups.value?.projects ?? [])
        .filter(
            (p) =>
                !productId.value ||
                !p.product ||
                p.product.id === productId.value,
        )
        .map((p) => ({ id: p.id, name: p.name, color: p.color })),
);
const milestoneOptions = computed(() =>
    (lookups.value?.milestones ?? [])
        .filter((m) => m.project_id === projectId.value)
        .map((m) => ({ id: m.id, name: m.name })),
);

watch(projectId, (id) => {
    const p = lookups.value?.projects.find((x) => x.id === id);

    if (p?.product && productId.value !== p.product.id) {
        productId.value = p.product.id;
    }

    if (!id) {
        milestoneId.value = null;
    }
});

watch(productId, () => {
    moduleIds.value = moduleIds.value.filter((id) =>
        modules.value.some((m) => m.id === id),
    );
    statusId.value = null;
});

const canSubmit = computed(() => title.value.trim().length > 0 && !busy.value);

const submit = async () => {
    if (!canSubmit.value) {
        return;
    }

    busy.value = true;
    errors.value = {};

    try {
        const data = await api.post<{ issue: Issue }>(`/${slug.value}/issues`, {
            title: title.value.trim(),
            description_html: description.value || null,
            product_id: productId.value,
            project_id: projectId.value,
            milestone_id: milestoneId.value,
            parent_id: null,
            status_id: status.value?.id ?? null,
            priority: priority.value,
            owner_id: ownerId.value,
            lead_team_id: teamIds.value[0] ?? null,
            label_ids: labelIds.value,
            module_ids: moduleIds.value,
            estimate: estimate.value,
            due_date: dueDate.value,
            teams: teamIds.value.map((team_id, index) => ({
                team_id,
                is_required: true,
                assignee_ids: index === 0 ? assigneeIds.value : [],
            })),
            conversation_uuid: linkConversation.value
                ? conversationUuid.value
                : null,
        });

        if (parentIdentifier.value) {
            await api
                .post(`/${slug.value}/issues/${data.issue.identifier}/parent`, {
                    parent_identifier: parentIdentifier.value,
                })
                .catch(() => null);
        }

        issueStore.upsert(data.issue, { force: true });
        emit('created', data.issue);

        toast.success(`${data.issue.identifier} created`, {
            action: {
                label: 'Open',
                onClick: () =>
                    router.visit(
                        `/${slug.value}/issues/${data.issue.identifier}`,
                    ),
            },
        });

        router.reload({ only: ['projectsNav'] });

        if (createMore.value) {
            const keep: CreateIssuePrefill = {
                product_id: productId.value,
                project_id: projectId.value,
                team_ids: teamIds.value,
                module_ids: moduleIds.value,
                label_ids: labelIds.value,
            };
            reset(keep);
            await nextTick();
            titleInput.value?.focus();
        } else {
            open.value = false;
        }
    } catch (error) {
        if (error instanceof HttpError) {
            errors.value = error.errors;
            toast.error(Object.values(error.errors)[0]?.[0] ?? error.message);
        } else {
            toast.error('Could not create the issue.');
        }
    } finally {
        busy.value = false;
    }
};

const context = computed(() => {
    const parts: string[] = [];

    if (product.value) {
        parts.push(product.value.name);
    }

    if (project.value) {
        parts.push(project.value.name);
    }

    return parts.join(' › ') || page.props.currentOrganization?.name || '';
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            :class="
                cn(
                    'top-[10%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-2xl',
                    expanded && 'top-[4%] sm:max-w-4xl',
                )
            "
            :show-close-button="false"
            @escape-key-down="open = false"
        >
            <DialogTitle class="sr-only">Create issue</DialogTitle>
            <DialogDescription class="sr-only"
                >Describe the work and pick who owns it.</DialogDescription
            >

            <div
                class="flex items-center gap-2 border-b px-4 py-2 text-[11.5px] text-muted-foreground"
            >
                <Layers class="size-3.5" />
                <span class="truncate">{{ context }}</span>
                <span class="mx-1 text-border">·</span>
                <span>New issue</span>
                <div class="ml-auto flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-6"
                        :aria-label="expanded ? 'Shrink' : 'Expand'"
                        @click="expanded = !expanded"
                    >
                        <Minimize2 v-if="expanded" class="size-3.5" />
                        <Maximize2 v-else class="size-3.5" />
                    </Button>
                </div>
            </div>

            <div class="space-y-3 px-5 pt-4 pb-2">
                <input
                    ref="titleInput"
                    v-model="title"
                    type="text"
                    placeholder="Issue title"
                    class="w-full bg-transparent text-[17px] font-semibold tracking-[-0.01em] outline-none placeholder:text-muted-foreground/60"
                    @keydown.enter.prevent="submit"
                />
                <p v-if="errors.title" class="text-xs text-destructive">
                    {{ errors.title[0] }}
                </p>

                <RichTextEditor
                    v-model="description"
                    placeholder="Add a description… Type @ to mention someone."
                    :members="lookups?.members ?? []"
                    compact
                    :min-height="expanded ? '16rem' : '5rem'"
                />

                <div
                    v-if="conversationUuid"
                    class="flex items-center gap-2 rounded-lg border border-dashed bg-surface px-3 py-2 text-[12px]"
                >
                    <Mail class="size-3.5 text-muted-foreground" />
                    <span class="min-w-0 flex-1 truncate">
                        From conversation
                        <span class="font-medium text-foreground">{{
                            conversationSubject ?? conversationUuid
                        }}</span>
                    </span>
                    <label
                        class="flex items-center gap-1.5 text-muted-foreground"
                    >
                        <Switch v-model="linkConversation" class="scale-75" />
                        Link it
                    </label>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-1 border-t px-3 py-2">
                <StatusPicker
                    :status="status"
                    :options="workflowStatuses"
                    @change="(s) => (statusId = s.id)"
                />
                <PriorityPicker
                    :priority="priority"
                    @change="(p) => (priority = p)"
                />
                <MemberPicker
                    :selected="owner"
                    :members="lookups?.members ?? []"
                    placeholder="Owner…"
                    empty-label="Owner"
                    @change="(users) => (ownerId = users[0]?.id ?? null)"
                />
                <TeamPicker
                    :selected="teams"
                    :options="lookups?.teams ?? []"
                    multiple
                    placeholder="Add team…"
                    empty-label="Teams"
                    @change="(list) => (teamIds = list.map((t) => t.id))"
                />
                <MemberPicker
                    v-if="teamIds.length > 0"
                    :selected="assignees"
                    :members="lookups?.members ?? []"
                    multiple
                    placeholder="Assign…"
                    empty-label="Assignees"
                    @change="(users) => (assigneeIds = users.map((u) => u.id))"
                />
                <EntityPicker
                    :selected="
                        product
                            ? [
                                  {
                                      id: product.id,
                                      name: product.name,
                                      color: product.color,
                                  },
                              ]
                            : []
                    "
                    :options="
                        (lookups?.products ?? []).map((p) => ({
                            id: p.id,
                            name: p.name,
                            color: p.color,
                            hint: p.key,
                        }))
                    "
                    :icon="Box"
                    placeholder="Product…"
                    empty-label="Product"
                    @change="(list) => (productId = list[0]?.id ?? null)"
                />
                <EntityPicker
                    v-if="modules.length > 0"
                    :selected="
                        selectedModules.map((m) => ({
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
                    empty-label="Modules"
                    @change="(list) => (moduleIds = list.map((m) => m.id))"
                />
                <EntityPicker
                    :selected="
                        project
                            ? [
                                  {
                                      id: project.id,
                                      name: project.name,
                                      color: project.color,
                                  },
                              ]
                            : []
                    "
                    :options="projectOptions"
                    :icon="FolderKanban"
                    placeholder="Project…"
                    empty-label="Project"
                    @change="(list) => (projectId = list[0]?.id ?? null)"
                />
                <EntityPicker
                    v-if="milestoneOptions.length > 0"
                    :selected="
                        milestoneOptions.filter((m) => m.id === milestoneId)
                    "
                    :options="milestoneOptions"
                    :icon="Users"
                    placeholder="Milestone…"
                    empty-label="Milestone"
                    @change="(list) => (milestoneId = list[0]?.id ?? null)"
                />
                <LabelPicker
                    :selected="labels"
                    :available="lookups?.labels ?? []"
                    :product-id="productId"
                    :can-create="false"
                    @toggle="
                        (label, attached) =>
                            label &&
                            (labelIds = attached
                                ? [...labelIds, label.id]
                                : labelIds.filter((id) => id !== label.id))
                    "
                />
                <DatePicker :value="dueDate" @change="(v) => (dueDate = v)" />
                <EstimatePicker
                    :value="estimate"
                    @change="(v) => (estimate = v)"
                />
            </div>

            <div
                class="flex items-center justify-between gap-2 border-t bg-surface px-4 py-2.5"
            >
                <label
                    class="flex items-center gap-2 text-[12px] text-muted-foreground"
                >
                    <Switch v-model="createMore" class="scale-75" />
                    Create more
                </label>
                <div class="flex items-center gap-2">
                    <Button variant="ghost" size="sm" @click="open = false"
                        >Cancel</Button
                    >
                    <Button
                        size="sm"
                        class="gap-2"
                        :disabled="!canSubmit"
                        @click="submit"
                    >
                        Create issue
                        <kbd
                            class="rounded border border-primary-foreground/30 px-1 text-[10px] opacity-80"
                            >⌘↵</kbd
                        >
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
