<script setup lang="ts">
import { ArrowRightLeft, Share2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { ShareTarget } from '@/types';

const props = defineProps<{ targets: ShareTarget[]; canMove: boolean; originIsPersonal: boolean; busy?: boolean }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ confirm: [inbox: string, mode: 'share' | 'transfer', note: string] }>();

const mode = ref<'share' | 'transfer'>('share');
const target = ref<string | null>(null);
const note = ref('');

watch(open, (value) => {
    if (value) {
        target.value = props.targets[0]?.uuid ?? null;
        note.value = '';
        mode.value = 'share';
    }
});

const explain = computed(() =>
    mode.value === 'share'
        ? 'The conversation stays in its current inbox and also appears in the team inbox. Teammates can read, comment, assign and reply.'
        : 'The team inbox becomes the owner. It leaves the current list (you keep a link to it if it came from your personal mail).',
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-lg">
            <DialogHeader>
                <DialogTitle class="font-display text-2xl">Bring the team in</DialogTitle>
                <DialogDescription>
                    Nothing changes in {{ originIsPersonal ? 'your mailbox at the provider' : 'the connected mailbox' }}. This only changes who can see the conversation inside Tandem.
                </DialogDescription>
            </DialogHeader>

            <div class="grid grid-cols-2 gap-2">
                <button type="button" :class="cn('rounded-xl border p-3 text-left transition', mode === 'share' ? 'border-brand bg-brand-soft/50' : 'hover:bg-accent')" @click="mode = 'share'">
                    <Share2 class="mb-1.5 size-4 text-brand" />
                    <p class="text-sm font-semibold">Share</p>
                    <p class="text-xs text-muted-foreground">Keep it here, show it there too.</p>
                </button>
                <button type="button" :disabled="!canMove" :class="cn('rounded-xl border p-3 text-left transition disabled:opacity-50', mode === 'transfer' ? 'border-brand bg-brand-soft/50' : 'hover:bg-accent')" @click="mode = 'transfer'">
                    <ArrowRightLeft class="mb-1.5 size-4 text-brand" />
                    <p class="text-sm font-semibold">Transfer</p>
                    <p class="text-xs text-muted-foreground">Hand it over to the team inbox.</p>
                </button>
            </div>

            <p class="text-xs text-muted-foreground">{{ explain }}</p>

            <div class="max-h-56 space-y-1 overflow-y-auto scrollbar-thin">
                <p v-if="targets.length === 0" class="rounded-lg border border-dashed p-3 text-xs text-muted-foreground">You do not have access to any shared inbox yet.</p>
                <button
                    v-for="inbox in targets"
                    :key="inbox.uuid"
                    type="button"
                    :class="cn('flex w-full items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition', target === inbox.uuid ? 'border-brand bg-brand-soft/40' : 'hover:bg-accent')"
                    @click="target = inbox.uuid"
                >
                    <InboxDot :color="inbox.color" />
                    <span class="flex-1 truncate">{{ inbox.name }}</span>
                    <span class="truncate text-xs text-muted-foreground">{{ inbox.email }}</span>
                </button>
            </div>

            <Textarea v-if="mode === 'share'" v-model="note" placeholder="Add a note for the team (optional)… e.g. “Can someone from billing take this?”" rows="2" class="text-sm" />

            <DialogFooter>
                <Button variant="ghost" @click="open = false">Cancel</Button>
                <Button class="bg-brand text-brand-foreground hover:bg-brand/90" :disabled="!target || busy" @click="emit('confirm', target!, mode, note)">
                    {{ mode === 'share' ? 'Share with team' : 'Transfer conversation' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
