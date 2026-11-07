<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bot } from '@lucide/vue';
import { computed } from 'vue';
import IssueIdentifier from '@/components/projects/shared/IssueIdentifier.vue';
import { describeActivity } from '@/components/projects/workspace/activity';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { fullTime, relative } from '@/lib/time';
import type { Activity } from '@/types';

/** A chronological feed across many issues (product, project, team pages). */
const props = withDefaults(
    defineProps<{
        activity: Activity[] | null | undefined;
        limit?: number | null;
        emptyText?: string;
    }>(),
    { limit: null, emptyText: 'No activity yet.' },
);

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const rows = computed(() =>
    (props.activity ?? []).slice(0, props.limit ?? undefined),
);
</script>

<template>
    <div
        v-if="activity === undefined || activity === null"
        class="space-y-2 py-1"
    >
        <div v-for="i in 6" :key="i" class="flex items-center gap-3">
            <div class="size-5 shimmer rounded-full" />
            <div
                class="h-3 flex-1 shimmer rounded"
                :style="{ maxWidth: `${50 + ((i * 13) % 35)}%` }"
            />
        </div>
    </div>
    <p
        v-else-if="rows.length === 0"
        class="rounded-lg border border-dashed px-3 py-4 text-center text-[12.5px] text-muted-foreground"
    >
        {{ emptyText }}
    </p>
    <ol v-else class="relative space-y-0.5 border-l border-border/70 pl-4">
        <li
            v-for="row in rows"
            :key="row.id"
            class="relative flex items-start gap-2 py-1 text-[12.5px]"
        >
            <span
                class="absolute top-1.5 -left-[21px] rounded-full ring-2 ring-background"
            >
                <UserAvatar
                    v-if="row.actor"
                    :name="row.actor.name"
                    :email="row.actor.email"
                    :src="row.actor.avatar"
                    :initials="row.actor.initials"
                    size="xs"
                />
                <span
                    v-else
                    class="flex size-5 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    ><Bot class="size-3"
                /></span>
            </span>
            <span class="min-w-0 flex-1 leading-5">
                <span class="font-medium text-foreground">{{
                    row.actor?.name ?? 'Tandem'
                }}</span>
                <span class="text-muted-foreground">
                    {{ describeActivity(row) }}</span
                >
                <template v-if="row.issue">
                    <span class="text-muted-foreground"> on </span>
                    <Link
                        :href="`/${slug}/issues/${row.issue.identifier}`"
                        class="hover:underline"
                        ><IssueIdentifier
                            :identifier="row.issue.identifier"
                            :muted="false"
                        />
                        <span class="text-foreground">{{
                            row.issue.title
                        }}</span></Link
                    >
                </template>
            </span>
            <time
                :datetime="row.created_at"
                :title="fullTime(row.created_at)"
                class="shrink-0 text-[11px] text-muted-foreground tabular-nums"
                >{{ relative(row.created_at) }}</time
            >
        </li>
    </ol>
</template>
