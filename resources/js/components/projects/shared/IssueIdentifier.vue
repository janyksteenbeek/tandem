<script setup lang="ts">
import { cn } from '@/lib/utils';

defineProps<{ identifier: string; class?: string; muted?: boolean }>();
</script>

<template>
    <span
        :class="
            cn(
                'shrink-0 font-mono text-[11px] tracking-tight tabular-nums',
                muted === false ? 'text-foreground' : 'text-muted-foreground',
                $props.class,
            )
        "
        >{{ identifier }}</span
    >
</template>
