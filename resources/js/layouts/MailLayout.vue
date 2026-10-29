<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { Menu } from '@lucide/vue';
import { computed, provide, ref } from 'vue';
import CommandPalette from '@/components/mail/CommandPalette.vue';
import KeyboardShortcutsDialog from '@/components/mail/KeyboardShortcutsDialog.vue';
import MailRail from '@/components/mail/MailRail.vue';
import MailSidebar from '@/components/mail/MailSidebar.vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useKeyboard } from '@/composables/useKeyboard';
import type { SavedView, ViewOption } from '@/types';

const props = defineProps<{
    activeInboxUuid?: string | null;
    activeView?: string | null;
    views?: ViewOption[];
    counts?: Record<string, number> | null;
    hideNavButton?: boolean;
    savedViews?: SavedView[];
}>();

const emit = defineEmits<{ compose: [] }>();

const page = usePage();
const paletteOpen = ref(false);
const shortcutsOpen = ref(false);
const mobileNavOpen = ref(false);

const userId = computed(() => page.props.auth.user.id);

provide('openCommandPalette', () => (paletteOpen.value = true));
provide('openShortcuts', () => (shortcutsOpen.value = true));

useEcho(`user.${userId.value}`, '.user.inbox', () => {
    router.reload({ only: ['mailNav', 'counts'] });
});

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
    { keys: 'c', description: 'Compose', handler: () => emit('compose') },
]);
</script>

<template>
    <TooltipProvider :delay-duration="200">
        <div class="flex h-dvh w-full overflow-hidden bg-background">
            <div class="hidden md:flex">
                <MailRail
                    @open-search="paletteOpen = true"
                    @open-shortcuts="shortcutsOpen = true"
                />
                <MailSidebar
                    :active-inbox-uuid="props.activeInboxUuid"
                    :active-view="props.activeView"
                    :views="props.views"
                    :counts="props.counts"
                    :saved-views="props.savedViews"
                    @compose="emit('compose')"
                />
            </div>

            <Sheet v-model:open="mobileNavOpen">
                <SheetContent side="left" class="w-[19.5rem] p-0">
                    <div class="flex h-full">
                        <MailRail
                            @open-search="paletteOpen = true"
                            @open-shortcuts="shortcutsOpen = true"
                        />
                        <MailSidebar
                            :active-inbox-uuid="props.activeInboxUuid"
                            :active-view="props.activeView"
                            :views="props.views"
                            :counts="props.counts"
                            @compose="
                                mobileNavOpen = false;
                                emit('compose');
                            "
                        />
                    </div>
                </SheetContent>
            </Sheet>

            <main class="relative flex min-w-0 flex-1 flex-col">
                <Button
                    v-if="!props.hideNavButton"
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

            <CommandPalette
                v-model:open="paletteOpen"
                @compose="emit('compose')"
            />
            <KeyboardShortcutsDialog v-model:open="shortcutsOpen" />
            <Toaster position="bottom-right" rich-colors close-button />
        </div>
    </TooltipProvider>
</template>
