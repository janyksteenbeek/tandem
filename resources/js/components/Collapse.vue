<script setup lang="ts">
/**
 * Opens and closes a block by animating its height, so the content around it
 * slides instead of jumping. Rows are measured by the grid, which means the
 * content can be any height and change while open.
 */
defineProps<{ open: boolean }>();
</script>

<template>
    <Transition
        enter-active-class="transition-[grid-template-rows,opacity] duration-200 ease-out"
        enter-from-class="grid-rows-[0fr] opacity-0"
        enter-to-class="grid-rows-[1fr] opacity-100"
        leave-active-class="transition-[grid-template-rows,opacity] duration-150 ease-in"
        leave-from-class="grid-rows-[1fr] opacity-100"
        leave-to-class="grid-rows-[0fr] opacity-0"
    >
        <div v-if="open" class="grid">
            <div class="min-h-0 overflow-hidden">
                <slot />
            </div>
        </div>
    </Transition>
</template>
