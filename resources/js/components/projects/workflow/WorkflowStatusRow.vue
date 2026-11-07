<script setup lang="ts">
import {
    ArrowDown,
    ArrowUp,
    Check,
    GripVertical,
    Pencil,
    Trash2,
} from '@lucide/vue';
import { nextTick, ref } from 'vue';
import StatusIcon from '@/components/projects/shared/StatusIcon.vue';
import ColorPicker from '@/components/settings/ColorPicker.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { WorkflowStatus } from '@/types';

const props = defineProps<{
    status: WorkflowStatus;
    canManage: boolean;
    canDelete: boolean;
    isFirst: boolean;
    isLast: boolean;
}>();
const emit = defineEmits<{
    rename: [name: string];
    recolor: [color: string];
    makeDefault: [];
    move: [direction: -1 | 1];
    remove: [];
}>();

const editing = ref(false);
const draft = ref(props.status.name);
const input = ref<HTMLInputElement | null>(null);

const startEdit = async () => {
    if (!props.canManage) {
        return;
    }

    draft.value = props.status.name;
    editing.value = true;
    await nextTick();
    input.value?.focus();
    input.value?.select();
};

const commit = () => {
    editing.value = false;
    const name = draft.value.trim();

    if (name && name !== props.status.name) {
        emit('rename', name);
    }
};
</script>

<template>
    <div
        :class="
            cn(
                'group/status flex h-9 items-center gap-2 rounded-lg px-2 text-[13px] hover:bg-accent/60',
                status.is_default && 'bg-brand-soft/30',
            )
        "
    >
        <GripVertical
            v-if="canManage"
            class="drag-handle size-3.5 shrink-0 cursor-grab text-muted-foreground/40 opacity-0 transition-opacity group-hover/status:opacity-100"
        />
        <Popover v-if="canManage">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="flex size-6 items-center justify-center rounded-md hover:bg-accent"
                    aria-label="Change colour"
                >
                    <StatusIcon :status="status" :size="15" />
                </button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-3" align="start">
                <ColorPicker
                    :model-value="status.color"
                    @update:model-value="(color) => emit('recolor', color)"
                />
            </PopoverContent>
        </Popover>
        <StatusIcon v-else :status="status" :size="15" />

        <input
            v-if="editing"
            ref="input"
            v-model="draft"
            type="text"
            class="h-7 min-w-0 flex-1 rounded-md border bg-background px-2 text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
            @keydown.enter.prevent="commit"
            @keydown.escape.prevent="editing = false"
            @blur="commit"
        />
        <button
            v-else
            type="button"
            class="flex min-w-0 flex-1 items-center gap-1.5 text-left"
            :disabled="!canManage"
            @dblclick="startEdit"
        >
            <span class="truncate">{{ status.name }}</span>
            <Pencil
                v-if="canManage"
                class="size-3 text-muted-foreground/0 transition-colors group-hover/status:text-muted-foreground/60"
                @click.stop="startEdit"
            />
        </button>

        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    role="radio"
                    :aria-checked="status.is_default"
                    :disabled="!canManage || status.is_default"
                    :class="
                        cn(
                            'flex h-6 items-center gap-1 rounded-full px-2 text-[11px] transition-colors',
                            status.is_default
                                ? 'bg-brand-soft text-brand-soft-foreground'
                                : 'text-muted-foreground/0 group-hover/status:text-muted-foreground hover:bg-accent hover:text-foreground',
                        )
                    "
                    @click="emit('makeDefault')"
                >
                    <Check v-if="status.is_default" class="size-3" />
                    Default
                </button>
            </TooltipTrigger>
            <TooltipContent>{{
                status.is_default
                    ? 'New items start here'
                    : 'Make this the default status'
            }}</TooltipContent>
        </Tooltip>

        <template v-if="canManage">
            <Button
                variant="ghost"
                size="icon"
                class="size-6 text-muted-foreground/0 group-hover/status:text-muted-foreground"
                :disabled="isFirst"
                aria-label="Move up"
                @click="emit('move', -1)"
                ><ArrowUp class="size-3.5"
            /></Button>
            <Button
                variant="ghost"
                size="icon"
                class="size-6 text-muted-foreground/0 group-hover/status:text-muted-foreground"
                :disabled="isLast"
                aria-label="Move down"
                @click="emit('move', 1)"
                ><ArrowDown class="size-3.5"
            /></Button>
            <Button
                variant="ghost"
                size="icon"
                class="size-6 text-muted-foreground/0 group-hover/status:text-muted-foreground hover:text-destructive"
                :disabled="!canDelete"
                aria-label="Delete status"
                @click="emit('remove')"
                ><Trash2 class="size-3.5"
            /></Button>
        </template>
    </div>
</template>
