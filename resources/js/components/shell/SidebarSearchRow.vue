<script setup lang="ts">
import { Search } from '@lucide/vue';
import { inject } from 'vue';
import { Kbd } from '@/components/ui/kbd';

/** Looks like a search field, opens the command palette. */
const openPalette = inject<() => void>('openCommandPalette', () => {});
</script>

<template>
    <button
        type="button"
        class="flex h-8 w-full items-center gap-2 rounded-lg border border-sidebar-border bg-background/70 px-2.5 text-[12.5px] text-muted-foreground shadow-xs transition-colors hover:border-foreground/15 hover:bg-background hover:text-foreground"
        aria-label="Search"
        @click="openPalette()"
    >
        <Search class="size-3.5 shrink-0" />
        <span class="flex-1 text-left">Search…</span>
        <Kbd class="h-4 min-w-4 bg-transparent px-1 text-[10px]">⌘K</Kbd>
    </button>
</template>
