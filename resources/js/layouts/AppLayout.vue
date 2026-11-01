<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { Menu } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, provide, ref } from 'vue';
import CommandPalette from '@/components/mail/CommandPalette.vue';
import KeyboardShortcutsDialog from '@/components/mail/KeyboardShortcutsDialog.vue';
import AppRail from '@/components/shell/AppRail.vue';
import OfflineBanner from '@/components/shell/OfflineBanner.vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { provideAppShell } from '@/composables/useAppShell';
import { useKeyboard } from '@/composables/useKeyboard';
import { playNotificationSound } from '@/lib/notificationSound';

/**
 * The one shell every signed-in page lives in: rail, an optional page-owned
 * column, and the content. Persistent across visits so the palette, the
 * shortcuts dialog and the live navigation counters survive navigation.
 */
const page = usePage();
const column = provideAppShell();

const paletteOpen = ref(false);
const shortcutsOpen = ref(false);
const mobileNavOpen = ref(false);

const userId = computed(() => page.props.auth.user.id);
const mailUrl = computed(() =>
    page.props.currentOrganization
        ? `/${page.props.currentOrganization.slug}/mail`
        : '/',
);

/** The palette's "Compose" goes to whatever page registered a handler; elsewhere it opens mail first. */
const compose = () => {
    if (column.value?.compose) {
        column.value.compose();

        return;
    }

    router.visit(mailUrl.value);
};

provide('openCommandPalette', () => (paletteOpen.value = true));
provide('openShortcuts', () => (shortcutsOpen.value = true));

useEcho<{ inbound?: boolean }>(
    `user.${userId.value}`,
    '.user.inbox',
    (payload) => {
        if (payload.inbound) {
            playNotificationSound();
        }

        router.reload({ only: ['mailNav', 'counts'] });
    },
);

useKeyboard(() => [
    {
        keys: 'mod+k',
        description: 'Command palette',
        handler: () => (paletteOpen.value = !paletteOpen.value),
        allowInInput: true,
    },
    {
        keys: 'shift+/',
        description: 'Shortcuts',
        handler: () => (shortcutsOpen.value = true),
    },
]);

let stopListening: (() => void) | undefined;

onMounted(() => {
    stopListening = router.on('navigate', () => {
        mobileNavOpen.value = false;
    });
});

onBeforeUnmount(() => stopListening?.());
</script>

<template>
    <TooltipProvider :delay-duration="200">
        <div class="flex h-dvh w-full overflow-hidden bg-background">
            <div class="hidden md:flex">
                <AppRail
                    @open-search="paletteOpen = true"
                    @open-shortcuts="shortcutsOpen = true"
                />
                <div
                    v-if="column"
                    class="flex [view-transition-name:shell-column]"
                >
                    <component :is="column.component" v-bind="column.props" />
                </div>
            </div>

            <Sheet v-model:open="mobileNavOpen">
                <SheetContent
                    side="left"
                    :class="column ? 'w-[18.5rem] p-0' : 'w-14 p-0'"
                >
                    <div class="flex h-full">
                        <AppRail
                            @open-search="paletteOpen = true"
                            @open-shortcuts="shortcutsOpen = true"
                        />
                        <component
                            :is="column.component"
                            v-if="column"
                            v-bind="column.props"
                        />
                    </div>
                </SheetContent>
            </Sheet>

            <main
                class="relative flex min-w-0 flex-1 flex-col [view-transition-name:main]"
            >
                <OfflineBanner />
                <Button
                    v-if="!column?.hideMobileTrigger"
                    variant="outline"
                    size="icon"
                    class="absolute top-2.5 left-2 z-20 size-8 md:hidden"
                    aria-label="Open navigation"
                    @click="mobileNavOpen = true"
                >
                    <Menu class="size-4" />
                </Button>
                <slot />
            </main>

            <CommandPalette v-model:open="paletteOpen" @compose="compose" />
            <KeyboardShortcutsDialog v-model:open="shortcutsOpen" />
            <Toaster position="bottom-right" rich-colors close-button />
        </div>
    </TooltipProvider>
</template>
