<script setup lang="ts">
import { Check } from '@lucide/vue';
import { CHIP_COLORS, chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';

const model = defineModel<string>({ default: 'violet' });
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
                    chipClass(color),
                    model === color && 'ring-2 ring-(--chip)',
                )
            "
            @click="model = color"
        >
            <Check v-if="model === color" class="size-3.5 text-white drop-shadow" />
        </button>
    </div>
</template>
