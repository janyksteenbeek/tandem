<script setup lang="ts">
import { TONE_CLASS, TONE_DOT } from '@/lib/calls/format';
import type { StatusMeta } from '@/lib/calls/format';
import { cn } from '@/lib/utils';

/**
 * A small coloured status label. `pulse` animates the dot for things happening right now
 * (a live meeting, a running recording, an export being built).
 */
withDefaults(defineProps<{ meta: StatusMeta; pulse?: boolean }>(), {
    pulse: false,
});
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex h-5 shrink-0 items-center gap-1.5 rounded-full px-2 text-[11px] font-medium whitespace-nowrap',
                TONE_CLASS[meta.tone],
            )
        "
    >
        <span class="relative flex size-1.5">
            <span
                v-if="pulse"
                :class="
                    cn(
                        'absolute inline-flex size-full animate-ping rounded-full opacity-60 motion-reduce:hidden',
                        TONE_DOT[meta.tone],
                    )
                "
            />
            <span
                :class="
                    cn(
                        'relative inline-flex size-1.5 rounded-full',
                        TONE_DOT[meta.tone],
                    )
                "
            />
        </span>
        {{ meta.label }}
    </span>
</template>
