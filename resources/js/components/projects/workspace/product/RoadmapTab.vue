<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Flag } from '@lucide/vue';
import { computed, defineAsyncComponent, onMounted } from 'vue';
import { api, reportError } from '@/lib/http';
import { productRoadmapRows } from '@/lib/projects/timelineRows';
import type { TimelineRow } from '@/lib/projects/timelineScale';
import type { Product, ProjectSummary, Release } from '@/types';

const Timeline = defineAsyncComponent(
    () => import('@/components/projects/timeline/Timeline.vue'),
);

/**
 * The product roadmap: every project as a bar and every release as a diamond on one timeline.
 * Product managers drag to reschedule; everyone else reads.
 */
const props = defineProps<{
    product: Product;
    projects: ProjectSummary[] | null | undefined;
    releases: Release[] | null | undefined;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');

const rows = computed(() =>
    productRoadmapRows(
        props.projects ?? [],
        props.releases ?? [],
        slug.value,
        props.product.slug,
    ),
);
const dated = computed(() => rows.value.filter((r) => r.start || r.end));

/**
 * A direct load of the roadmap URL arrives without the optional `projects`/`releases` props
 * (the controller resolves them for their own tabs); fetch them once instead of showing the
 * skeleton forever.
 */
onMounted(() => {
    if (props.projects === undefined || props.releases === undefined) {
        router.reload({ only: ['projects', 'releases'] });
    }
});

const shift = async ({
    kind,
    start,
    end,
    row,
}: {
    id: string;
    kind: TimelineRow['kind'];
    start: string | null;
    end: string | null;
    row: TimelineRow;
}) => {
    const meta = row.meta ?? {};

    try {
        if (kind === 'project') {
            await api.patch(`/${slug.value}/projects/${String(meta.slug)}`, {
                start_date: start,
                target_date: end,
            });
        } else if (kind === 'release') {
            await api.patch(
                `/${slug.value}/products/${props.product.slug}/releases/${String(meta.uuid)}`,
                { planned_at: end ?? start },
            );
        }

        router.reload({ only: ['projects', 'releases'] });
    } catch (error) {
        reportError(error);
    }
};

const open = (row: TimelineRow) => {
    if (row.url) {
        router.visit(row.url);
    }
};
</script>

<template>
    <div class="flex h-full min-h-[32rem] flex-col">
        <div
            v-if="projects === undefined || projects === null"
            class="space-y-3 p-4"
        >
            <div v-for="i in 5" :key="i" class="h-9 shimmer rounded-lg" />
        </div>
        <div
            v-else-if="rows.length === 0"
            class="m-4 flex flex-col items-center rounded-2xl border border-dashed bg-surface px-6 py-10 text-center"
        >
            <span
                class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                ><Flag class="size-5"
            /></span>
            <p class="font-display text-2xl">Nothing on the roadmap.</p>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Projects with dates and planned releases show up here on a
                timeline. Give a project a start and target date to begin.
            </p>
        </div>
        <Timeline
            v-else
            :rows="rows"
            zoom="month"
            :can-edit="product.can.update"
            :container-key="`roadmap:${product.id}`"
            @shift="shift"
            @open="open"
        >
            <template #toolbar>
                <span
                    class="flex items-center gap-3 text-[11px] text-muted-foreground"
                >
                    <span class="flex items-center gap-1"
                        ><span class="h-2.5 w-5 rounded-sm bg-brand/40" />
                        Project</span
                    >
                    <span class="flex items-center gap-1"
                        ><span
                            class="size-2 rotate-45 rounded-[2px] border-2 border-success"
                        />
                        Release</span
                    >
                    <span v-if="dated.length < rows.length"
                        >{{ rows.length - dated.length }} without dates</span
                    >
                </span>
            </template>
        </Timeline>
    </div>
</template>
