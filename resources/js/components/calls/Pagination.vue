<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/lib/calls/types';

/** Previous / next for a server-side paginator; hidden while everything fits on one page. */
defineProps<{ page: Paginated<unknown>; only?: string[]; label: string }>();
</script>

<template>
    <nav
        v-if="page.last_page > 1"
        class="mt-4 flex items-center justify-between gap-3 text-[12px] text-muted-foreground"
        :aria-label="`${label} pages`"
    >
        <span class="tabular-nums"
            >{{ page.from }}–{{ page.to }} of {{ page.total }}</span
        >
        <span class="flex items-center gap-1.5">
            <Button
                v-if="page.prev_page_url"
                as-child
                variant="outline"
                size="sm"
                class="h-7 gap-1 px-2 text-[12px]"
            >
                <Link
                    :href="page.prev_page_url"
                    :only="only"
                    preserve-state
                    rel="prev"
                    ><ChevronLeft class="size-3.5" /> Newer</Link
                >
            </Button>
            <Button
                v-if="page.next_page_url"
                as-child
                variant="outline"
                size="sm"
                class="h-7 gap-1 px-2 text-[12px]"
            >
                <Link
                    :href="page.next_page_url"
                    :only="only"
                    preserve-state
                    rel="next"
                    >Older <ChevronRight class="size-3.5"
                /></Link>
            </Button>
        </span>
    </nav>
</template>
