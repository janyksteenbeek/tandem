<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { FolderKanban, Plus } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import WorkspaceHeader from '@/components/projects/workspace/WorkspaceHeader.vue';
import CreateProjectDialog from '@/components/projects/dialogs/CreateProjectDialog.vue';
import ProjectRows from '@/components/projects/workspace/ProjectRows.vue';
import { Button } from '@/components/ui/button';
import { useIssueLookups } from '@/composables/projects/useIssueLookups';
import { cn } from '@/lib/utils';
import type { IssueLookups, ProjectSummary } from '@/types';

const props = defineProps<{
    projects: ProjectSummary[];
    status: 'active' | 'completed' | 'all';
    lookups: IssueLookups;
    can: { create: boolean };
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const { lookups } = useIssueLookups(() => props.lookups);
const createOpen = ref(false);

onMounted(() => {
    if (
        new URLSearchParams(window.location.search).get('create') === '1' &&
        props.can.create
    ) {
        createOpen.value = true;
    }
});

const filters = [
    { key: 'active', label: 'Active' },
    { key: 'completed', label: 'Completed' },
    { key: 'all', label: 'All' },
] as const;

const setStatus = (key: string) =>
    router.visit(`/${slug.value}/projects/all?status=${key}`, {
        only: ['projects', 'status'],
        preserveState: true,
        preserveScroll: true,
    });

const grouped = computed(() => {
    const map = new Map<
        string,
        { label: string; color: string | null; projects: ProjectSummary[] }
    >();

    for (const project of props.projects) {
        const key = project.product?.uuid ?? 'standalone';
        const group = map.get(key) ?? {
            label: project.product?.name ?? 'Standalone',
            color: project.product?.color ?? null,
            projects: [],
        };
        group.projects.push(project);
        map.set(key, group);
    }

    return [...map.values()].sort((a, b) =>
        a.label === 'Standalone'
            ? 1
            : b.label === 'Standalone'
              ? -1
              : a.label.localeCompare(b.label),
    );
});
</script>

<template>
    <Head title="Projects" />

    <div class="flex h-full min-h-0 flex-col">
        <WorkspaceHeader
            :breadcrumbs="[{ label: 'Projects' }]"
            subtitle="Initiatives with a goal and a target date."
        >
            <template #meta>
                <div class="flex rounded-lg border p-0.5">
                    <button
                        v-for="filter in filters"
                        :key="filter.key"
                        type="button"
                        :class="
                            cn(
                                'rounded-md px-2.5 py-1 text-[12px] transition-colors',
                                status === filter.key
                                    ? 'bg-accent font-medium text-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )
                        "
                        @click="setStatus(filter.key)"
                    >
                        {{ filter.label }}
                    </button>
                </div>
            </template>
            <template #actions>
                <Button
                    v-if="can.create"
                    size="sm"
                    class="h-8 gap-1.5 bg-brand text-brand-foreground hover:bg-brand/90"
                    @click="createOpen = true"
                    ><Plus class="size-3.5" /> New project</Button
                >
            </template>
        </WorkspaceHeader>

        <div
            class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-6"
        >
            <div
                v-if="projects.length === 0"
                class="mx-auto flex max-w-md flex-col items-center py-16 text-center"
            >
                <span
                    class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground"
                    ><FolderKanban class="size-5"
                /></span>
                <p class="font-display text-2xl">
                    {{
                        status === 'active'
                            ? 'Nothing in flight.'
                            : 'No projects here.'
                    }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Projects group issues around an outcome. Progress is derived
                    from the issues themselves.
                </p>
                <Button
                    v-if="can.create"
                    class="mt-4 gap-1.5"
                    @click="createOpen = true"
                    ><Plus class="size-3.5" /> Create a project</Button
                >
            </div>
            <div v-else class="mx-auto max-w-5xl space-y-8">
                <section v-for="group in grouped" :key="group.label">
                    <p
                        class="mb-2 flex items-center gap-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <span
                            v-if="group.color"
                            class="size-2 rounded-full bg-(--chip)"
                            :class="`chip-${group.color}`"
                        />{{ group.label }}
                        <span class="font-normal normal-case tabular-nums">{{
                            group.projects.length
                        }}</span>
                    </p>
                    <ProjectRows
                        :projects="group.projects"
                        :show-product="false"
                    />
                </section>
                <p class="text-[11.5px] text-muted-foreground">
                    Looking for a product?
                    <Link
                        :href="`/${slug}/products`"
                        class="text-brand hover:underline"
                        >Products</Link
                    >
                    hold modules and releases.
                </p>
            </div>
        </div>

        <CreateProjectDialog v-model:open="createOpen" :lookups="lookups" />
    </div>
</template>
