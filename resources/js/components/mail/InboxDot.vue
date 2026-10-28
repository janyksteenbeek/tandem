<script setup lang="ts">
import { chipClass } from '@/lib/mail';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{ color?: string | null; size?: 'sm' | 'md'; class?: string }>(),
    { size: 'md', color: 'slate' },
);
</script>

<template>
    <span
        :class="
            cn(
                'inline-block shrink-0 rounded-full bg-(--chip)',
                size === 'sm' ? 'size-2' : 'size-2.5',
                chipClass(props.color),
                props.class,
            )
        "
    />
</template>
