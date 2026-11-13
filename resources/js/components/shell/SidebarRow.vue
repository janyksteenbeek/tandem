<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import { ChevronRight, Lock } from '@lucide/vue';
import EntityGlyph from '@/components/shell/EntityGlyph.vue';
import InboxDot from '@/components/shared/InboxDot.vue';
import { cn } from '@/lib/utils';

/**
 * One row of any sidebar: an entity tile, an icon or a coloured dot, a label, and whatever
 * trails it. Rows that can expand swap the leading mark for a chevron on hover; `depth`
 * indents nested rows. `heading` rows (a mailbox) only gain weight when open, never a fill.
 */
withDefaults(
    defineProps<{
        href: NonNullable<InertiaLinkProps['href']>;
        /** A team, product, project, module or view: renders its tinted tile. */
        entity?: {
            name: string;
            color?: string | null;
            icon?: string | null;
        } | null;
        icon?: LucideIcon | null;
        color?: string | null;
        identifier?: string | null;
        active?: boolean;
        depth?: 0 | 1 | 2;
        expandable?: boolean;
        expanded?: boolean;
        trailing?: string | number | null;
        variant?: 'default' | 'heading';
        locked?: boolean;
        title?: string | null;
    }>(),
    {
        entity: null,
        icon: null,
        color: null,
        identifier: null,
        active: false,
        depth: 0,
        expandable: false,
        expanded: false,
        trailing: null,
        variant: 'default',
        locked: false,
        title: null,
    },
);

const emit = defineEmits<{ toggle: [] }>();
</script>

<template>
    <div
        :class="
            cn(
                'group/row flex items-center',
                depth === 1 && 'pl-4',
                depth === 2 && 'pl-8',
            )
        "
    >
        <Link
            :href="href"
            :title="title ?? undefined"
            :class="
                cn(
                    'flex h-7 min-w-0 flex-1 items-center gap-2 rounded-md px-2 text-[13px] text-sidebar-foreground/85 transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground',
                    variant === 'default' &&
                        active &&
                        'vt-sidebar-active bg-brand-soft/70 font-medium text-brand-soft-foreground ring-1 ring-brand/10 ring-inset hover:bg-brand-soft hover:text-brand-soft-foreground',
                    variant === 'heading' &&
                        active &&
                        'font-medium text-sidebar-foreground',
                )
            "
            :aria-current="active ? 'page' : undefined"
        >
            <span
                v-if="entity || icon || color || expandable"
                class="relative flex size-[18px] shrink-0 items-center justify-center"
            >
                <span
                    :class="
                        cn(
                            'flex items-center justify-center transition-opacity',
                            expandable && 'group-hover/row:opacity-0',
                        )
                    "
                >
                    <EntityGlyph
                        v-if="entity"
                        :name="entity.name"
                        :color="entity.color"
                        :icon="entity.icon"
                        :fallback="icon"
                        :class="
                            cn(
                                'transition-transform group-hover/row:scale-105',
                                active && 'shadow-xs',
                            )
                        "
                    />
                    <component
                        :is="icon"
                        v-else-if="icon"
                        :class="
                            cn(
                                'size-4 text-muted-foreground transition-colors group-hover/row:text-sidebar-foreground',
                                active && variant === 'default' && 'text-brand',
                            )
                        "
                    />
                    <InboxDot
                        v-else-if="color || !expandable"
                        :color="color ?? 'slate'"
                        size="sm"
                    />
                </span>
                <button
                    v-if="expandable"
                    type="button"
                    class="absolute inset-0 flex items-center justify-center rounded text-muted-foreground opacity-0 transition-opacity group-hover/row:opacity-100 hover:bg-sidebar-accent hover:text-foreground focus-visible:opacity-100"
                    :aria-label="expanded ? 'Collapse' : 'Expand'"
                    :aria-expanded="expanded"
                    @click.prevent.stop="emit('toggle')"
                >
                    <ChevronRight
                        :class="
                            cn(
                                'size-3.5 transition-transform',
                                expanded && 'rotate-90',
                            )
                        "
                    />
                </button>
            </span>
            <span
                v-if="identifier"
                class="shrink-0 font-mono text-[11px] text-muted-foreground"
                >{{ identifier }}</span
            >
            <span class="min-w-0 flex-1 truncate"><slot /></span>
            <Lock
                v-if="locked"
                class="size-3 shrink-0 text-muted-foreground/70"
                aria-label="Private"
            />
            <slot name="trailing">
                <span
                    v-if="
                        trailing !== null &&
                        trailing !== undefined &&
                        trailing !== ''
                    "
                    class="text-[11px] text-muted-foreground tabular-nums"
                    >{{ trailing }}</span
                >
            </slot>
        </Link>
    </div>
</template>
