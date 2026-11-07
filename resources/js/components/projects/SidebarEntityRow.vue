<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import { ChevronRight } from '@lucide/vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import { cn } from '@/lib/utils';

/**
 * A product, project, team or view in the sidebar: coloured dot or icon, name, optional trailing
 * text, and an optional chevron when the row can expand into its tabs.
 */
defineProps<{
    href: string;
    name: string;
    color?: string | null;
    icon?: LucideIcon;
    active?: boolean;
    expandable?: boolean;
    expanded?: boolean;
    trailing?: string | number | null;
    identifier?: string | null;
}>();

const emit = defineEmits<{ toggle: [] }>();
</script>

<template>
    <div class="group/row flex items-center">
        <Link
            :href="href"
            :class="
                cn(
                    'flex h-7 min-w-0 flex-1 items-center gap-2 rounded-md px-2 text-[12.5px] text-sidebar-foreground/80 transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground',
                    active &&
                        'vt-sidebar-active bg-brand-soft/70 font-medium text-brand-soft-foreground ring-1 ring-brand/10 ring-inset hover:bg-brand-soft hover:text-brand-soft-foreground',
                )
            "
            :aria-current="active ? 'page' : undefined"
        >
            <span class="flex size-4 shrink-0 items-center justify-center">
                <component
                    :is="icon"
                    v-if="icon"
                    :class="
                        cn(
                            'size-3.5 text-muted-foreground/80',
                            active && 'text-brand',
                        )
                    "
                />
                <InboxDot v-else :color="color ?? 'slate'" size="sm" />
            </span>
            <span
                v-if="identifier"
                class="shrink-0 font-mono text-[11px] text-muted-foreground"
                >{{ identifier }}</span
            >
            <span class="flex-1 truncate">{{ name }}</span>
            <span
                v-if="
                    trailing !== null &&
                    trailing !== undefined &&
                    trailing !== ''
                "
                class="text-[11px] text-muted-foreground tabular-nums"
                >{{ trailing }}</span
            >
        </Link>
        <button
            v-if="expandable"
            type="button"
            class="-ml-1 flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground/0 transition-colors group-hover/row:text-muted-foreground/60 hover:bg-sidebar-accent hover:text-foreground"
            :aria-label="expanded ? 'Collapse' : 'Expand'"
            @click.prevent="emit('toggle')"
        >
            <ChevronRight
                :class="
                    cn('size-3.5 transition-transform', expanded && 'rotate-90')
                "
            />
        </button>
    </div>
</template>
