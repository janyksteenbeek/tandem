<script setup lang="ts">
import { useMounted } from '@vueuse/core';
import { Check, Download, MonitorSmartphone } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useBranding } from '@/composables/useBranding';
import { useInstallPrompt } from '@/composables/usePwa';

/**
 * Where to install the app from settings. Chromium browsers get a button;
 * Safari has no prompt API, so it gets the two gestures that do the job.
 */
const branding = useBranding();
const { canInstall, isInstalled, install } = useInstallPrompt();

const mounted = useMounted();

const isSafari = computed(
    () =>
        mounted.value &&
        /safari/i.test(navigator.userAgent) &&
        !/chrome|chromium|crios|android/i.test(navigator.userAgent),
);
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 sm:flex-row sm:items-start"
    >
        <span
            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand-soft-foreground"
        >
            <MonitorSmartphone class="size-5" />
        </span>

        <div class="min-w-0 flex-1 space-y-1">
            <h2 class="text-sm font-semibold">{{ branding.name }} as an app</h2>
            <p class="text-sm text-muted-foreground">
                Opens in its own window, sits in your dock or home screen, and
                keeps the mail you have read available when the connection
                drops.
            </p>

            <p
                v-if="isInstalled"
                class="flex items-center gap-1.5 pt-2 text-sm text-foreground"
            >
                <Check class="size-4 text-brand" /> Installed on this device.
            </p>
            <p
                v-else-if="!canInstall && isSafari"
                class="pt-2 text-sm text-muted-foreground"
            >
                In Safari, open the Share menu and choose
                <span class="font-medium text-foreground">Add to Dock</span>
                on a Mac or
                <span class="font-medium text-foreground"
                    >Add to Home Screen</span
                >
                on iPhone and iPad.
            </p>
            <p
                v-else-if="!canInstall"
                class="pt-2 text-sm text-muted-foreground"
            >
                Your browser will offer to install from its address bar or menu
                once it is ready.
            </p>
        </div>

        <Button
            v-if="canInstall && !isInstalled"
            class="shrink-0 gap-2"
            @click="install"
        >
            <Download class="size-4" /> Install
        </Button>
    </section>
</template>
