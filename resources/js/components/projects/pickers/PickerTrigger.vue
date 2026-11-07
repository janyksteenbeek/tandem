<script setup lang="ts">
import { cn } from '@/lib/utils';

/**
 * The borderless chip pickers render as: in rows it is a bare icon, in the properties panel a
 * label with an icon. `empty` renders it dashed/muted to invite a value.
 */
withDefaults(
    defineProps<{
        empty?: boolean;
        compact?: boolean;
        disabled?: boolean;
        class?: string;
        label?: string;
    }>(),
    {
        empty: false,
        compact: false,
        disabled: false,
    },
);
</script>

<template>
    <button
        type="button"
        :disabled="disabled"
        :aria-label="label"
        :class="
            cn(
                'inline-flex h-7 max-w-full items-center gap-1.5 rounded-md text-[12.5px] transition-colors hover:bg-accent disabled:pointer-events-none disabled:opacity-60',
                compact ? 'px-1' : 'px-1.5',
                empty ? 'text-muted-foreground' : 'text-foreground',
                $props.class,
            )
        "
    >
        <slot />
    </button>
</template>
