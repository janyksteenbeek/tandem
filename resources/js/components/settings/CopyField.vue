<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        value: string;
        label?: string;
        toastMessage?: string;
        size?: 'sm' | 'md';
        class?: string;
    }>(),
    { size: 'md', toastMessage: 'Copied to clipboard.' },
);

const copied = ref(false);

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.value);
        copied.value = true;
        toast.success(props.toastMessage);
        setTimeout(() => (copied.value = false), 1600);
    } catch {
        toast.error('Could not copy. Select the text and copy it manually.');
    }
}
</script>

<template>
    <div :class="cn('min-w-0', props.class)">
        <p v-if="label" class="mb-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">{{ label }}</p>
        <div
            :class="
                cn(
                    'flex items-center gap-2 rounded-lg border bg-surface-sunken pl-3 pr-1 font-mono text-foreground',
                    size === 'sm' ? 'h-8 text-xs' : 'h-10 text-sm',
                )
            "
        >
            <span class="min-w-0 flex-1 truncate select-all" :title="value">{{ value }}</span>
            <Button type="button" variant="ghost" :size="size === 'sm' ? 'icon-xs' : 'icon-sm'" :aria-label="`Copy ${label ?? 'value'}`" @click="copy">
                <Check v-if="copied" class="text-success" />
                <Copy v-else />
            </Button>
        </div>
    </div>
</template>
