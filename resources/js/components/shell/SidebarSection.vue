<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Plus } from '@lucide/vue';
import Collapse from '@/components/Collapse.vue';
import { cn } from '@/lib/utils';

/**
 * A labelled group of sidebar rows. Pass `open` to make it collapsible (the parent owns and
 * persists the state); leave it out for a plain section. The header can link to the full
 * list and offer an add button; both stay faintly visible so people find them.
 */
withDefaults(
    defineProps<{
        label: string;
        open?: boolean | null;
        count?: number | null;
        allHref?: string | null;
        canAdd?: boolean;
    }>(),
    { open: null, count: null, allHref: null, canAdd: false },
);

const emit = defineEmits<{ toggle: []; add: [] }>();
</script>

<template>
    <section class="mt-4 first:mt-1">
        <div class="group/head flex h-6 items-center gap-1 px-1">
            <button
                v-if="open !== null"
                type="button"
                class="flex h-6 min-w-0 flex-1 items-center gap-1 rounded-md px-1 text-left text-[10.5px] font-semibold tracking-[0.08em] text-muted-foreground/70 uppercase transition-colors hover:text-sidebar-foreground"
                :aria-expanded="open"
                @click="emit('toggle')"
            >
                <ChevronRight
                    :class="
                        cn(
                            'size-3 shrink-0 transition-transform',
                            open && 'rotate-90',
                        )
                    "
                />
                <span class="truncate">{{ label }}</span>
                <span v-if="count" class="ml-1 font-normal tabular-nums">{{
                    count
                }}</span>
            </button>
            <p
                v-else
                class="flex h-6 min-w-0 flex-1 items-center px-1 text-[10.5px] font-semibold tracking-[0.08em] text-muted-foreground/70 uppercase"
            >
                <span class="truncate">{{ label }}</span>
                <span v-if="count" class="ml-1 font-normal tabular-nums">{{
                    count
                }}</span>
            </p>
            <Link
                v-if="allHref"
                :href="allHref"
                class="rounded-md px-1 text-[10.5px] text-muted-foreground/50 transition-colors hover:bg-sidebar-accent hover:text-foreground"
                :aria-label="`All ${label.toLowerCase()}`"
                >All</Link
            >
            <button
                v-if="canAdd"
                type="button"
                class="rounded-md p-0.5 text-muted-foreground/50 transition-colors hover:bg-sidebar-accent hover:text-foreground"
                :aria-label="`Add to ${label.toLowerCase()}`"
                @click="emit('add')"
            >
                <Plus class="size-3.5" />
            </button>
        </div>
        <Collapse :open="open !== false">
            <div class="space-y-px">
                <slot />
            </div>
        </Collapse>
    </section>
</template>
