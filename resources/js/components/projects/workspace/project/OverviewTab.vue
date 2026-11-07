<script lang="ts">
import type {
    Activity,
    IssueListItem,
    Milestone,
    ProjectProgressDetail,
} from '@/types';

export type ProjectOverview = {
    progress: ProjectProgressDetail;
    milestones: Milestone[];
    blockers: IssueListItem[];
    recent_issues: IssueListItem[];
    recent_activity: Activity[];
};
</script>

<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import RichTextEditor from '@/components/projects/editor/RichTextEditor.vue';
import ActivityFeed from '@/components/projects/workspace/ActivityFeed.vue';
import CompactIssueRow from '@/components/projects/workspace/CompactIssueRow.vue';
import OverviewSection from '@/components/projects/workspace/OverviewSection.vue';
import SegmentedProgress from '@/components/projects/workspace/SegmentedProgress.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { api, reportError } from '@/lib/http';
import { PROJECT_HEALTH_META, PROJECT_STATUS_META } from '@/lib/projects/meta';
import { cn } from '@/lib/utils';
import type { Project } from '@/types';

const props = defineProps<{
    project: Project;
    overview: ProjectOverview | null | undefined;
}>();
const emit = defineEmits<{ updated: [project: Project] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(() => `/${slug.value}/projects/${props.project.slug}`);

const description = ref(props.project.description_html ?? '');
watch(
    () => props.project.description_html,
    (v) => (description.value = v ?? ''),
);

const saveDescription = async () => {
    if (
        (description.value || null) === (props.project.description_html || null)
    ) {
        return;
    }

    try {
        const data = await api.patch<{ project: Project }>(base.value, {
            description_html: description.value || null,
        });
        emit('updated', data.project);
    } catch (error) {
        reportError(error);
    }
};

const maxTeam = computed(() =>
    Math.max(
        1,
        ...(props.overview?.progress.by_team ?? []).map((t) => t.workstreams),
    ),
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
                <RichTextEditor
                    v-if="project.can.update"
                    v-model="description"
                    placeholder="What is this project trying to achieve?"
                    :toolbar="false"
                    @blur="saveDescription"
                />
                <div
                    v-else-if="project.description_html"
                    class="prose-tandem text-[14px]"
                    v-html="project.description_html"
                />
                <p v-else class="text-[13px] text-muted-foreground">
                    No description yet.
                </p>
            </div>

            <OverviewSection
                label="Progress"
                :hint="`${overview.progress.percent}% · ${overview.progress.completed} of ${overview.progress.total - overview.progress.canceled} issues`"
            >
                <SegmentedProgress
                    :completed="overview.progress.completed"
                    :started="overview.progress.started"
                    :unstarted="overview.progress.unstarted"
                    :backlog="overview.progress.backlog"
                    :canceled="overview.progress.canceled"
                />
                <ul
                    v-if="overview.progress.by_team.length > 0"
                    class="mt-3 space-y-1"
                >
                    <li
                        v-for="team in overview.progress.by_team"
                        :key="team.team_id"
                        class="flex h-6 items-center gap-2 text-[12.5px]"
                    >
                        <span class="w-32 truncate">{{ team.team }}</span>
                        <span
                            class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                        >
                            <span
                                class="block h-full rounded-full bg-status-completed"
                                :style="{
                                    width: `${(team.completed / maxTeam) * 100}%`,
                                }"
                            />
                        </span>
                        <span
                            class="w-16 text-right text-[11px] text-muted-foreground tabular-nums"
                            >{{ team.completed }}/{{ team.workstreams }}</span
                        >
                    </li>
                </ul>
            </OverviewSection>

            <OverviewSection
                v-if="overview.blockers.length > 0"
                label="Blocked"
            >
                <ul class="space-y-px">
                    <li v-for="issue in overview.blockers" :key="issue.uuid">
                        <CompactIssueRow :issue="issue" />
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

            <OverviewSection label="Recent activity">
                <template #action
                    ><Link
                        :href="`${base}/activity`"
                        class="text-[11px] text-muted-foreground hover:text-foreground"
                        >All activity</Link
                    ></template
                >
                <ActivityFeed
                    :activity="overview.recent_activity"
                    :limit="10"
                />
            </OverviewSection>
        </div>

        <aside class="space-y-6">
            <OverviewSection label="Status">
                <div class="space-y-1.5 text-[13px]">
                    <p class="flex items-center gap-1.5">
                        <span
                            :class="
                                cn(
                                    'size-1.5 rounded-full',
                                    PROJECT_STATUS_META[project.status].dot,
                                )
                            "
                        />{{ PROJECT_STATUS_META[project.status].label }}
                    </p>
                    <p :class="PROJECT_HEALTH_META[project.health].className">
                        {{ PROJECT_HEALTH_META[project.health].label }}
                    </p>
                    <p v-if="project.target_date" class="text-muted-foreground">
                        Target {{ project.target_date }}
                    </p>
                    <p
                        v-if="project.visibility === 'private'"
                        class="text-muted-foreground"
                    >
                        Private project
                    </p>
                </div>
            </OverviewSection>
            <OverviewSection label="Lead">
                <div
                    v-if="project.lead"
                    class="flex items-center gap-2 text-[13px]"
                >
                    <UserAvatar
                        :name="project.lead.name"
                        :email="project.lead.email"
                        :src="project.lead.avatar"
                        :initials="project.lead.initials"
                        size="sm"
                    />{{ project.lead.name }}
                </div>
                <p v-else class="text-[12.5px] text-muted-foreground">
                    Nobody yet.
                </p>
            </OverviewSection>
            <OverviewSection label="Teams">
                <ul v-if="project.teams.length > 0" class="space-y-1">
                    <li v-for="team in project.teams" :key="team.id">
                        <Link
                            :href="`/${slug}/teams/${team.slug}`"
                            class="flex items-center gap-2 text-[13px] hover:underline"
                            ><InboxDot :color="team.color" size="sm" />{{
                                team.name
                            }}</Link
                        >
                    </li>
                </ul>
                <p v-else class="text-[12.5px] text-muted-foreground">
                    No teams yet.
                </p>
            </OverviewSection>
            <OverviewSection v-if="project.product" label="Product">
                <Link
                    :href="`/${slug}/products/${project.product.slug}`"
                    class="flex items-center gap-2 text-[13px] hover:underline"
                    ><InboxDot :color="project.product.color" size="sm" />{{
                        project.product.name
                    }}</Link
                >
                <p
                    v-if="project.modules.length > 0"
                    class="mt-1 text-[12px] text-muted-foreground"
                >
                    {{ project.modules.map((m) => m.name).join(', ') }}
                </p>
            </OverviewSection>
            <OverviewSection label="Milestones">
                <template #action
                    ><Link
                        :href="`${base}/milestones`"
                        class="text-[11px] text-muted-foreground hover:text-foreground"
                        >Manage</Link
                    ></template
                >
                <ul v-if="overview.milestones.length > 0" class="space-y-1.5">
                    <li
                        v-for="milestone in overview.milestones"
                        :key="milestone.uuid"
                        class="flex items-center gap-2 text-[13px]"
                    >
                        <ProgressRing
                            :value="milestone.progress"
                            :size="16"
                            :tone="
                                milestone.status === 'completed'
                                    ? 'success'
                                    : milestone.status === 'missed'
                                      ? 'warning'
                                      : 'brand'
                            "
                        />
                        <span class="min-w-0 flex-1 truncate">{{
                            milestone.name
                        }}</span>
                        <span
                            v-if="milestone.target_date"
                            class="text-[11px] text-muted-foreground"
                            >{{ milestone.target_date }}</span
                        >
                    </li>
                </ul>
                <p v-else class="text-[12.5px] text-muted-foreground">
                    No milestones.
                </p>
            </OverviewSection>
            <OverviewSection v-if="project.links.length > 0" label="Links">
                <ul class="space-y-1">
                    <li v-for="link in project.links" :key="link.id">
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
