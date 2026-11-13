<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, inject } from 'vue';
import ProjectsSidebar from '@/components/projects/ProjectsSidebar.vue';
import { useShellColumn } from '@/composables/useAppShell';
import { useKeyboard } from '@/composables/useKeyboard';
import { PRIORITY } from '@/lib/keyboard';
import type { CreateIssuePrefill } from '@/types';

/**
 * Every projects page lives in this layout: it swaps the shell column for the projects
 * navigation and owns the section-wide shortcuts (C to create, G-then-… to jump).
 */
const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const openCreateIssue = inject<(prefill?: CreateIssuePrefill) => void>(
    'openCreateIssue',
    () => {},
);

useShellColumn(() => ({
    component: ProjectsSidebar,
    props: { onCreate: () => openCreateIssue() },
    compose: () => openCreateIssue(),
}));

const go = (path: string) => router.visit(`/${slug.value}${path}`);

useKeyboard(
    () => [
        // Listed before "c": shortcuts match in order, and "g c" must win over "c" after a "g".
        {
            keys: 'g c',
            description: 'Go to calls',
            group: 'Navigate',
            handler: () => go('/calls'),
        },
        {
            keys: 'c',
            description: 'Create issue',
            group: 'Issues',
            handler: () => openCreateIssue(),
        },
        {
            keys: 'g i',
            description: 'Go to my issues',
            group: 'Navigate',
            handler: () => go('/my-issues'),
        },
        {
            keys: 'g p',
            description: 'Go to projects',
            group: 'Navigate',
            handler: () => go('/projects/all'),
        },
        {
            keys: 'g r',
            description: 'Go to products',
            group: 'Navigate',
            handler: () => go('/products'),
        },
        {
            keys: 'g t',
            description: 'Go to teams',
            group: 'Navigate',
            handler: () => go('/teams'),
        },
        {
            keys: 'g m',
            description: 'Go to mail',
            group: 'Navigate',
            handler: () => go('/mail'),
        },
        {
            keys: 'g s',
            description: 'Go to settings',
            group: 'Navigate',
            handler: () => router.visit('/settings/profile'),
        },
    ],
    { scope: 'Projects', priority: PRIORITY.section },
);
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <slot />
    </div>
</template>
