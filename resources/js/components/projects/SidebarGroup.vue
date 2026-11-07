<script setup lang="ts">
import { ChevronRight, Plus } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import Collapse from '@/components/Collapse.vue';
import { cn } from '@/lib/utils';

/**
 * A collapsible section of the projects sidebar. Open state is owned by the parent so it can be
 * persisted; the header can carry an "all" link and an add button.
 */
defineProps<{
    label: string;
    open: boolean;
    count?: number | null;
    allHref?: string | null;
    canAdd?: boolean;
}>();

const emit = defineEmits<{ toggle: []; add: [] }>();
</script>

<template>
    <section class="mt-4">
        <div class="group/head flex items-center gap-1 px-1 pb-1">
            <button
                type="button"
                class="flex min-w-0 flex-1 items-center gap-1 rounded-md px-1 py-0.5 text-left text-[10.5px] font-semibold tracking-[0.08em] text-muted-foreground/70 uppercase transition-colors hover:text-sidebar-foreground"
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
            <Link
                v-if="allHref"
                :href="allHref"
                class="rounded-md px-1 text-[10.5px] text-muted-foreground/0 transition-colors group-hover/head:text-muted-foreground/70 hover:text-foreground"
                >All</Link
            >
            <button
                v-if="canAdd"
                type="button"
                class="rounded-md p-0.5 text-muted-foreground/0 transition-colors group-hover/head:text-muted-foreground/70 hover:bg-sidebar-accent hover:text-foreground"
                :aria-label="`Add to ${label}`"
                @click="emit('add')"
            >
                <Plus class="size-3.5" />
            </button>
        </div>
        <Collapse :open="open">
            <div class="space-y-px">
                <slot />
            </div>
        </Collapse>
    </section>
</template>
