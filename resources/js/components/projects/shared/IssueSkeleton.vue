<script setup lang="ts">
withDefaults(defineProps<{ rows?: number }>(), { rows: 8 });
</script>

<template>
    <div class="space-y-px px-2 py-2">
        <div
            v-for="i in rows"
            :key="i"
            class="flex h-9 items-center gap-3 rounded-lg px-2"
        >
            <div class="size-3.5 shimmer rounded-full" />
            <div class="h-3 w-14 shimmer rounded" />
            <div class="size-3.5 shimmer rounded-full" />
            <div
                class="h-3 flex-1 shimmer rounded"
                :style="{ maxWidth: `${40 + ((i * 17) % 40)}%` }"
            />
            <div class="ml-auto h-3 w-10 shimmer rounded" />
            <div class="size-5 shimmer rounded-full" />
        </div>
    </div>
</template>
