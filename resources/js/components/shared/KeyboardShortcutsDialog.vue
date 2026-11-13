<script setup lang="ts">
import { computed, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useBranding } from '@/composables/useBranding';
import { formatKeys, getKeyboardRegistry } from '@/lib/keyboard';

/**
 * Lists whatever shortcuts are registered right now, so the sheet always matches the page:
 * mail shortcuts in mail, issue shortcuts on an issue, plus the ones that work everywhere.
 */
const branding = useBranding();
const open = defineModel<boolean>('open', { default: false });

const everywhere = [
    { keys: 'mod+k', label: 'Command palette & search' },
    { keys: 'shift+/', label: 'This cheat sheet' },
    { keys: 'escape', label: 'Close / back' },
];

const groups = computed(() => {
    // Re-read on every open; the registry is not reactive by design.
    void open.value;

    const live = getKeyboardRegistry()
        .visible()
        .map((group) => ({
            title: group.group === 'page' ? 'This page' : group.group,
            items: group.shortcuts.map((s) => ({
                keys: s.keys,
                label: s.description,
            })),
        }))
        .filter((group) => group.items.length > 0);

    return [...live, { title: 'Everywhere', items: everywhere }];
});

watch(open, () => void 0);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-2xl">
            <DialogHeader>
                <DialogTitle class="font-display text-2xl"
                    >Keyboard shortcuts</DialogTitle
                >
                <DialogDescription
                    >Keep your hands on the keys. {{ branding.name }} is built
                    to be driven without a mouse.</DialogDescription
                >
            </DialogHeader>
            <div
                class="grid scrollbar-thin max-h-[65vh] gap-6 overflow-y-auto pr-1 sm:grid-cols-2"
            >
                <div v-for="group in groups" :key="group.title">
                    <p
                        class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ group.title }}
                    </p>
                    <dl class="space-y-1.5">
                        <div
                            v-for="item in group.items"
                            :key="item.keys"
                            class="flex items-center justify-between gap-3 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                {{ item.label }}
                            </dt>
                            <dd class="flex gap-1">
                                <kbd
                                    v-for="key in formatKeys(item.keys).split(
                                        ' then ',
                                    )"
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
