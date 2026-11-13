<script lang="ts">
export const EMOJI = [
    '👍',
    '👎',
    '❤️',
    '🎉',
    '👀',
    '🚀',
    '😄',
    '😕',
    '🙏',
    '✅',
    '🔥',
    '💯',
];
</script>

<script setup lang="ts">
import { SmilePlus } from '@lucide/vue';
import { ref } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

const emit = defineEmits<{ pick: [emoji: string] }>();
const open = ref(false);

const pick = (emoji: string) => {
    emit('pick', emoji);
    open.value = false;
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="flex h-6 items-center justify-center rounded-full border px-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                aria-label="Add reaction"
            >
                <SmilePlus class="size-3.5" />
            </button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-1.5" align="start">
            <div class="grid grid-cols-6 gap-0.5">
                <button
                    v-for="emoji in EMOJI"
                    :key="emoji"
                    type="button"
                    class="flex size-8 items-center justify-center rounded-md text-lg transition-transform hover:scale-110 hover:bg-accent"
                    :aria-label="emoji"
                    @click="pick(emoji)"
                >
                    {{ emoji }}
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
