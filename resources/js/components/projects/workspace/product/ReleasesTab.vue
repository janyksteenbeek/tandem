<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Rocket } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ProgressRing from '@/components/projects/charts/ProgressRing.vue';
import ReleaseDialog from '@/components/projects/dialogs/ReleaseDialog.vue';
import OverviewSection from '@/components/projects/workspace/OverviewSection.vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Product, Release } from '@/types';

const props = defineProps<{
    product?: Product;
    releases: Release[] | null | undefined;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const product = computed(
    () =>
        props.product ??
        ((page.props as Record<string, unknown>).product as Product),
);
const base = computed(
    () => `/${slug.value}/products/${product.value.slug}/releases`,
);

const list = ref<Release[]>(props.releases ?? []);
watch(
    () => props.releases,
    (value) => (list.value = value ?? []),
);

const upcoming = computed(() =>
    list.value
        .filter((r) => r.status === 'planned' || r.status === 'in_progress')
        .sort((a, b) =>
            (a.planned_at ?? '9999').localeCompare(b.planned_at ?? '9999'),
        ),
);
const shipped = computed(() =>
    list.value
        .filter((r) => r.status === 'released' || r.status === 'canceled')
        .sort((a, b) =>
            (b.released_at ?? b.planned_at ?? '').localeCompare(
                a.released_at ?? a.planned_at ?? '',
            ),
        ),
);

const createOpen = ref(false);

const onSaved = (release: Release) => {
    list.value = [release, ...list.value];
    router.reload({ only: ['releases'] });
};

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

const date = (iso: string | null) =>
    iso
        ? new Date(iso.slice(0, 10) + 'T00:00:00').toLocaleDateString(
              undefined,
              { month: 'short', day: 'numeric', year: 'numeric' },
          )
        : '—';
</script>

<template>
    <div class="max-w-3xl space-y-8">
        <div
            v-if="releases === undefined || releases === null"
            class="space-y-3"
        >
            <div v-for="i in 4" :key="i" class="h-9 shimmer rounded-lg" />
        </div>

        <div
            v-else-if="list.length === 0"
            class="flex flex-col items-center rounded-2xl border border-dashed bg-surface px-6 py-10 text-center"
        >
            <span
                class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                ><Rocket class="size-5"
            /></span>
            <p class="font-display text-2xl">No releases yet.</p>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Releases bundle issues from any project or module into a version
                you ship.
            </p>
            <Button
                v-if="product.can.update"
                size="sm"
                class="mt-4 gap-1.5"
                @click="createOpen = true"
                ><Plus class="size-3.5" /> New release</Button
            >
        </div>

        <template v-else>
            <div v-if="product.can.update" class="flex justify-end">
                <Button size="sm" class="h-8 gap-1.5" @click="createOpen = true"
                    ><Plus class="size-3.5" /> New release</Button
                >
            </div>

            <OverviewSection
                v-for="group in [
                    { key: 'upcoming', label: 'Upcoming', rows: upcoming },
                    { key: 'shipped', label: 'Released', rows: shipped },
                ]"
                :key="group.key"
                :label="group.label"
                :hint="group.rows.length ? `${group.rows.length}` : null"
            >
                <p
                    v-if="group.rows.length === 0"
                    class="rounded-lg border border-dashed px-3 py-5 text-center text-[12.5px] text-muted-foreground"
                >
                    {{
                        group.key === 'upcoming'
                            ? 'Nothing planned.'
                            : 'Nothing shipped yet.'
                    }}
                </p>
                <ul v-else class="space-y-px">
                    <li v-for="release in group.rows" :key="release.uuid">
                        <Link
                            :href="`${base}/${release.uuid}`"
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
                                :tone="
                                    release.status === 'released'
                                        ? 'success'
                                        : 'brand'
                                "
                            />
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-[13.5px] font-medium"
                                    >{{ release.name }}
                                    <span
                                        v-if="release.version"
                                        class="ml-1 font-mono text-[11px] text-muted-foreground"
                                        >{{ release.version }}</span
                                    ></span
                                >
                                <span
                                    class="block text-[11.5px] text-muted-foreground tabular-nums"
                                    >{{ release.completed_issues_count }} of
                                    {{ release.issues_count }} issues done</span
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
                                class="w-28 text-right text-[11.5px] text-muted-foreground tabular-nums"
                                >{{
                                    date(
                                        release.released_at ??
                                            release.planned_at,
                                    )
                                }}</span
                            >
                        </Link>
                    </li>
                </ul>
            </OverviewSection>
        </template>

        <ReleaseDialog
            v-model:open="createOpen"
            :product-slug="product.slug"
            :product-name="product.name"
            @saved="onSaved"
        />
    </div>
</template>
