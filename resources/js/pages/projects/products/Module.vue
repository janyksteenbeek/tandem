<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Layers, Plus } from '@lucide/vue';
import { computed, inject } from 'vue';
import WorkspaceHeader from '@/components/projects/workspace/WorkspaceHeader.vue';
import IssueWorkspaceBody from '@/components/projects/IssueWorkspaceBody.vue';
import CompactIssueRow from '@/components/projects/workspace/CompactIssueRow.vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import StatPill from '@/components/projects/workspace/StatPill.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { useIssueLookups } from '@/composables/projects/useIssueLookups';
import type {
    BoardColumn,
    CreateIssuePrefill,
    CursorPage,
    IssueDisplay,
    IssueFilters,
    IssueListItem,
    IssueLookups,
    ModuleSummary,
    Product,
    ProjectSummary,
    WorkflowStatus,
} from '@/types';

const props = defineProps<{
    product: Product;
    module: ModuleSummary;
    breadcrumbs: { slug: string; name: string }[];
    filters: IssueFilters;
    display: IssueDisplay;
    workflow: WorkflowStatus[];
    lookups: IssueLookups;
    issues: CursorPage<IssueListItem>;
    board?: BoardColumn[] | null;
    overview: {
        open: number;
        blocked: number;
        completed_30d: number;
        projects: ProjectSummary[];
        blockers: IssueListItem[];
        recently_completed: IssueListItem[];
    };
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const productUrl = computed(
    () => `/${slug.value}/products/${props.product.slug}`,
);
const base = computed(() => `${productUrl.value}/modules/${props.module.slug}`);
const { lookups } = useIssueLookups(() => props.lookups);
const openCreateIssue = inject<(prefill?: CreateIssuePrefill) => void>(
    'openCreateIssue',
    () => {},
);

const crumbs = computed(() => [
    { label: 'Products', href: `/${slug.value}/products` },
    { label: props.product.name, href: productUrl.value },
    { label: 'Modules', href: `${productUrl.value}/modules` },
    ...props.breadcrumbs.map((b) => ({
        label: b.name,
        href: `${productUrl.value}/modules/${b.slug}`,
    })),
    { label: props.module.name },
]);

const container = computed(() => ({
    type: 'module' as const,
    id: props.module.id,
    name: props.module.name,
    url: base.value,
}));
</script>

<template>
    <Head :title="`${module.name} · ${product.name}`" />

    <div class="flex h-full min-h-0 flex-col">
        <IssueWorkspaceBody
            :container="container"
            :filters="filters"
            :display="display"
            :issues="issues"
            :board="board"
            :workflow="workflow"
            :lookups="lookups"
            :channels="[`product.${product.uuid}`]"
            :base-url="base"
            empty-key="product"
        >
            <template #header>
                <WorkspaceHeader
                    :breadcrumbs="crumbs"
                    :title="module.name"
                    :subtitle="module.description"
                    :color="module.color ?? product.color"
                >
                    <template #meta>
                        <span
                            v-if="module.owner"
                            class="flex items-center gap-1.5 text-[12px] text-muted-foreground"
                            ><UserAvatar
                                :name="module.owner.name"
                                :email="module.owner.email"
                                :src="module.owner.avatar"
                                :initials="module.owner.initials"
                                size="xs"
                            />{{ module.owner.name }}</span
                        >
                        <span
                            v-if="module.teams?.length"
                            class="flex items-center -space-x-0.5"
                            ><InboxDot
                                v-for="team in module.teams"
                                :key="team.id"
                                :color="team.color"
                                size="sm"
                        /></span>
                        <Button
                            size="sm"
                            variant="outline"
                            class="h-8 gap-1.5"
                            @click="
                                openCreateIssue({
                                    product_id: product.id,
                                    module_ids: [module.id],
                                })
                            "
                            ><Plus class="size-3.5" /> New issue</Button
                        >
                    </template>
                    <div class="grid gap-x-8 gap-y-3 lg:grid-cols-[auto_1fr]">
                        <StatPill
                            :items="[
                                { label: 'open', value: overview.open },
                                {
                                    label: 'blocked',
                                    value: overview.blocked,
                                    tone:
                                        overview.blocked > 0
                                            ? 'destructive'
                                            : 'muted',
                                },
                                {
                                    label: 'done in 30 days',
                                    value: overview.completed_30d,
                                    tone: 'success',
                                },
                            ]"
                        />
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-muted-foreground"
                        >
                            <template v-if="overview.projects.length > 0">
                                <span class="font-medium text-foreground"
                                    >Projects:</span
                                >
                                <Link
                                    v-for="project in overview.projects.slice(
                                        0,
                                        4,
                                    )"
                                    :key="project.uuid"
                                    :href="`/${slug}/projects/${project.slug}`"
                                    class="flex items-center gap-1 hover:text-foreground"
                                    ><ProgressRing
                                        :value="project.progress"
                                        :size="12"
                                    />{{ project.name }}</Link
                                >
                            </template>
                            <template v-if="module.children?.length">
                                <span class="font-medium text-foreground"
                                    >Submodules:</span
                                >
                                <Link
                                    v-for="child in module.children"
                                    :key="child.id"
                                    :href="`${productUrl}/modules/${child.slug}`"
                                    class="flex items-center gap-1 hover:text-foreground"
                                    ><Layers class="size-3" />{{
                                        child.name
                                    }}</Link
                                >
                            </template>
                        </div>
                    </div>
                    <details
                        v-if="
                            overview.blockers.length > 0 ||
                            overview.recently_completed.length > 0
                        "
                        class="group/more mt-2"
                    >
                        <summary
                            class="cursor-pointer list-none text-[11.5px] text-muted-foreground select-none hover:text-foreground"
                        >
                            Blockers and recent completions
                        </summary>
                        <div class="mt-2 grid gap-4 md:grid-cols-2">
                            <div v-if="overview.blockers.length > 0">
                                <p
                                    class="mb-1 text-[11px] font-semibold tracking-wide text-destructive uppercase"
                                >
                                    Blocked
                                </p>
                                <ul class="space-y-px">
                                    <li
                                        v-for="issue in overview.blockers"
                                        :key="issue.uuid"
                                    >
                                        <CompactIssueRow :issue="issue" />
                                    </li>
                                </ul>
                            </div>
                            <div v-if="overview.recently_completed.length > 0">
                                <p
                                    class="mb-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Recently completed
                                </p>
                                <ul class="space-y-px">
                                    <li
                                        v-for="issue in overview.recently_completed"
                                        :key="issue.uuid"
                                    >
                                        <CompactIssueRow :issue="issue" />
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </details>
                </WorkspaceHeader>
            </template>
        </IssueWorkspaceBody>
    </div>
</template>
