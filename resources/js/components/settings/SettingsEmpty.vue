<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';

defineProps<{ title: string; body?: string; icon?: LucideIcon }>();
</script>

<template>
    <div class="flex flex-col items-center rounded-2xl border border-dashed bg-surface px-6 py-10 text-center animate-rise">
        <span v-if="icon" class="mb-3 flex size-11 items-center justify-center rounded-2xl bg-brand-soft text-brand-soft-foreground">
            <component :is="icon" class="size-5" />
        </span>
        <p class="font-display text-2xl">{{ title }}</p>
        <p v-if="body" class="mt-1 max-w-sm text-sm text-muted-foreground">{{ body }}</p>
        <div v-if="$slots.default" class="mt-4 flex flex-wrap items-center justify-center gap-2">
            <slot />
        </div>
    </div>
</template>
