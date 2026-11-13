<script setup lang="ts">
import type { Component } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * A button on the meeting toolbar. `tone` says what state it shows:
 *  - `default`: a neutral action
 *  - `off`: a muted microphone or a stopped camera (deliberately loud)
 *  - `active`: a panel that is open or a share that is running
 *  - `danger`: leaving
 */
withDefaults(
    defineProps<{
        label: string;
        icon: Component;
        tone?: 'default' | 'off' | 'active' | 'danger' | 'recording';
        pressed?: boolean | null;
        shortcut?: string;
        badge?: number | null;
        busy?: boolean;
        disabled?: boolean;
        class?: string;
    }>(),
    {
        tone: 'default',
        pressed: null,
        shortcut: undefined,
        badge: null,
        busy: false,
        disabled: false,
        class: '',
    },
);

defineEmits<{ click: [event: MouseEvent] }>();

// Attributes (test ids, aria) belong on the button, not on the renderless tooltip around it.
defineOptions({ inheritAttrs: false });

const tones = {
    default:
        'bg-foreground/[0.06] text-foreground hover:bg-foreground/[0.11] dark:bg-white/[0.08] dark:hover:bg-white/[0.14]',
    off: 'bg-destructive text-white hover:bg-destructive/90 shadow-[0_4px_14px_-4px] shadow-destructive/50',
    active: 'bg-brand-soft text-brand-soft-foreground hover:bg-brand-soft/80 ring-1 ring-brand/25',
    danger: 'bg-destructive text-white hover:bg-destructive/90',
    recording:
        'bg-destructive/12 text-destructive hover:bg-destructive/20 ring-1 ring-destructive/30',
};
</script>

<template>
    <Tooltip>
        <TooltipTrigger as-child>
            <button
                v-bind="$attrs"
                type="button"
                :aria-label="label"
                :aria-pressed="pressed === null ? undefined : pressed"
                :disabled="disabled"
                :class="
                    cn(
                        'relative flex size-11 shrink-0 items-center justify-center rounded-xl transition-[background-color,color,transform,box-shadow] duration-150 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/60 active:scale-[0.94] disabled:pointer-events-none disabled:opacity-40',
                        tones[tone],
                        busy && 'cursor-progress',
                        $props.class,
                    )
                "
                @click="$emit('click', $event)"
            >
                <component
                    :is="icon"
                    :class="cn('size-5', busy && 'animate-pulse')"
                    :stroke-width="2"
                />
                <span
                    v-if="badge"
                    class="absolute -top-1 -right-1 flex h-[18px] min-w-[18px] animate-pop items-center justify-center rounded-full bg-brand px-1 text-[10px] font-semibold text-brand-foreground tabular-nums ring-2 ring-card"
                >
                    {{ badge > 99 ? '99+' : badge }}
                </span>
            </button>
        </TooltipTrigger>
        <TooltipContent side="top" :side-offset="8">
            {{ label }}
            <kbd
                v-if="shortcut"
                class="ml-1.5 rounded border border-current/25 px-1 font-sans text-[10px] uppercase opacity-80"
                >{{ shortcut }}</kbd
            >
        </TooltipContent>
    </Tooltip>
</template>
