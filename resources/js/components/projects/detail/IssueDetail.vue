<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { ChevronRight } from '@lucide/vue';
import DOMPurify from 'dompurify';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Collapse from '@/components/Collapse.vue';
import CommentThread from '@/components/projects/comments/CommentThread.vue';
import ActivityTimeline from '@/components/projects/detail/ActivityTimeline.vue';
import AttachmentList from '@/components/projects/detail/AttachmentList.vue';
import DependencyList from '@/components/projects/detail/DependencyList.vue';
import IssueBreadcrumbs from '@/components/projects/detail/IssueBreadcrumbs.vue';
import IssueHeader from '@/components/projects/detail/IssueHeader.vue';
import IssueProperties from '@/components/projects/detail/IssueProperties.vue';
import LinkedConversations from '@/components/projects/detail/LinkedConversations.vue';
import SharedContentList from '@/components/projects/detail/SharedContentList.vue';
import SubIssueList from '@/components/projects/detail/SubIssueList.vue';
import WorkstreamsSection from '@/components/projects/detail/WorkstreamsSection.vue';
import ConfirmDialog from '@/components/projects/dialogs/ConfirmDialog.vue';
import RichTextEditor from '@/components/projects/editor/RichTextEditor.vue';
import { EntityPicker } from '@/components/projects/pickers';
import {
    provideIssueActions,
    useIssueActions,
} from '@/composables/projects/useIssueActions';
import { useKeyboard } from '@/composables/useKeyboard';
import { PRIORITY } from '@/lib/keyboard';
import { issueStore } from '@/lib/projects/issueStore';
import { cn } from '@/lib/utils';
import { FolderKanban } from '@lucide/vue';
import type {
    Activity,
    Issue,
    IssueAttachment,
    IssueComment,
    IssueLookups,
    LinkedConversations as LinkedConversationsData,
    WorkflowStatus,
} from '@/types';

/**
 * The issue page body: breadcrumbs, title, description, teams, relations, files, mail,
 * activity and comments in the main column; every property in the aside.
 */
const props = defineProps<{
    issue: Issue;
    lookups: IssueLookups;
    activity?: Activity[] | null;
    linkedConversations?: LinkedConversationsData | null;
    from?: string | null;
}>();

const emit = defineEmits<{
    updated: [issue: Issue];
    removed: [uuid: string];
    linkConversation: [];
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const orgName = computed(() => page.props.currentOrganization?.name ?? '');

const local = ref<Issue>(props.issue);
const editingTitle = ref(false);
const editingDescription = ref(false);

watch(
    () => props.issue,
    (value) => {
        local.value = {
            ...value,
            title: editingTitle.value ? local.value.title : value.title,
            description_html: editingDescription.value
                ? local.value.description_html
                : value.description_html,
        };
    },
);

const actions = useIssueActions((issue) => {
    local.value = {
        ...issue,
        title: editingTitle.value ? local.value.title : issue.title,
        description_html: editingDescription.value
            ? local.value.description_html
            : issue.description_html,
    };
    emit('updated', local.value);
});

provideIssueActions(actions);

/** Store updates (optimistic patches, realtime) flow back into the local copy. */
issueStore.subscribe(({ uuid, issue }) => {
    if (uuid !== local.value.uuid || !issue) {
        return;
    }

    local.value = {
        ...local.value,
        ...issue,
        title: editingTitle.value ? local.value.title : issue.title,
        description_html:
            editingDescription.value || !('description_html' in issue)
                ? local.value.description_html
                : (issue as Issue).description_html,
    };
});

const statuses = computed<WorkflowStatus[]>(() => {
    const product = props.lookups.products.find(
        (p) => p.id === local.value.product?.id,
    );
    const workflowId =
        product?.workflow_id ?? props.lookups.default_workflow_id;

    return (
        props.lookups.workflows[workflowId]?.statuses ?? props.lookups.statuses
    );
});

// --- description autosave -------------------------------------------------------------------
const description = ref(local.value.description_html ?? '');
const saveState = ref<'idle' | 'saving' | 'saved'>('idle');

watch(
    () => local.value.description_html,
    (value) => {
        if (!editingDescription.value) {
            description.value = value ?? '';
        }
    },
);

const persistDescription = async () => {
    if (description.value === (local.value.description_html ?? '')) {
        return;
    }

    saveState.value = 'saving';
    const result = await actions.setDescription(local.value, description.value);
    saveState.value = result ? 'saved' : 'idle';
    setTimeout(() => (saveState.value = 'idle'), 1800);
};

const debouncedSave = useDebounceFn(persistDescription, 800);

watch(description, () => {
    if (editingDescription.value) {
        void debouncedSave();
    }
});

const safeDescription = computed(() =>
    DOMPurify.sanitize(local.value.description_html ?? '', {
        USE_PROFILES: { html: true },
    }),
);

// --- header actions ---------------------------------------------------------------------------
const header = ref<InstanceType<typeof IssueHeader> | null>(null);
const comments = ref<InstanceType<typeof CommentThread> | null>(null);
const deleteDialog = ref(false);
const moveDialog = ref(false);
const propertiesOpen = ref(false);

const destroy = async () => {
    if (await actions.destroy(local.value)) {
        deleteDialog.value = false;
        emit('removed', local.value.uuid);
        router.visit(props.from ?? `/${slug.value}/my-issues`);
    }
};

const projectOptions = computed(() =>
    props.lookups.projects
        .filter(
            (p) =>
                !local.value.product ||
                !p.product ||
                p.product.id === local.value.product.id,
        )
        .map((p) => ({ id: p.id, name: p.name, color: p.color })),
);

const copy = async (text: string, label: string) => {
    await navigator.clipboard.writeText(text);
    toast.success(`${label} copied`);
};

const back = () => {
    if (props.from) {
        router.visit(props.from);
    } else if (window.history.length > 1) {
        window.history.back();
    } else {
        router.visit(`/${slug.value}/my-issues`);
    }
};

const openPicker = (label: string) => {
    const button = document.querySelector<HTMLButtonElement>(
        `[data-issue-props] button[aria-label="${label}"]`,
    );
    button?.click();
};

useKeyboard(
    () => [
        {
            keys: 's',
            description: 'Change status',
            group: 'Issue',
            handler: () => openPicker('Status'),
        },
        {
            keys: 'p',
            description: 'Set priority',
            group: 'Issue',
            handler: () => openPicker('Priority'),
        },
        {
            keys: 'a',
            description: 'Assign',
            group: 'Issue',
            handler: () =>
                openPicker(
                    local.value.workstreams.length ? 'Assign…' : 'Owner…',
                ),
        },
        {
            keys: 'l',
            description: 'Labels',
            group: 'Issue',
            handler: () => openPicker('Labels'),
        },
        {
            keys: 'd',
            description: 'Due date',
            group: 'Issue',
            handler: () => openPicker('Due date'),
        },
        {
            keys: 'e',
            description: 'Edit title',
            group: 'Issue',
            handler: () => header.value?.editTitle(),
        },
        {
            keys: 'm',
            description: 'Comment',
            group: 'Issue',
            handler: () => comments.value?.focus(),
        },
        {
            keys: 'mod+shift+,',
            description: 'Copy issue ID',
            group: 'Issue',
            handler: () => void copy(local.value.identifier, 'ID'),
        },
        {
            keys: 'mod+.',
            description: 'Copy issue link',
            group: 'Issue',
            handler: () =>
                void copy(
                    `${window.location.origin}/${slug.value}/issues/${local.value.identifier}`,
                    'Link',
                ),
        },
        {
            keys: 'escape',
            description: 'Back',
            group: 'Issue',
            hidden: true,
            handler: back,
        },
    ],
    { scope: 'Issue', priority: PRIORITY.detail },
);

const setComments = (list: IssueComment[]) => {
    local.value = {
        ...local.value,
        comments: list,
        counts: { ...local.value.counts, comments: list.length },
    };
};

const setAttachments = (list: IssueAttachment[]) => {
    local.value = {
        ...local.value,
        attachments: list,
        counts: { ...local.value.counts, attachments: list.length },
    };
};

/** Used by the page for realtime comment/activity events. */
defineExpose({
    receiveComment: (
        comment: IssueComment,
        action: 'created' | 'updated' | 'deleted',
    ) => {
        if (action === 'deleted') {
            setComments(
                local.value.comments.filter((c) => c.uuid !== comment.uuid),
            );

            return;
        }

        const exists = local.value.comments.some(
            (c) => c.uuid === comment.uuid,
        );
        setComments(
            exists
                ? local.value.comments.map((c) =>
                      c.uuid === comment.uuid
                          ? { ...comment, client_id: c.client_id }
                          : c,
                  )
                : [...local.value.comments, comment],
        );
    },
    mergeListItem: (item: Partial<Issue>) => {
        local.value = {
            ...local.value,
            ...item,
            title: editingTitle.value
                ? local.value.title
                : (item.title ?? local.value.title),
            description_html: local.value.description_html,
            comments: local.value.comments,
            attachments: local.value.attachments,
        };
    },
    issue: local,
});
</script>

<template>
    <div class="flex h-full min-h-0 flex-col lg:flex-row">
        <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto">
            <div
                class="mx-auto w-full max-w-3xl px-5 pt-12 pb-10 sm:px-8 md:pt-5"
            >
                <IssueBreadcrumbs
                    :issue="local"
                    :org-slug="slug"
                    :org-name="orgName"
                />

                <div class="mt-3">
                    <IssueHeader
                        ref="header"
                        :issue="local"
                        :statuses="statuses"
                        @rename="(t) => actions.rename(local, t)"
                        @status="(s) => actions.setStatus(local, s)"
                        @priority="(p) => actions.setPriority(local, p)"
                        @follow="(f) => actions.follow(local, f)"
                        @move="moveDialog = true"
                        @delete="deleteDialog = true"
                        @editing="(a) => (editingTitle = a)"
                    />
                </div>

                <div class="mt-3 lg:hidden">
                    <button
                        type="button"
                        class="flex items-center gap-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                        :aria-expanded="propertiesOpen"
                        @click="propertiesOpen = !propertiesOpen"
                    >
                        <ChevronRight
                            :class="
                                cn(
                                    'size-3 transition-transform',
                                    propertiesOpen && 'rotate-90',
                                )
                            "
                        />
                        Properties
                    </button>
                    <Collapse :open="propertiesOpen">
                        <div
                            data-issue-props
                            class="mt-2 rounded-lg border bg-surface/60 px-3 py-2"
                        >
                            <IssueProperties
                                :issue="local"
                                :lookups="lookups"
                                :org-slug="slug"
                                stacked
                            />
                        </div>
                    </Collapse>
                </div>

                <div class="relative mt-4">
                    <RichTextEditor
                        v-if="local.can.update"
                        v-model="description"
                        placeholder="Add a description… Type @ to mention, / for formatting."
                        :members="lookups.members"
                        :upload-url="`/${slug}/issues/${local.identifier}/attachments`"
                        min-height="4rem"
                        @focus="editingDescription = true"
                        @blur="
                            () => {
                                editingDescription = false;
                                void persistDescription();
                            }
                        "
                    />
                    <div
                        v-else-if="local.description_html"
                        class="prose-tandem text-[14px]"
                        v-html="safeDescription"
                    />
                    <p v-else class="text-[13px] text-muted-foreground">
                        No description.
                    </p>
                    <span
                        v-if="saveState !== 'idle'"
                        class="absolute -top-5 right-0 text-[11px] text-muted-foreground transition-opacity"
                        >{{
                            saveState === 'saving' ? 'Saving…' : 'Saved'
                        }}</span
                    >
                </div>

                <div class="mt-8 space-y-8">
                    <WorkstreamsSection :issue="local" :lookups="lookups" />
                    <SubIssueList :issue="local" />
                    <DependencyList :issue="local" />
                    <AttachmentList :issue="local" @change="setAttachments" />
                    <SharedContentList :items="local.shared_content" />
                    <LinkedConversations
                        :data="linkedConversations"
                        :can-link="local.can.link_conversations"
                        @link="emit('linkConversation')"
                    />
                    <ActivityTimeline :activity="activity" />
                    <CommentThread
                        ref="comments"
                        :issue="local"
                        :comments="local.comments"
                        :members="lookups.members"
                        @change="setComments"
                    />
                </div>
            </div>
        </div>

        <aside
            data-issue-props
            class="hidden scrollbar-thin w-80 shrink-0 overflow-y-auto border-l bg-surface/60 lg:block"
        >
            <IssueProperties
                :issue="local"
                :lookups="lookups"
                :org-slug="slug"
            />
        </aside>

        <ConfirmDialog
            v-model:open="deleteDialog"
            :title="`Delete ${local.identifier}?`"
            description="The issue is archived and disappears from every list. An admin can restore it."
            confirm-label="Delete issue"
            destructive
            @confirm="destroy"
        />

        <ConfirmDialog
            v-model:open="moveDialog"
            title="Move to project"
            description="The identifier stays the same; the status is mapped when the destination uses another workflow."
            confirm-label="Done"
            @confirm="moveDialog = false"
        >
            <EntityPicker
                :selected="
                    local.project
                        ? [
                              {
                                  id: local.project.id,
                                  name: local.project.name,
                                  color: local.project.color,
                              },
                          ]
                        : []
                "
                :options="projectOptions"
                :icon="FolderKanban"
                placeholder="Project…"
                empty-label="No project"
                @change="
                    (list) =>
                        actions.move(
                            local,
                            local.product?.id ?? null,
                            list[0]?.id ?? null,
                        )
                "
            />
        </ConfirmDialog>
    </div>
</template>
