<script setup lang="ts">
defineProps<{ label: string; hint?: string | null }>();
</script>

<template>
    <section>
        <div class="mb-2 flex items-center justify-between gap-2">
            <p
                class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ label }}
            </p>
            <span v-if="hint" class="text-[11px] text-muted-foreground">{{
                hint
            }}</span>
            <slot name="action" />
        </div>
        <slot />
    </section>
</template>
