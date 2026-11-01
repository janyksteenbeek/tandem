<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import SettingsSidebar from '@/components/settings/SettingsSidebar.vue';
import { useShellColumn } from '@/composables/useAppShell';
import { useKeyboard } from '@/composables/useKeyboard';
import { cn } from '@/lib/utils';

/**
 * Settings pages share the app shell with mail; this layout only swaps the
 * column for the settings navigation and gives the content room to scroll.
 */
const page = usePage();

useShellColumn(() => ({ component: SettingsSidebar }));

/** Account pages keep the compact column from the starter kit; everything else is denser and wants the room. */
const narrow = computed(() =>
    [
        'settings/Profile',
        'settings/Security',
        'settings/Appearance',
        'settings/Notifications',
    ].includes(page.component),
);

const mailUrl = computed(() =>
    page.props.currentOrganization
        ? `/${page.props.currentOrganization.slug}/mail`
        : '/',
);

useKeyboard(() => [
    {
        keys: 'g m',
        description: 'Back to mail',
        handler: () => router.visit(mailUrl.value),
    },
]);
</script>

<template>
    <div class="h-full min-h-0 overflow-y-auto">
        <section
            :class="
                cn(
                    'space-y-12 px-4 pt-14 pb-6 sm:px-8 md:pt-6',
                    narrow ? 'max-w-2xl' : 'max-w-6xl',
                )
            "
        >
            <slot />
        </section>
    </div>
</template>
