<script setup lang="ts">
import { Check } from '@lucide/vue';
import { CHIP_COLORS } from '@/lib/mail';
import { cn } from '@/lib/utils';

const model = defineModel<string>({ default: 'violet' });

/**
 * Spelled out so Tailwind emits every chip utility; the palette is otherwise only referenced dynamically.
 */
const CHIP_CLASSES: Record<(typeof CHIP_COLORS)[number], string> = {
    violet: 'chip-violet',
    teal: 'chip-teal',
    amber: 'chip-amber',
    rose: 'chip-rose',
    sky: 'chip-sky',
    emerald: 'chip-emerald',
    slate: 'chip-slate',
    orange: 'chip-orange',
    pink: 'chip-pink',
    lime: 'chip-lime',
    plum: 'chip-plum',
};
</script>

<template>
    <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Colour">
        <button
            v-for="color in CHIP_COLORS"
            :key="color"
            type="button"
            role="radio"
            :aria-checked="model === color"
            :aria-label="color"
            :title="color"
            :class="
                cn(
                    'flex size-7 items-center justify-center rounded-full bg-(--chip) ring-offset-2 ring-offset-background transition-transform hover:scale-110 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    CHIP_CLASSES[color],
                    model === color && 'ring-2 ring-(--chip)',
                )
            "
            @click="model = color"
        >
            <Check
                v-if="model === color"
                class="size-3.5 text-white drop-shadow"
            />
        </button>
    </div>
</template>
