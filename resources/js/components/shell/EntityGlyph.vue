<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import { entityIcon, monogram } from '@/lib/entityIcons';
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';

/**
 * The face of a team, product, project, module or view: a rounded tile tinted with the
 * entity's colour, carrying its icon or, failing that, its monogram. Used wherever a
 * bare coloured dot would make every row look the same.
 */
const props = withDefaults(
    defineProps<{
        name: string;
        color?: string | null;
        icon?: string | null;
        fallback?: LucideIcon | null;
        size?: 'sm' | 'md' | 'lg';
        class?: string;
    }>(),
    { color: null, icon: null, fallback: null, size: 'sm' },
);

const resolved = computed(() => entityIcon(props.icon) ?? props.fallback);
const letters = computed(() => monogram(props.name));

const box = {
    sm: 'size-[18px] rounded-[5px] text-[8.5px]',
    md: 'size-6 rounded-md text-[10px]',
    lg: 'size-8 rounded-lg text-xs',
} as const;
const glyph = {
    sm: 'size-[11px]',
    md: 'size-3.5',
    lg: 'size-4',
} as const;
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex shrink-0 items-center justify-center font-semibold tracking-tight entity-glyph-surface select-none',
                box[size],
                chipClass(color),
                props.class,
            )
        "
        aria-hidden="true"
    >
        <component :is="resolved" v-if="resolved" :class="glyph[size]" />
        <template v-else>{{ letters }}</template>
    </span>
</template>
