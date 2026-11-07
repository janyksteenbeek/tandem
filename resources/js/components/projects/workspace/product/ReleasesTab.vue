<script setup lang="ts">
import { Rocket } from '@lucide/vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import { cn } from '@/lib/utils';
import type { Release } from '@/types';

defineProps<{ releases: Release[] | null | undefined }>();

const STATUS: Record<Release['status'], { label: string; className: string }> =
    {
        planned: {
            label: 'Planned',
            className: 'bg-muted text-muted-foreground',
        },
        in_progress: {
            label: 'In progress',
            className: 'bg-warning/20 text-warning-foreground',
        },
        released: {
            label: 'Released',
            className: 'bg-success/12 text-success',
        },
        canceled: {
            label: 'Canceled',
            className: 'bg-destructive/12 text-destructive',
        },
    };
</script>

<template>
    <div class="max-w-3xl">
        <div
            v-if="releases === undefined || releases === null"
            class="space-y-3"
        >
            <div v-for="i in 4" :key="i" class="h-9 shimmer rounded-lg" />
        </div>
        <div
            v-else-if="releases.length === 0"
            class="flex flex-col items-center rounded-2xl border border-dashed bg-surface px-6 py-10 text-center"
        >
            <span
                class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                ><Rocket class="size-5"
            /></span>
            <p class="font-display text-2xl">No releases yet.</p>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Releases bundle issues from any project or module into a version
                you ship. Release management lands in the planning slice.
            </p>
        </div>
        <ul v-else class="space-y-px">
            <li
                v-for="release in releases"
                :key="release.uuid"
                class="-mx-2 issue-row h-11"
            >
                <ProgressRing
                    :value="
                        release.issues_count
                            ? release.completed_issues_count /
                              release.issues_count
                            : 0
                    "
                    :size="18"
                    tone="success"
                />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13.5px] font-medium"
                        >{{ release.name }}
                        <span
                            v-if="release.version"
                            class="ml-1 font-mono text-[11px] text-muted-foreground"
                            >{{ release.version }}</span
                        ></span
                    >
                    <span class="block text-[11.5px] text-muted-foreground"
                        >{{ release.completed_issues_count }}/{{
                            release.issues_count
                        }}
                        issues done</span
                    >
                </span>
                <span
                    :class="
                        cn(
                            'rounded-full px-2 py-0.5 text-[11px] font-medium',
                            STATUS[release.status].className,
                        )
                    "
                    >{{ STATUS[release.status].label }}</span
                >
                <span
                    class="w-24 text-right text-[11.5px] text-muted-foreground tabular-nums"
                    >{{
                        release.released_at
                            ? release.released_at.slice(0, 10)
                            : (release.planned_at ?? '—')
                    }}</span
                >
            </li>
        </ul>
    </div>
</template>
