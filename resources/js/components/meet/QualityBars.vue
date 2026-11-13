<script setup lang="ts">
import { computed } from 'vue';
import { QUALITY_LABEL, qualityBars } from '@/lib/meet/quality';
import type { QualityLevel } from '@/lib/meet/types';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{ level: QualityLevel; size?: 'sm' | 'md'; class?: string }>(),
    { size: 'md', class: '' },
);

const lit = computed(() => qualityBars(props.level));
const tone = computed(
    () =>
        ({
            good: 'bg-success',
            fair: 'bg-warning',
            poor: 'bg-destructive',
            lost: 'bg-destructive',
            unknown: 'bg-current',
        })[props.level],
);
</script>

<template>
    <span
        role="img"
        :aria-label="QUALITY_LABEL[level]"
        :class="
            cn(
                'inline-flex items-end gap-[2px]',
                size === 'sm' ? 'h-3' : 'h-3.5',
                props.class,
            )
        "
    >
        <span
            v-for="bar in 3"
            :key="bar"
            :class="
                cn(
                    'w-[3px] rounded-full transition-colors duration-300',
                    bar <= lit ? tone : 'bg-current opacity-25',
                    level === 'unknown' && 'animate-pulse',
                )
            "
            :style="{ height: `${40 + bar * 20}%` }"
        />
    </span>
</template>
