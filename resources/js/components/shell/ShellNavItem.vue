<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import { cn } from '@/lib/utils';

/**
 * One row in a shell column: icon, label and whatever the trailing slot wants to say.
 */
defineProps<{
    href: NonNullable<InertiaLinkProps['href']>;
    icon: LucideIcon;
    active?: boolean;
}>();
</script>

<template>
    <Link
        :href="href"
        :class="
            cn(
                'group flex items-center gap-2 rounded-lg px-2 py-1.5 text-[13px] text-sidebar-foreground/90 transition-colors hover:bg-sidebar-accent',
                active &&
                    'vt-sidebar-active bg-brand-soft/60 font-medium text-brand-soft-foreground ring-1 ring-brand/10 ring-inset hover:bg-brand-soft/80',
            )
        "
        :aria-current="active ? 'page' : undefined"
    >
        <component
            :is="icon"
            :class="
                cn(
                    'size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-sidebar-foreground',
                    active && 'text-brand',
                )
            "
        />
        <span class="flex-1 truncate"><slot /></span>
        <slot name="trailing" />
    </Link>
</template>
