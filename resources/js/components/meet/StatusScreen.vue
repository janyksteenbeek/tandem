<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

/**
 * A calm full-page message: waiting to be let in, the meeting ended, you left.
 */
withDefaults(
    defineProps<{
        icon: Component;
        title: string;
        tone?: 'neutral' | 'brand' | 'danger';
        pulse?: boolean;
    }>(),
    { tone: 'neutral', pulse: false },
);

const tones = {
    neutral: 'bg-foreground/[0.06] text-foreground',
    brand: 'bg-brand-soft text-brand-soft-foreground',
    danger: 'bg-destructive/10 text-destructive',
};
</script>

<template>
    <div
        class="mx-auto flex w-full max-w-md animate-rise flex-col items-center px-6 text-center"
        role="status"
    >
        <span class="relative mb-6 flex size-16 items-center justify-center">
            <span
                v-if="pulse"
                class="absolute inset-0 animate-ping rounded-full bg-brand/20 [animation-duration:2.4s]"
            />
            <span
                :class="
                    cn(
                        'relative flex size-16 items-center justify-center rounded-full',
                        tones[tone],
                    )
                "
            >
                <component :is="icon" class="size-7" :stroke-width="1.75" />
            </span>
        </span>
        <h1 class="font-display text-2xl text-balance">{{ title }}</h1>
        <div
            class="mt-2 text-[15px] leading-relaxed text-pretty text-muted-foreground"
        >
            <slot />
        </div>
        <div
            v-if="$slots.actions"
            class="mt-7 flex flex-wrap items-center justify-center gap-2"
        >
            <slot name="actions" />
        </div>
    </div>
</template>
