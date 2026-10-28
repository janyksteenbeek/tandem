<template>
    <div class="divide-y divide-border/70">
        <div v-for="n in 8" :key="n" class="flex gap-3 px-3 py-3">
            <div class="size-10 rounded-full shimmer" />
            <div class="flex-1 space-y-2">
                <div class="flex items-center gap-2">
                    <div class="h-3 w-28 rounded shimmer" />
                    <div class="ml-auto h-2.5 w-8 rounded shimmer" />
                </div>
                <div class="h-3 w-3/4 rounded shimmer" />
                <div class="h-2.5 w-full rounded shimmer" />
            </div>
        </div>
    </div>
</template>
