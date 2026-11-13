<script setup lang="ts">
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';

/**
 * The bare shell for everything under /meet: the meeting room and shared recordings. No rail
 * and no sidebar, and nothing here may assume a signed-in user, because guests land here too.
 */
</script>

<template>
    <TooltipProvider :delay-duration="200">
        <div class="flex h-dvh w-full flex-col overflow-hidden bg-background">
            <slot />
            <Toaster position="bottom-right" rich-colors close-button />
        </div>
    </TooltipProvider>
</template>
