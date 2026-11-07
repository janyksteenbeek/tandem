<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import SectionLabel from '@/components/projects/detail/SectionLabel.vue';
import WorkstreamCard from '@/components/projects/detail/WorkstreamCard.vue';
import { TeamPicker } from '@/components/projects/pickers';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import { injectIssueActions } from '@/composables/projects/useIssueActions';
import type { Issue, IssueLookups } from '@/types';

/**
 * Multiple teams on one issue, each with its own status. A single team stays a compact row so
 * simple issues do not look complicated.
 */
const props = defineProps<{ issue: Issue; lookups: IssueLookups }>();

const actions = injectIssueActions();

const existing = computed(() => props.issue.workstreams.map((w) => w.team.id));
const canEdit = (teamId: number) =>
    props.issue.can.update || props.lookups.team_ids.includes(teamId);
const summary = computed(() => {
    const r = props.issue.readiness;

    if (r.required === 0) {
        return r.completed === r.total
            ? 'All done'
            : `${r.completed} of ${r.total} done`;
    }

    return `${r.required_completed + r.required_canceled} of ${r.required} required ${r.required === 1 ? 'team' : 'teams'} done`;
});
const progress = computed(() =>
    props.issue.readiness.total > 0
        ? props.issue.readiness.completed / props.issue.readiness.total
        : 0,
);
</script>

<template>
    <section>
        <template v-if="issue.workstreams.length <= 1">
            <SectionLabel label="Team">
                <template #actions>
                    <TeamPicker
                        v-if="issue.can.manage_teams"
                        :selected="[]"
                        :options="lookups.teams"
                        :exclude="existing"
                        placeholder="Add a team…"
                        @change="
                            (teams) =>
                                teams[0] && actions.addTeam(issue, teams[0].id)
                        "
                    >
                        <template #trigger>
                            <button
                                type="button"
                                class="flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-muted-foreground hover:bg-accent hover:text-foreground"
                            >
                                <Plus class="size-3" /> Add team
                            </button>
                        </template>
                    </TeamPicker>
                </template>
            </SectionLabel>
            <WorkstreamCard
                v-if="issue.workstreams[0]"
                :issue="issue"
                :workstream="issue.workstreams[0]"
                :lookups="lookups"
                compact
                :editable="canEdit(issue.workstreams[0].team.id)"
            />
            <p
                v-else
                class="rounded-lg border border-dashed px-3 py-2.5 text-[12.5px] text-muted-foreground"
            >
                No team is working on this yet.
            </p>
        </template>

        <template v-else>
            <SectionLabel label="Teams">
                <template #meta>
                    <span
                        class="flex items-center gap-1.5 font-normal normal-case"
                    >
                        <ProgressRing
                            :value="progress"
                            :size="14"
                            :stroke="2"
                        />
                        {{ summary }}
                    </span>
                </template>
                <template #actions>
                    <TeamPicker
                        v-if="issue.can.manage_teams"
                        :selected="[]"
                        :options="lookups.teams"
                        :exclude="existing"
                        placeholder="Add a team…"
                        @change="
                            (teams) =>
                                teams[0] && actions.addTeam(issue, teams[0].id)
                        "
                    >
                        <template #trigger>
                            <button
                                type="button"
                                class="flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-muted-foreground hover:bg-accent hover:text-foreground"
                            >
                                <Plus class="size-3" /> Add team
                            </button>
                        </template>
                    </TeamPicker>
                </template>
            </SectionLabel>
            <div class="grid gap-2 md:grid-cols-2">
                <WorkstreamCard
                    v-for="workstream in issue.workstreams"
                    :key="workstream.uuid"
                    :issue="issue"
                    :workstream="workstream"
                    :lookups="lookups"
                    :editable="canEdit(workstream.team.id)"
                />
            </div>
        </template>
    </section>
</template>
