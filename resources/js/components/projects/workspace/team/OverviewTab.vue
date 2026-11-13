<script lang="ts">
import type { IssueListItem } from '@/types';

export type TeamOverview = {
    counts: {
        open: number;
        started: number;
        blocked: number;
        unassigned: number;
        completed_30d: number;
    };
    by_status: Record<string, number>;
    blocked: IssueListItem[];
    recent: IssueListItem[];
};
</script>

<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import CompactIssueRow from '@/components/projects/workspace/CompactIssueRow.vue';
import OverviewSection from '@/components/projects/workspace/OverviewSection.vue';
import StatPill from '@/components/projects/workspace/StatPill.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { CATEGORY_ORDER } from '@/lib/projects/meta';
import type { Team, Workflow } from '@/types';

const props = defineProps<{
    team: Team;
    workflow: Workflow;
    overview: TeamOverview | null | undefined;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const base = computed(() => `/${slug.value}/teams/${props.team.slug}`);

const statuses = computed(() =>
    [...props.workflow.statuses]
        .filter((s) => s.category !== 'completed' && s.category !== 'canceled')
        .sort(
            (a, b) =>
                CATEGORY_ORDER.indexOf(a.category) -
                    CATEGORY_ORDER.indexOf(b.category) ||
                a.position - b.position,
        )
        .map((s) => ({
            status: s,
            count: props.overview?.by_status[String(s.id)] ?? 0,
        })),
);
const maxStatus = computed(() =>
    Math.max(1, ...statuses.value.map((s) => s.count)),
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
                    v-if="team.description"
                    class="max-w-prose text-[13.5px] leading-6 text-foreground/90"
                >
                    {{ team.description }}
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
                            label: 'unassigned',
                            value: overview.counts.unassigned,
                        },
                        {
                            label: 'done in 30 days',
                            value: overview.counts.completed_30d,
                            tone: 'success',
                        },
                    ]"
                />
            </div>

            <OverviewSection label="Open work by status">
                <template #action
                    ><Link
                        :href="`${base}/board`"
                        class="text-[11px] text-muted-foreground hover:text-foreground"
                        >Board</Link
                    ></template
                >
                <ul class="space-y-1">
                    <li
                        v-for="row in statuses"
                        :key="row.status.id"
                        class="flex h-7 items-center gap-2 text-[12.5px]"
                    >
                        <StatusIcon :status="row.status" :size="13" />
                        <span class="w-36 truncate">{{ row.status.name }}</span>
                        <span
                            class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                            ><span
                                class="block h-full rounded-full bg-brand/70"
                                :style="{
                                    width: `${(row.count / maxStatus) * 100}%`,
                                }"
                        /></span>
                        <span
                            class="w-8 text-right text-[11px] text-muted-foreground tabular-nums"
                            >{{ row.count }}</span
                        >
                    </li>
                </ul>
            </OverviewSection>

            <OverviewSection
                v-if="overview.blocked.length > 0"
                label="Blocked for this team"
            >
                <ul class="space-y-px">
                    <li v-for="issue in overview.blocked" :key="issue.uuid">
                        <CompactIssueRow
                            :issue="issue"
                            :focus-team-id="team.id"
                        />
                    </li>
                </ul>
            </OverviewSection>

            <OverviewSection label="Recent">
                <template #action
                    ><Link
                        :href="`${base}/issues`"
                        class="text-[11px] text-muted-foreground hover:text-foreground"
                        >All issues</Link
                    ></template
                >
                <p
                    v-if="overview.recent.length === 0"
                    class="text-[12.5px] text-muted-foreground"
                >
                    The team has no work yet.
                </p>
                <ul v-else class="space-y-px">
                    <li v-for="issue in overview.recent" :key="issue.uuid">
                        <CompactIssueRow
                            :issue="issue"
                            :focus-team-id="team.id"
                        />
                    </li>
                </ul>
            </OverviewSection>
        </div>

        <aside class="space-y-6">
            <OverviewSection label="Active cycle">
                <Link
                    v-if="team.active_cycle"
                    :href="`${base}/cycles/${team.active_cycle.uuid}`"
                    class="block text-[13px] hover:underline"
                >
                    {{ team.active_cycle.name }}
                    <span class="block text-[11.5px] text-muted-foreground"
                        >{{ team.active_cycle.starts_at }} →
                        {{ team.active_cycle.ends_at }} ·
                        {{
                            Math.round(team.active_cycle.progress * 100)
                        }}%</span
                    >
                </Link>
                <p v-else class="text-[12.5px] text-muted-foreground">
                    {{
                        team.cycle_settings.enabled
                            ? 'No cycle running.'
                            : 'Cycles are off for this team.'
                    }}
                </p>
            </OverviewSection>
            <OverviewSection
                label="Members"
                :hint="String(team.members.length)"
            >
                <ul class="space-y-1.5">
                    <li
                        v-for="member in team.members"
                        :key="member.id"
                        class="flex items-center gap-2 text-[13px]"
                    >
                        <UserAvatar
                            :name="member.name"
                            :email="member.email"
                            :src="member.avatar"
                            :initials="member.initials"
                            size="sm"
                        />
                        <span class="min-w-0 flex-1 truncate">{{
                            member.name
                        }}</span>
                        <span
                            v-if="member.role === 'lead'"
                            class="rounded-full bg-brand-soft px-1.5 text-[10px] font-medium text-brand-soft-foreground"
                            >Lead</span
                        >
                    </li>
                </ul>
            </OverviewSection>
        </aside>
    </div>
</template>
