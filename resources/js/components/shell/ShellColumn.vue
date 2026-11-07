<script setup lang="ts">
import { useSlots } from 'vue';

/**
 * The shell's second column: a fixed-width aside beside the rail with a header,
 * a scrolling body and an optional footer. Mail, projects and settings all render
 * into this frame so the three areas feel like one app.
 */
defineProps<{ label: string }>();

const slots = useSlots();
</script>

<template>
    <aside
        class="flex h-full w-62 shrink-0 flex-col border-r border-sidebar-border shell-column-surface"
        :aria-label="label"
    >
        <div class="px-3 pt-2.5 pb-2">
            <slot name="header" />
        </div>

        <div class="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-2 pb-4">
            <slot />
        </div>

        <div
            v-if="slots.footer"
            class="flex items-center gap-2 border-t border-sidebar-border px-4 py-2 text-[11px] text-muted-foreground"
        >
            <slot name="footer" />
        </div>
    </aside>
</template>
