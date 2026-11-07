<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Flag, FolderKanban, Rocket } from '@lucide/vue';
import { computed } from 'vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import { PROJECT_STATUS_META } from '@/lib/projects/meta';
import { cn } from '@/lib/utils';
import type { Product, ProjectSummary, Release } from '@/types';

/** Projects and releases on one time-ordered line. The interactive timeline arrives with the planning slice. */
const props = defineProps<{
    product: Product;
    projects: ProjectSummary[] | null | undefined;
    releases: Release[] | null | undefined;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

type Entry = {
    key: string;
    kind: 'project' | 'release';
    date: string | null;
    label: string;
    href: string | null;
    color: string | null;
    progress: number;
    status: string;
    meta: string;
};

const entries = computed<Entry[]>(() => {
    const rows: Entry[] = [];

    for (const project of props.projects ?? []) {
        rows.push({
            key: project.uuid,
            kind: 'project',
            date: project.target_date,
            label: project.name,
            href: `/${slug.value}/projects/${project.slug}`,
            color: project.color,
            progress: project.progress,
            status: PROJECT_STATUS_META[project.status].label,
            meta: project.start_date
                ? `${project.start_date} → ${project.target_date ?? '…'}`
                : (project.target_date ?? ''),
        });
    }

    for (const release of props.releases ?? []) {
        rows.push({
            key: release.uuid,
            kind: 'release',
            date: release.planned_at,
            label:
                release.name + (release.version ? ` ${release.version}` : ''),
            href: null,
            color: null,
            progress: release.issues_count
                ? release.completed_issues_count / release.issues_count
                : 0,
            status: release.status.replace('_', ' '),
            meta: release.planned_at ?? 'Unscheduled',
        });
    }

    return rows.sort((a, b) =>
        (a.date ?? '9999').localeCompare(b.date ?? '9999'),
    );
});

const months = computed(() => {
    const map = new Map<string, Entry[]>();

    for (const entry of entries.value) {
        const key = entry.date ? entry.date.slice(0, 7) : 'unscheduled';
        map.set(key, [...(map.get(key) ?? []), entry]);
    }

    return [...map.entries()].map(([key, items]) => ({
        key,
        label:
            key === 'unscheduled'
                ? 'Unscheduled'
                : new Date(key + '-01T00:00:00').toLocaleDateString(undefined, {
                      month: 'long',
                      year: 'numeric',
                  }),
        items,
    }));
});
</script>

<template>
    <div class="max-w-3xl">
        <div
            v-if="projects === undefined || projects === null"
            class="space-y-3"
        >
            <div v-for="i in 5" :key="i" class="h-9 shimmer rounded-lg" />
        </div>
        <div
            v-else-if="entries.length === 0"
            class="flex flex-col items-center rounded-2xl border border-dashed bg-surface px-6 py-10 text-center"
        >
            <span
                class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                ><Flag class="size-5"
            /></span>
            <p class="font-display text-2xl">Nothing on the roadmap.</p>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Projects with target dates and planned releases show up here in
                order.
            </p>
        </div>
        <ol v-else class="relative space-y-6 border-l border-border/70 pl-5">
            <li v-for="month in months" :key="month.key">
                <p
                    class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ month.label }}
                </p>
                <ul class="space-y-px">
                    <li
                        v-for="entry in month.items"
                        :key="entry.key"
                        class="relative"
                    >
                        <span
                            :class="
                                cn(
                                    'absolute top-2.5 -left-[25px] flex size-3 items-center justify-center rounded-full ring-2 ring-background',
                                    entry.kind === 'release'
                                        ? 'bg-success'
                                        : 'bg-brand',
                                )
                            "
                        />
                        <component
                            :is="entry.href ? Link : 'div'"
                            :href="entry.href ?? undefined"
                            :class="
                                cn(
                                    '-mx-2 issue-row h-10',
                                    entry.href && 'cursor-pointer',
                                )
                            "
                        >
                            <Rocket
                                v-if="entry.kind === 'release'"
                                class="size-3.5 text-success"
                            />
                            <FolderKanban
                                v-else
                                class="size-3.5 text-muted-foreground"
                            />
                            <InboxDot
                                v-if="entry.color"
                                :color="entry.color"
                                size="sm"
                            />
                            <span
                                class="min-w-0 flex-1 truncate text-[13.5px] font-medium"
                                >{{ entry.label }}</span
                            >
                            <span
                                class="text-[11.5px] text-muted-foreground capitalize"
                                >{{ entry.status }}</span
                            >
                            <span
                                class="text-[11.5px] text-muted-foreground tabular-nums"
                                >{{ entry.meta }}</span
                            >
                            <ProgressRing
                                :value="entry.progress"
                                :size="14"
                                :tone="
                                    entry.kind === 'release'
                                        ? 'success'
                                        : 'brand'
                                "
                            />
                        </component>
                    </li>
                </ul>
            </li>
        </ol>
    </div>
</template>
