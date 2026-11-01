<script setup lang="ts">
import { WifiOff } from '@lucide/vue';
import { useNetworkStatus } from '@/composables/usePwa';

/**
 * A quiet strip over the content while the connection is gone. The pages
 * already loaded keep working; anything that needs the server will not.
 */
const { isOnline } = useNetworkStatus();
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="-translate-y-full opacity-0"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="-translate-y-full opacity-0"
    >
        <div
            v-if="!isOnline"
            role="status"
            class="flex items-center justify-center gap-2 border-b border-highlight/40 bg-highlight/15 px-4 py-1.5 text-xs font-medium text-foreground"
        >
            <WifiOff class="size-3.5 text-highlight-foreground" />
            <span>
                You're offline. Showing what was last loaded; changes will wait
                until you're back.
            </span>
        </div>
    </Transition>
</template>
