<script setup lang="ts">
import { Check, ChevronDown } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { STATUS_META } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type { ConversationStatus } from '@/types';

defineProps<{ status: ConversationStatus; disabled?: boolean }>();
const emit = defineEmits<{ change: [status: ConversationStatus] }>();

const choices: ConversationStatus[] = ['open', 'pending', 'closed'];
const labels: Record<ConversationStatus, string> = { open: 'Open', pending: 'Waiting', snoozed: 'Later', closed: 'Done' };
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="outline" size="sm" :disabled="disabled" class="h-8 gap-1.5 rounded-full px-2.5 text-xs font-medium">
                <span :class="cn('size-2 rounded-full', STATUS_META[status]?.dot)" />
                {{ labels[status] }}
                <ChevronDown class="size-3 text-muted-foreground" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-44">
            <DropdownMenuItem v-for="choice in choices" :key="choice" class="gap-2" @click="emit('change', choice)">
                <span :class="cn('size-2 rounded-full', STATUS_META[choice].dot)" /> {{ labels[choice] }}
                <Check v-if="choice === status" class="ml-auto size-3.5 text-brand" />
                <kbd v-else-if="choice === 'closed'" class="ml-auto rounded border px-1 text-[10px]">D</kbd>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
