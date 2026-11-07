<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import IssueDetail from '@/components/projects/detail/IssueDetail.vue';
import type {
    Activity,
    Issue,
    IssueComment,
    IssueListItem,
    IssueLookups,
    LinkedConversations,
} from '@/types';

const props = defineProps<{
    issue: Issue;
    lookups: IssueLookups;
    activity?: Activity[] | null;
    linkedConversations?: LinkedConversations | null;
    from?: string | null;
}>();

const detail = ref<InstanceType<typeof IssueDetail> | null>(null);
const activity = ref<Activity[] | null | undefined>(props.activity);

watch(
    () => props.activity,
    (value) => (activity.value = value),
);

const title = computed(
    () => `${props.issue.identifier} · ${props.issue.title}`,
);

const reloadIssue = useDebounceFn(
    () => router.reload({ only: ['issue'] }),
    300,
);

useEcho<{
    reason: string;
    issue: IssueListItem | { uuid: string; identifier: string };
}>(`issue.${props.issue.uuid}`, '.issue.updated', (payload) => {
    if (payload.reason === 'deleted') {
        return;
    }

    if ('title' in payload.issue) {
        detail.value?.mergeListItem(payload.issue as Partial<Issue>);
    }

    if (
        [
            'workstream',
            'assignees',
            'relations',
            'dependencies',
            'blockers',
            'links',
            'shared_content',
            'attachments',
            'parent',
            'completion',
        ].includes(payload.reason)
    ) {
        void reloadIssue();
    }
});

useEcho<{ activity: Activity }>(
    `issue.${props.issue.uuid}`,
    '.activity.added',
    (payload) => {
        if (
            activity.value &&
            !activity.value.some((a) => a.id === payload.activity.id)
        ) {
            activity.value = [...activity.value, payload.activity];
        }
    },
);

useEcho<{ comment: IssueComment }>(
    `issue.${props.issue.uuid}`,
    '.comment.created',
    (payload) => detail.value?.receiveComment(payload.comment, 'created'),
);
useEcho<{ comment: IssueComment }>(
    `issue.${props.issue.uuid}`,
    '.comment.updated',
    (payload) => detail.value?.receiveComment(payload.comment, 'updated'),
);
useEcho<{ comment: IssueComment }>(
    `issue.${props.issue.uuid}`,
    '.comment.deleted',
    (payload) => detail.value?.receiveComment(payload.comment, 'deleted'),
);

useEcho(
    `issue.${props.issue.uuid}`,
    '.workstream.updated',
    () => void reloadIssue(),
);
</script>

<template>
    <Head :title="title" />
    <div class="h-full min-h-0 [view-transition-name:issue-detail]">
        <IssueDetail
            ref="detail"
            :issue="issue"
            :lookups="lookups"
            :activity="activity"
            :linked-conversations="linkedConversations"
            :from="from"
        />
    </div>
</template>
