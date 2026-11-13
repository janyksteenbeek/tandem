<script setup lang="ts">
import JoinMeetingField from '@/components/calls/JoinMeetingField.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Join a meeting</DialogTitle>
                <DialogDescription>
                    Use the code from the invitation (it looks like
                    <span class="font-mono text-foreground">abc-defg-hij</span>)
                    or paste the whole link.
                </DialogDescription>
            </DialogHeader>
            <JoinMeetingField v-if="open" autofocus />
        </DialogContent>
    </Dialog>
</template>
