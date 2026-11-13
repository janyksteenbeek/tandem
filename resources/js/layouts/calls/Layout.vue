<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CallsSidebar from '@/components/calls/CallsSidebar.vue';
import JoinMeetingDialog from '@/components/calls/JoinMeetingDialog.vue';
import { useCallStarter } from '@/composables/calls/useCallStarter';
import { useShellColumn } from '@/composables/useAppShell';
import { useKeyboard } from '@/composables/useKeyboard';
import { PRIORITY } from '@/lib/keyboard';
import { index } from '@/routes/calls';

/**
 * Every calls page lives in this layout: it swaps the shell column for the calls navigation
 * and owns the section-wide shortcuts (N to start, J to join, G-then-… to jump).
 */
const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const { start } = useCallStarter();
const joinOpen = ref(false);

useShellColumn(() => ({ component: CallsSidebar }));

const go = (path: string) => router.visit(`/${slug.value}${path}`);

useKeyboard(
    () => [
        {
            keys: 'n',
            description: 'Start a meeting',
            group: 'Calls',
            handler: () => start(),
        },
        {
            keys: 'j',
            description: 'Join a meeting',
            group: 'Calls',
            handler: (event) => {
                event.preventDefault();
                joinOpen.value = true;
            },
        },
        {
            keys: 'g c',
            description: 'Go to recent meetings',
            group: 'Navigate',
            handler: () => router.visit(index.url(slug.value)),
        },
        {
            keys: 'g r',
            description: 'Go to recordings',
            group: 'Navigate',
            handler: () =>
                router.visit(
                    index.url(slug.value, { query: { tab: 'recordings' } }),
                ),
        },
        {
            keys: 'g m',
            description: 'Go to mail',
            group: 'Navigate',
            handler: () => go('/mail'),
        },
        {
            keys: 'g i',
            description: 'Go to my issues',
            group: 'Navigate',
            handler: () => go('/my-issues'),
        },
        {
            keys: 'g s',
            description: 'Go to settings',
            group: 'Navigate',
            handler: () => router.visit('/settings/profile'),
        },
    ],
    { scope: 'Calls', priority: PRIORITY.section },
);
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <slot />
        <JoinMeetingDialog v-model:open="joinOpen" />
    </div>
</template>
