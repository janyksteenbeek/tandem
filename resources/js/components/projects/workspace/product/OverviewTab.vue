<script lang="ts">
import type { IssueListItem, ProjectSummary } from '@/types';

export type ProductOverview = {
    counts: {
        open: number;
        started: number;
        blocked: number;
        completed_30d: number;
    };
    by_module: {
        id: number;
        slug: string;
        name: string;
        color: string | null;
        open: number;
        parent_id: number | null;
    }[];
    projects: {
        projects: number;
        active: number;
        total: number;
        completed: number;
        percent: number;
    };
    active_projects: ProjectSummary[];
    recent_issues: IssueListItem[];
    upcoming_releases: {
        uuid: string;
        name: string;
        version: string | null;
        planned_at: string | null;
        status: string;
        issues_count: number;
        completed_issues_count: number;
    }[];
};
</script>

<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import CompactIssueRow from '@/components/projects/workspace/CompactIssueRow.vue';
import OverviewSection from '@/components/projects/workspace/OverviewSection.vue';
import StatPill from '@/components/projects/workspace/StatPill.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { cn } from '@/lib/utils';
import type { Product } from '@/types';

const props = defineProps<{
    product: Product;
    overview: ProductOverview | null | undefined;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(() => `/${slug.value}/products/${props.product.slug}`);
const maxModule = computed(() =>
    Math.max(1, ...(props.overview?.by_module ?? []).map((m) => m.open)),
);
</script>

<template>
    <div v-if="!overview" class="grid gap-8 lg:grid-cols-[1fr_18rem]">
        <div class="space-y-3">
            <div
                v-for="i in 6"
                :key="i"
                class="h-4 shimmer rounded"
                :style="{ width: `${40 + ((i * 23) % 50)}%` }"
            />
        </div>
        <div class="space-y-3">
            <div v-for="i in 4" :key="i" class="h-4 shimmer rounded" />
        </div>
    </div>
    <div v-else class="grid gap-8 lg:grid-cols-[1fr_18rem]">
        <div class="min-w-0 space-y-7">
            <div>
                <p
                    v-if="product.description"
                    class="max-w-prose text-[13.5px] leading-6 text-foreground/90"
                >
                    {{ product.description }}
                </p>
                <p v-else class="text-[13px] text-muted-foreground">
                    No description yet.
                    <Link
                        v-if="product.can.update"
                        :href="`${base}/settings`"
                        class="text-brand hover:underline"
                        >Add one</Link
                    >
                </p>
                <StatPill
                    class="mt-3"
                    :items="[
                        { label: 'open', value: overview.counts.open },
                        {
                            label: 'in progress',
                            value: overview.counts.started,
                            tone: 'warning',
                        },
                        {
                            label: 'blocked',
                            value: overview.counts.blocked,
                            tone:
                                overview.counts.blocked > 0
                                    ? 'destructive'
                                    : 'muted',
                        },
                        {
                            label: 'completed in 30 days',
                            value: overview.counts.completed_30d,
                            tone: 'success',
                        },
                    ]"
                />
            </div>

            <OverviewSection label="Open issues by module">
                <template #action
                    ><Link
                        :href="`${base}/modules`"
                        class="text-[11px] text-muted-foreground hover:text-foreground"
                        >Manage</Link
                    ></template
                >
                <p
                    v-if="overview.by_module.length === 0"
                    class="text-[12.5px] text-muted-foreground"
                >
                    No modules yet.
                </p>
                <ul v-else class="space-y-1">
                    <li v-for="module in overview.by_module" :key="module.id">
                        <Link
                            :href="`${base}/modules/${module.slug}`"
                            :class="
                                cn(
                                    'flex h-7 items-center gap-2 rounded-md px-2 text-[12.5px] hover:bg-accent',
                                    module.parent_id && 'pl-6',
                                )
                            "
                        >
                            <InboxDot
                                :color="module.color ?? 'slate'"
                                size="sm"
                            />
                            <span class="w-40 truncate">{{ module.name }}</span>
                            <span
                                class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                                ><span
                                    class="block h-full rounded-full bg-brand/70"
                                    :style="{
                                        width: `${(module.open / maxModule) * 100}%`,
                                    }"
                            /></span>
                            <span
                                class="w-8 text-right text-[11px] text-muted-foreground tabular-nums"
                                >{{ module.open }}</span
                            >
                        </Link>
                    </li>
                </ul>
            </OverviewSection>

            <OverviewSection label="Recent issues">
                <template #action
                    ><Link
                        :href="`${base}/issues`"
                        class="text-[11px] text-muted-foreground hover:text-foreground"
                        >All issues</Link
                    ></template
                >
                <p
                    v-if="overview.recent_issues.length === 0"
                    class="text-[12.5px] text-muted-foreground"
                >
                    No issues yet.
                </p>
                <ul v-else class="space-y-px">
                    <li
                        v-for="issue in overview.recent_issues"
                        :key="issue.uuid"
                    >
                        <CompactIssueRow :issue="issue" />
                    </li>
                </ul>
            </OverviewSection>
        </div>

        <aside class="space-y-6">
            <OverviewSection label="Owner">
                <div
                    v-if="product.owner"
                    class="flex items-center gap-2 text-[13px]"
                >
                    <UserAvatar
                        :name="product.owner.name"
                        :email="product.owner.email"
                        :src="product.owner.avatar"
                        :initials="product.owner.initials"
                        size="sm"
                    />
                    {{ product.owner.name }}
                </div>
                <p v-else class="text-[12.5px] text-muted-foreground">
                    Nobody yet.
                </p>
            </OverviewSection>
            <OverviewSection label="Teams">
                <ul v-if="product.teams.length > 0" class="space-y-1">
                    <li v-for="team in product.teams" :key="team.id">
                        <Link
                            :href="`/${slug}/teams/${team.slug}`"
                            class="flex items-center gap-2 text-[13px] hover:underline"
                            ><InboxDot :color="team.color" size="sm" />
                            {{ team.name }}</Link
                        >
                    </li>
                </ul>
                <p v-else class="text-[12.5px] text-muted-foreground">
                    No teams assigned.
                </p>
            </OverviewSection>
            <OverviewSection
                label="Active projects"
                :hint="
                    overview.projects.active
                        ? `${overview.projects.percent}% done`
                        : null
                "
            >
                <ul
                    v-if="overview.active_projects.length > 0"
                    class="space-y-1.5"
                >
                    <li
                        v-for="project in overview.active_projects"
                        :key="project.uuid"
                    >
                        <Link
                            :href="`/${slug}/projects/${project.slug}`"
                            class="flex items-center gap-2 text-[13px] hover:underline"
                        >
                            <ProgressRing
                                :value="project.progress"
                                :size="16"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                project.name
                            }}</span>
                            <span
                                v-if="project.target_date"
                                class="text-[11px] text-muted-foreground"
                                >{{ project.target_date }}</span
                            >
                        </Link>
                    </li>
                </ul>
                <p v-else class="text-[12.5px] text-muted-foreground">
                    Nothing in flight.
                </p>
            </OverviewSection>
            <OverviewSection label="Upcoming releases">
                <ul
                    v-if="overview.upcoming_releases.length > 0"
                    class="space-y-1.5"
                >
                    <li
                        v-for="release in overview.upcoming_releases"
                        :key="release.uuid"
                        class="flex items-center gap-2 text-[13px]"
                    >
                        <ProgressRing
                            :value="
                                release.issues_count
                                    ? release.completed_issues_count /
                                      release.issues_count
                                    : 0
                            "
                            :size="16"
                            tone="success"
                        />
                        <span class="min-w-0 flex-1 truncate"
                            >{{ release.name
                            }}<span
                                v-if="release.version"
                                class="ml-1 font-mono text-[11px] text-muted-foreground"
                                >{{ release.version }}</span
                            ></span
                        >
                        <span
                            v-if="release.planned_at"
                            class="text-[11px] text-muted-foreground"
                            >{{ release.planned_at }}</span
                        >
                    </li>
                </ul>
                <p v-else class="text-[12.5px] text-muted-foreground">
                    No releases planned.
                </p>
            </OverviewSection>
            <OverviewSection v-if="product.links.length > 0" label="Links">
                <ul class="space-y-1">
                    <li v-for="link in product.links" :key="link.id">
                        <a
                            :href="link.url"
                            target="_blank"
                            rel="noopener"
                            class="text-[13px] text-brand hover:underline"
                            >{{ link.title }}</a
                        >
                    </li>
                </ul>
            </OverviewSection>
        </aside>
    </div>
</template>
