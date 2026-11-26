<script setup lang="ts">
import { Ban } from '@lucide/vue';
import { VIDEO_EFFECTS } from '@/lib/meet/effects/catalog';
import type { VideoEffectId } from '@/lib/meet/types';
import { cn } from '@/lib/utils';

/**
 * The choice of camera effect: none, or one of the effects, each shown as its picture.
 */
const props = withDefaults(
    defineProps<{
        modelValue: VideoEffectId;
        disabled?: boolean;
        size?: 'sm' | 'md';
    }>(),
    { disabled: false, size: 'md' },
);

const emit = defineEmits<{ 'update:modelValue': [effect: VideoEffectId] }>();

const tileClass = (selected: boolean) =>
    cn(
        'relative shrink-0 overflow-hidden rounded-xl border transition-[border-color,box-shadow] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-60',
        props.size === 'sm' ? 'h-12 w-20' : 'h-20 w-32',
        selected
            ? 'border-brand shadow-[0_0_0_1.5px_var(--brand)]'
            : 'hover:border-foreground/30',
    );
</script>

<template>
    <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Effect">
        <button
            type="button"
            role="radio"
            :aria-checked="modelValue === 'none'"
            :disabled="disabled"
            :class="
                cn(
                    tileClass(modelValue === 'none'),
                    'flex flex-col items-center justify-center gap-1 bg-muted/60 text-muted-foreground',
                    modelValue === 'none' && 'text-foreground',
                )
            "
            data-testid="effect-none"
            @click="emit('update:modelValue', 'none')"
        >
            <Ban :class="size === 'sm' ? 'size-3.5' : 'size-4'" />
            <span class="text-xs font-medium">None</span>
        </button>
        <button
            v-for="effect in VIDEO_EFFECTS"
            :key="effect.id"
            type="button"
            role="radio"
            :aria-checked="modelValue === effect.id"
            :disabled="disabled"
            :class="tileClass(modelValue === effect.id)"
            :data-testid="`effect-${effect.id}`"
            @click="emit('update:modelValue', effect.id)"
        >
            <img
                :src="effect.thumbnail"
                alt=""
                class="absolute inset-0 size-full object-cover"
                draggable="false"
            />
            <span
                class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-2 pt-4 pb-1 text-left text-xs font-semibold text-white"
                >{{ effect.label }}</span
            >
        </button>
    </div>
</template>
