<script setup lang="ts">
import { X } from '@lucide/vue';

/**
 * The frame of the chat and participants panels: a column beside the stage on wide screens,
 * a full overlay on narrow ones.
 */
defineProps<{ title: string; subtitle?: string }>();

defineEmits<{ close: [] }>();
</script>

<template>
    <aside
        class="absolute inset-0 z-30 flex min-h-0 flex-col bg-card lg:static lg:z-auto lg:w-[22.5rem] lg:shrink-0 lg:rounded-2xl lg:border lg:shadow-pane xl:w-96"
        :aria-label="title"
    >
        <header
            class="flex h-13 shrink-0 items-center justify-between gap-3 border-b px-4 py-3"
        >
            <div class="min-w-0">
                <h2 class="truncate text-[15px] font-semibold tracking-tight">
                    {{ title }}
                </h2>
                <p
                    v-if="subtitle"
                    class="truncate text-xs text-muted-foreground"
                >
                    {{ subtitle }}
                </p>
            </div>
            <div class="flex items-center gap-1">
                <slot name="actions" />
                <button
                    type="button"
                    :aria-label="`Close ${title.toLowerCase()}`"
                    class="flex size-8 items-center justify-center rounded-lg text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @click="$emit('close')"
                >
                    <X class="size-4" />
                </button>
            </div>
        </header>
        <slot />
    </aside>
</template>
