<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        confirmLabel?: string;
        cancelLabel?: string;
        destructive?: boolean;
        busy?: boolean;
    }>(),
    {
        confirmLabel: 'Confirm',
        cancelLabel: 'Cancel',
        destructive: false,
        busy: false,
    },
);

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ confirm: [] }>();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle class="font-display text-2xl">{{
                    title
                }}</DialogTitle>
                <DialogDescription v-if="description">{{
                    description
                }}</DialogDescription>
            </DialogHeader>
            <slot />
            <DialogFooter class="gap-2">
                <Button type="button" variant="ghost" @click="open = false">{{
                    cancelLabel
                }}</Button>
                <Button
                    type="button"
                    :variant="destructive ? 'destructive' : 'default'"
                    :disabled="busy"
                    @click="emit('confirm')"
                >
                    {{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
