<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Link2, Plus } from '@lucide/vue';
import { computed, inject, ref } from 'vue';
import LinkIssueDialog from '@/components/projects/dialogs/LinkIssueDialog.vue';
import SectionLabel from '@/components/projects/detail/SectionLabel.vue';
import AvatarStack from '@/components/projects/shared/AvatarStack.vue';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import { api, reportError } from '@/lib/http';
import type { CreateIssuePrefill, Issue, IssueListItem } from '@/types';

const props = defineProps<{ issue: Issue }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const openCreateIssue = inject<(prefill?: CreateIssuePrefill) => void>(
    'openCreateIssue',
    () => {},
);

const linkDialog = ref(false);
const done = computed(
    () =>
        props.issue.sub_issues.filter(
            (i) =>
                i.status_category === 'completed' ||
                i.status_category === 'canceled',
        ).length,
);

const create = () =>
    openCreateIssue({
        parent_identifier: props.issue.identifier,
        product_id: props.issue.product?.id ?? null,
        project_id: props.issue.project?.id ?? null,
        team_ids: props.issue.teams.map((t) => t.id),
    });

const attach = async (picked: IssueListItem) => {
    try {
        await api.post(`/${slug.value}/issues/${picked.identifier}/parent`, {
            parent_identifier: props.issue.identifier,
        });
        router.reload({ only: ['issue'] });
    } catch (error) {
        reportError(error);
    }
};
</script>

<template>
    <section v-if="issue.sub_issues.length > 0 || issue.can.update">
        <SectionLabel label="Sub-issues">
            <template #meta>
                <span
                    v-if="issue.sub_issues.length"
                    class="flex items-center gap-1.5 font-normal normal-case"
                >
                    <ProgressRing
                        :value="
                            issue.sub_issues.length
                                ? done / issue.sub_issues.length
                                : 0
                        "
                        :size="14"
                        :stroke="2"
                    />
                    {{ done }}/{{ issue.sub_issues.length }}
                </span>
            </template>
            <template #actions>
                <button
                    v-if="issue.can.update"
                    type="button"
                    class="flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click="linkDialog = true"
                >
                    <Link2 class="size-3" /> Add existing
                </button>
                <button
                    type="button"
                    class="flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click="create"
                >
                    <Plus class="size-3" /> New
                </button>
            </template>
        </SectionLabel>
        <ul v-if="issue.sub_issues.length" class="divide-y rounded-lg border">
            <li v-for="sub in issue.sub_issues" :key="sub.uuid">
                <Link
                    :href="`/${slug}/issues/${sub.identifier}`"
                    class="flex h-9 items-center gap-2.5 px-3 text-[13px] hover:bg-accent/60"
                >
                    <StatusIcon :status="sub.status" :size="14" />
                    <IssueIdentifier :identifier="sub.identifier" />
                    <span class="min-w-0 flex-1 truncate">{{ sub.title }}</span>
                    <AvatarStack
                        v-if="sub.assignees.length"
                        :users="sub.assignees"
                        :max="2"
                    />
                </Link>
            </li>
        </ul>
        <button
            v-else
            type="button"
            class="w-full rounded-lg border border-dashed px-3 py-2.5 text-left text-[12.5px] text-muted-foreground hover:bg-accent/40"
            @click="create"
        >
            Break this down into sub-issues…
        </button>
        <LinkIssueDialog
            v-model:open="linkDialog"
            mode="child"
            :exclude="issue.identifier"
            @pick="attach"
        />
    </section>
</template>
