<script setup lang="ts">
import ReactionPicker from '@/components/projects/comments/ReactionPicker.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { Reaction } from '@/types';

defineProps<{ reactions: Reaction[]; canReact: boolean }>();
const emit = defineEmits<{ toggle: [emoji: string, on: boolean] }>();
</script>

<template>
    <div
        v-if="reactions.length > 0 || canReact"
        class="mt-1.5 flex flex-wrap items-center gap-1"
    >
        <Tooltip v-for="reaction in reactions" :key="reaction.emoji">
            <TooltipTrigger as-child>
                <button
                    type="button"
                    :disabled="!canReact"
                    :class="
                        cn(
                            'flex h-6 items-center gap-1 rounded-full border px-1.5 text-[11px] tabular-nums transition-colors',
                            reaction.mine
                                ? 'border-brand bg-brand-soft/60 text-brand-soft-foreground'
                                : 'hover:bg-accent',
                        )
                    "
                    :aria-pressed="reaction.mine"
                    @click="emit('toggle', reaction.emoji, !reaction.mine)"
                >
                    <span>{{ reaction.emoji }}</span>
                    <span>{{ reaction.count }}</span>
                </button>
            </TooltipTrigger>
            <TooltipContent>{{
                reaction.users.map((u) => u.name ?? 'Someone').join(', ')
            }}</TooltipContent>
        </Tooltip>
        <ReactionPicker
            v-if="canReact"
            @pick="
                (emoji) =>
                    emit(
                        'toggle',
                        emoji,
                        !reactions.find((r) => r.emoji === emoji)?.mine,
                    )
            "
        />
    </div>
</template>
