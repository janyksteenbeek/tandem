<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const open = defineModel<boolean>('open', { default: false });

const groups = [
    {
        title: 'Navigate',
        items: [
            ['j / k', 'Next / previous conversation'],
            ['↵', 'Open selected conversation'],
            ['esc', 'Back to the list'],
            ['g i', 'Go to inbox'],
            ['g s', 'Go to starred'],
            ['g t', 'Go to sent'],
            ['⌘ K', 'Command palette & search'],
            ['/', 'Focus search'],
            ['?', 'This cheat sheet'],
        ],
    },
    {
        title: 'Act',
        items: [
            ['c', 'Compose'],
            ['r', 'Reply'],
            ['a', 'Reply all'],
            ['f', 'Forward'],
            ['m', 'Internal comment'],
            ['e', 'Archive'],
            ['#', 'Trash'],
            ['s', 'Star / unstar'],
            ['u', 'Mark unread'],
            ['h', 'Snooze until tomorrow 9:00'],
            ['d', 'Mark done (close)'],
            ['i', 'Assign to me'],
            ['x', 'Select conversation'],
            ['⌘ ↵', 'Send'],
        ],
    },
];
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-2xl">
            <DialogHeader>
                <DialogTitle class="font-display text-2xl"
                    >Keyboard shortcuts</DialogTitle
                >
                <DialogDescription
                    >Keep your hands on the keys. Tandem is built to be driven
                    without a mouse.</DialogDescription
                >
            </DialogHeader>
            <div class="grid gap-6 sm:grid-cols-2">
                <div v-for="group in groups" :key="group.title">
                    <p
                        class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ group.title }}
                    </p>
                    <dl class="space-y-1.5">
                        <div
                            v-for="[keys, label] in group.items"
                            :key="keys"
                            class="flex items-center justify-between gap-3 text-sm"
                        >
                            <dt class="text-muted-foreground">{{ label }}</dt>
                            <dd class="flex gap-1">
                                <kbd
                                    v-for="key in keys.split(' ')"
                                    :key="key"
                                    class="min-w-6 rounded-md border bg-muted px-1.5 py-0.5 text-center font-mono text-[11px] text-foreground"
                                    >{{ key }}</kbd
                                >
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
