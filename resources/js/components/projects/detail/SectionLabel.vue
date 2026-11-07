<script setup lang="ts">
defineProps<{ label: string }>();
</script>

<template>
    <div class="mb-2 flex items-center justify-between gap-2">
        <p
            class="flex items-center gap-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
        >
            {{ label }}
            <slot name="meta" />
        </p>
        <div class="flex items-center gap-1">
            <slot name="actions" />
        </div>
    </div>
</template>
