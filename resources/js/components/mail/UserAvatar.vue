<script setup lang="ts">
import { computed } from 'vue';
import { avatarStyle, initialsOf } from '@/lib/mail';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        name?: string | null;
        email?: string | null;
        src?: string | null;
        initials?: string | null;
        size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl';
        class?: string;
    }>(),
    { size: 'md', name: null, email: null, src: null, initials: null },
);

const label = computed(() => props.name?.trim() || props.email || '?');
const text = computed(() => props.initials || initialsOf(label.value));
const style = computed(() => (props.src ? {} : avatarStyle(props.email || label.value)));

const sizes = {
    xs: 'size-5 text-[9px]',
    sm: 'size-6 text-[10px]',
    md: 'size-8 text-xs',
    lg: 'size-10 text-sm',
    xl: 'size-14 text-lg',
};
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex shrink-0 select-none items-center justify-center overflow-hidden rounded-full font-semibold tracking-wide',
                sizes[size],
                props.class,
            )
        "
        :style="style"
        :title="label"
    >
        <img v-if="src" :src="src" :alt="label" class="size-full object-cover" />
        <span v-else>{{ text }}</span>
    </span>
</template>
