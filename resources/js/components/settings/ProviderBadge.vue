<script setup lang="ts">
import { Forward, Mail, Server } from '@lucide/vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { MailProvider } from '@/types';

const props = withDefaults(
    defineProps<{
        provider: MailProvider;
        label?: string;
        variant?: 'badge' | 'icon';
        size?: 'sm' | 'md' | 'lg';
        class?: string;
    }>(),
    {
        variant: 'badge',
        size: 'md',
    },
);

const META: Record<
    MailProvider,
    { label: string; icon: unknown; tone: string }
> = {
    gmail: { label: 'Gmail', icon: Mail, tone: 'chip-rose' },
    imap: { label: 'IMAP / SMTP', icon: Server, tone: 'chip-sky' },
    forwarding: { label: 'Forwarding', icon: Forward, tone: 'chip-teal' },
};

const meta = computed(() => META[props.provider] ?? META.imap);

const iconBox = {
    sm: 'size-7 rounded-lg [&>svg]:size-3.5',
    md: 'size-9 rounded-xl [&>svg]:size-4',
    lg: 'size-11 rounded-xl [&>svg]:size-5',
};
</script>

<template>
    <span
        v-if="variant === 'icon'"
        :class="
            cn(
                'inline-flex shrink-0 items-center justify-center bg-(--chip)/15 text-(--chip)',
                meta.tone,
                iconBox[size],
                props.class,
            )
        "
        :title="label ?? meta.label"
    >
        <component :is="meta.icon" />
    </span>
    <span
        v-else
        :class="
            cn(
                'inline-flex shrink-0 items-center gap-1 rounded-full border border-(--chip)/30 bg-(--chip)/10 px-2 py-0.5 text-[11px] font-medium text-(--chip) [&>svg]:size-3',
                meta.tone,
                props.class,
            )
        "
    >
        <component :is="meta.icon" />
        {{ label ?? meta.label }}
    </span>
</template>
