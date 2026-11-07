<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import { computed } from 'vue';
import AvatarStack from '@/components/projects/shared/AvatarStack.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import type { CycleSummary, TeamSummary, UserSummary } from '@/types';

type Row = TeamSummary & {
    description: string | null;
    is_member: boolean;
    members: UserSummary[];
    members_count: number;
    open_workstreams: number;
    active_cycle: CycleSummary | null;
};

const props = defineProps<{ teams: Row[] }>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const canManage = computed(() => Boolean(page.props.canManageOrganization));
const ordered = computed(() =>
    [...props.teams].sort(
        (a, b) =>
            Number(b.is_member) - Number(a.is_member) ||
            a.name.localeCompare(b.name),
    ),
);
</script>

<template>
    <Head title="Teams" />

    <div class="flex h-full min-h-0 flex-col">
        <header
            class="flex shrink-0 items-center justify-between gap-3 border-b px-4 py-3 pl-12 sm:px-6 md:pl-6"
        >
            <div>
                <h1
                    class="font-display text-xl font-semibold tracking-[-0.01em]"
                >
                    Teams
                </h1>
                <p class="text-[12px] text-muted-foreground">
                    Who does the work. Every team has its own workflow and
                    cycles.
                </p>
            </div>
            <Link
                v-if="canManage"
                :href="`/settings/organizations/${slug}/teams`"
                class="text-[12.5px] text-brand hover:underline"
                >Manage teams</Link
            >
        </header>

        <div
            class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-6"
        >
            <div
                v-if="teams.length === 0"
                class="mx-auto flex max-w-md flex-col items-center py-16 text-center"
            >
                <span
                    class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                    ><Users class="size-5"
                /></span>
                <p class="font-display text-2xl">No teams yet.</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Teams are created in organization settings and shared with
                    mail.
                </p>
            </div>
            <ul v-else class="mx-auto max-w-5xl space-y-px">
                <li v-for="team in ordered" :key="team.id">
                    <Link
                        :href="`/${slug}/teams/${team.slug}`"
                        class="-mx-2 issue-row h-12"
                    >
                        <InboxDot :color="team.color" size="md" />
                        <span class="min-w-0 flex-1">
                            <span
                                class="flex items-center gap-2 text-[13.5px] font-medium"
                                >{{ team.name
                                }}<span
                                    v-if="team.is_member"
                                    class="rounded-full bg-brand-soft px-1.5 text-[10px] font-medium text-brand-soft-foreground"
                                    >Your team</span
                                ></span
                            >
                            <span
                                v-if="team.description"
                                class="block truncate text-[11.5px] text-muted-foreground"
                                >{{ team.description }}</span
                            >
                        </span>
                        <span
                            v-if="team.active_cycle"
                            class="hidden rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground md:inline"
                            >{{ team.active_cycle.name }} ·
                            {{
                                Math.round(team.active_cycle.progress * 100)
                            }}%</span
                        >
                        <span class="text-[11.5px] text-muted-foreground"
                            ><span
                                class="font-semibold text-foreground tabular-nums"
                                >{{ team.open_workstreams }}</span
                            >
                            open</span
                        >
                        <AvatarStack :users="team.members" :max="4" />
                        <span
                            v-if="team.members_count > team.members.length"
                            class="text-[11px] text-muted-foreground"
                            >+{{
                                team.members_count - team.members.length
                            }}</span
                        >
                    </Link>
                </li>
            </ul>
        </div>
    </div>
</template>
