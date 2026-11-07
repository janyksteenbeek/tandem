<script lang="ts">
export type Crumb = { label: string; href?: string | null };
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';

defineProps<{ items: Crumb[] }>();
</script>

<template>
    <nav
        aria-label="Breadcrumb"
        class="flex min-w-0 items-center gap-1 text-[12px] text-muted-foreground"
    >
        <template v-for="(crumb, index) in items" :key="index">
            <ChevronRight
                v-if="index > 0"
                class="size-3 shrink-0 text-muted-foreground/60"
            />
            <Link
                v-if="crumb.href"
                :href="crumb.href"
                class="truncate transition-colors hover:text-foreground"
                >{{ crumb.label }}</Link
            >
            <span v-else class="truncate text-foreground" aria-current="page">{{
                crumb.label
            }}</span>
        </template>
    </nav>
</template>
