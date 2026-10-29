<script setup lang="ts">
defineProps<{ title: string; description?: string; count?: number | null }>();
</script>

<template>
    <section class="space-y-4">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1 basis-72">
                <h2
                    class="flex items-center gap-2 text-base font-semibold tracking-tight"
                >
                    {{ title }}
                    <span
                        v-if="count !== undefined && count !== null"
                        class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground tabular-nums"
                        >{{ count }}</span
                    >
                </h2>
                <p
                    v-if="description"
                    class="mt-0.5 text-sm text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>
        <slot />
    </section>
</template>
