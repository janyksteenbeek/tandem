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

/**
 * A small confirmation dialog. The default slot sits between the description and the buttons
 * for extra inputs (a reason, a checklist).
 */
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
        description: undefined,
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
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="description">{{
                    description
                }}</DialogDescription>
                <DialogDescription v-else class="sr-only">{{
                    title
                }}</DialogDescription>
            </DialogHeader>
            <slot />
            <DialogFooter>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="busy"
                    @click="open = false"
                    >{{ cancelLabel }}</Button
                >
                <Button
                    size="sm"
                    :variant="destructive ? 'destructive' : 'default'"
                    :disabled="busy"
                    @click="emit('confirm')"
                    >{{ confirmLabel }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
