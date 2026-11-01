<script setup lang="ts">
import { useBranding } from '@/composables/useBranding';

/**
 * The shell's second column: a fixed-width aside beside the rail with a header,
 * a scrolling body and a footer. Mail and settings both render into this frame.
 */
defineProps<{ label: string }>();

const branding = useBranding();
</script>

<template>
    <aside
        class="flex h-full w-60 shrink-0 flex-col border-r border-sidebar-border bg-sidebar"
        :aria-label="label"
    >
        <slot name="header" />

        <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-2 pb-4">
            <slot />
        </div>

        <div
            class="flex items-center gap-2 border-t border-sidebar-border px-3 py-2 text-[11px] text-muted-foreground"
        >
            <slot name="footer">
                <span class="font-display text-[13px] italic">{{
                    branding.name
                }}</span>
                <span v-if="branding.tagline" class="truncate">
                    · {{ branding.tagline }}
                </span>
            </slot>
        </div>
    </aside>
</template>
