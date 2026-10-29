<script setup lang="ts">
import { Check, Flag } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { PRIORITY_META } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type { ConversationPriority } from '@/types';

defineProps<{ priority: ConversationPriority; disabled?: boolean }>();
const emit = defineEmits<{ change: [priority: ConversationPriority] }>();

const choices: ConversationPriority[] = [
    'urgent',
    'high',
    'normal',
    'low',
    'none',
];
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="size-8"
                :disabled="disabled"
                :aria-label="`Priority: ${PRIORITY_META[priority].label}`"
            >
                <Flag
                    :class="
                        cn(
                            'size-4',
                            priority !== 'none'
                                ? PRIORITY_META[priority].className
                                : 'text-muted-foreground',
                            priority !== 'none' && 'fill-current',
                        )
                    "
                />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-40">
            <DropdownMenuItem
                v-for="choice in choices"
                :key="choice"
                class="gap-2"
                @click="emit('change', choice)"
            >
                <Flag
                    :class="
                        cn(
                            'size-3.5',
                            PRIORITY_META[choice].className,
                            choice !== 'none' && 'fill-current',
                        )
                    "
                />
                {{ PRIORITY_META[choice].label }}
                <Check
                    v-if="choice === priority"
                    class="ml-auto size-3.5 text-brand"
                />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
