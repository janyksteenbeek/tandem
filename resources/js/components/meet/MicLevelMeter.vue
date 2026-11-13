<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

/**
 * A row of segments that light up with the microphone level. `level` is 0 to 1.
 */
const props = withDefaults(
    defineProps<{
        level: number;
        segments?: number;
        muted?: boolean;
        class?: string;
    }>(),
    { segments: 14, muted: false, class: '' },
);

const lit = computed(() =>
    props.muted ? 0 : Math.round(props.level * props.segments),
);
</script>

<template>
    <div
        role="meter"
        aria-label="Microphone level"
        :aria-valuenow="Math.round(level * 100)"
        aria-valuemin="0"
        aria-valuemax="100"
        :class="cn('flex h-2 items-stretch gap-[3px]', props.class)"
    >
        <span
            v-for="segment in segments"
            :key="segment"
            :class="
                cn(
                    'flex-1 rounded-full transition-colors duration-75',
                    segment <= lit
                        ? segment > segments * 0.85
                            ? 'bg-warning'
                            : 'bg-success'
                        : 'bg-foreground/10',
                )
            "
        />
    </div>
</template>
