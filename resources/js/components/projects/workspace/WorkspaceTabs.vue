<script lang="ts">
export type WorkspaceTab = {
    key: string;
    label: string;
    href: string;
    count?: number | null;
    only?: string[];
};
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';

/**
 * Underline tabs. Each tab is a real link (crawlable, middle-clickable) that performs a partial
 * visit so the header never remounts; hover prefetches the tab's payload.
 */
defineProps<{ tabs: WorkspaceTab[]; active: string }>();
const emit = defineEmits<{ change: [key: string] }>();
</script>

<template>
    <div
        role="tablist"
        class="-mb-px flex scrollbar-thin items-center gap-0.5 overflow-x-auto"
    >
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            role="tab"
            :aria-selected="tab.key === active"
            :only="tab.only"
            preserve-state
            preserve-scroll
            prefetch="hover"
            :class="
                cn(
                    'flex h-9 shrink-0 items-center gap-1.5 border-b-2 border-transparent px-2.5 text-[13px] text-muted-foreground transition-colors hover:text-foreground',
                    tab.key === active &&
                        'border-brand font-medium text-foreground',
                )
            "
            @click="emit('change', tab.key)"
        >
            {{ tab.label }}
            <span
                v-if="tab.count !== undefined && tab.count !== null"
                class="rounded-full bg-muted px-1.5 text-[10.5px] text-muted-foreground tabular-nums"
                >{{ tab.count }}</span
            >
        </Link>
    </div>
</template>
