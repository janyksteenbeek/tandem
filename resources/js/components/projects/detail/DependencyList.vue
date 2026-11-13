<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Check, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import LinkIssueDialog from '@/components/projects/dialogs/LinkIssueDialog.vue';
import SectionLabel from '@/components/projects/detail/SectionLabel.vue';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import { injectIssueActions } from '@/composables/projects/useIssueActions';
import type { Issue, IssueListItem, IssueRelationKind } from '@/types';

const props = defineProps<{ issue: Issue }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const actions = injectIssueActions();
const dialog = ref(false);

const groups = computed(() =>
    (
        [
            ['blocked_by', 'Blocked by'],
            ['blocks', 'Blocks'],
            ['related', 'Related'],
            ['duplicate', 'Duplicates'],
        ] as [IssueRelationKind, string][]
    )
        .map(([kind, label]) => ({
            kind,
            label,
            rows: props.issue.relations.filter((r) => r.kind === kind),
        }))
        .filter((g) => g.rows.length > 0),
);

const dependencies = computed(() => props.issue.workstream_dependencies);
const empty = computed(
    () => groups.value.length === 0 && dependencies.value.length === 0,
);

const pick = (picked: IssueListItem, kind?: IssueRelationKind) =>
    void actions.addRelation(props.issue, picked.identifier, kind ?? 'related');

const removeDependency = (row: Issue['workstream_dependencies'][number]) => {
    const workstream = props.issue.workstreams.find(
        (w) => w.uuid === row.workstream_uuid,
    );

    if (workstream && row.id !== null) {
        void actions.removeDependency(props.issue, workstream, row.id);
    }
};
</script>

<template>
    <section v-if="!empty || issue.can.update">
        <SectionLabel label="Dependencies">
            <template #actions>
                <button
                    v-if="issue.can.update"
                    type="button"
                    class="flex h-6 items-center gap-1 rounded-md px-1.5 text-[11.5px] text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click="dialog = true"
                >
                    <Plus class="size-3" /> Add
                </button>
            </template>
        </SectionLabel>

        <p
            v-if="empty"
            class="rounded-lg border border-dashed px-3 py-2.5 text-[12.5px] text-muted-foreground"
        >
            Nothing blocks or relates to this issue.
        </p>

        <div v-else class="space-y-3">
            <div v-for="group in groups" :key="group.kind">
                <p class="mb-1 text-[11px] text-muted-foreground">
                    {{ group.label }}
                </p>
                <ul class="divide-y rounded-lg border">
                    <li
                        v-for="row in group.rows"
                        :key="row.id"
                        class="group/dep flex h-9 items-center gap-2.5 px-3 text-[13px]"
                    >
                        <StatusIcon
                            :category="row.issue.status_category ?? 'unstarted'"
                            :color="row.issue.status?.color"
                            :size="14"
                        />
                        <Link
                            :href="`/${slug}/issues/${row.issue.identifier}`"
                            class="flex min-w-0 flex-1 items-center gap-2 hover:underline"
                        >
                            <IssueIdentifier
                                :identifier="row.issue.identifier"
                            />
                            <span class="truncate">{{ row.issue.title }}</span>
                        </Link>
                        <Check
                            v-if="
                                row.issue.status_category === 'completed' ||
                                row.issue.status_category === 'canceled'
                            "
                            class="size-3.5 text-success"
                        />
                        <button
                            v-if="issue.can.update"
                            type="button"
                            class="rounded p-0.5 text-muted-foreground opacity-0 transition-opacity group-hover/dep:opacity-100 hover:text-foreground"
                            aria-label="Remove relation"
                            @click="actions.removeRelation(issue, row.id)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </li>
                </ul>
            </div>

            <div v-if="dependencies.length > 0">
                <p class="mb-1 text-[11px] text-muted-foreground">
                    Team workstreams waiting on
                </p>
                <ul class="divide-y rounded-lg border">
                    <li
                        v-for="row in dependencies"
                        :key="
                            row.id ??
                            `${row.workstream_uuid}-${row.issue?.uuid}`
                        "
                        class="group/dep flex h-9 items-center gap-2.5 px-3 text-[13px]"
                    >
                        <InboxDot :color="row.team.color" size="sm" />
                        <span class="shrink-0 text-muted-foreground"
                            >{{ row.team.name }} →</span
                        >
                        <Link
                            v-if="row.issue"
                            :href="`/${slug}/issues/${row.issue.identifier}`"
                            class="flex min-w-0 flex-1 items-center gap-2 hover:underline"
                        >
                            <IssueIdentifier
                                :identifier="row.issue.identifier"
                            />
                            <span class="truncate">{{ row.issue.title }}</span>
                            <span
                                v-if="row.depends_on_team"
                                class="shrink-0 text-[11px] text-muted-foreground"
                                >({{ row.depends_on_team.name }})</span
                            >
                        </Link>
                        <Check
                            v-if="row.resolved"
                            class="size-3.5 text-success"
                        />
                        <button
                            v-if="row.id !== null && issue.can.update"
                            type="button"
                            class="rounded p-0.5 text-muted-foreground opacity-0 transition-opacity group-hover/dep:opacity-100 hover:text-foreground"
                            aria-label="Remove dependency"
                            @click="removeDependency(row)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <LinkIssueDialog
            v-model:open="dialog"
            mode="relation"
            :exclude="issue.identifier"
            @pick="pick"
        />
    </section>
</template>
