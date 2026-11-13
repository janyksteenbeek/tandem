<script setup lang="ts">
import { Check, Copy, Link2Off } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { copyText } from '@/composables/calls/useCallStarter';
import { fullTime } from '@/lib/time';

/**
 * Creates and shows the secure recording link. With no link yet, opening the dialog makes
 * one. An existing link is never replaced without asking, because that would break it for
 * whoever already has it; its address was only shown when it was created.
 */
const props = defineProps<{
    link: { url: string; expires_at: string | null } | null;
    shared: boolean;
    busy: boolean;
}>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ create: []; revoke: [] }>();

const copied = ref(false);

watch(open, (value) => {
    copied.value = false;

    if (value && !props.link && !props.shared) {
        emit('create');
    }
});

const copy = async () => {
    if (
        props.link &&
        (await copyText(props.link.url, 'Recording link copied'))
    ) {
        copied.value = true;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Secure recording link</DialogTitle>
                <DialogDescription>
                    Anyone with this link can watch this recording without an
                    account. It gives access to nothing else.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="busy && !link"
                class="flex h-9 items-center gap-2 text-[13px] text-muted-foreground"
            >
                <Spinner class="size-4" /> Creating the link…
            </div>

            <template v-else-if="link">
                <div class="flex items-center gap-2">
                    <Input
                        :model-value="link.url"
                        readonly
                        class="h-9 font-mono text-[12px]"
                        aria-label="Recording link"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <Button
                        size="sm"
                        class="h-9 shrink-0 gap-1.5"
                        @click="copy"
                    >
                        <Check v-if="copied" class="size-3.5" />
                        <Copy v-else class="size-3.5" />
                        {{ copied ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
                <p class="text-[12px] leading-relaxed text-muted-foreground">
                    Copy it now: it is not shown again.
                    <template v-if="link.expires_at"
                        >It stops working on
                        {{ fullTime(link.expires_at) }}.</template
                    >
                    Creating a new link replaces this one.
                </p>
            </template>

            <div
                v-else-if="shared"
                class="rounded-lg bg-muted/50 px-3 py-2.5 text-[12.5px] leading-relaxed text-muted-foreground"
            >
                A link to this recording already exists. Its address was only
                shown when it was created. You can make a new one, which stops
                the old link from working.
                <Button
                    variant="outline"
                    size="sm"
                    class="mt-2 flex"
                    :disabled="busy"
                    @click="emit('create')"
                    >Create a new link</Button
                >
            </div>

            <div
                v-if="shared"
                class="flex items-center justify-between gap-3 border-t pt-3"
            >
                <p class="text-[12px] text-muted-foreground">
                    A link to this recording is active.
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    class="gap-1.5 text-destructive hover:text-destructive"
                    :disabled="busy"
                    @click="emit('revoke')"
                >
                    <Link2Off class="size-3.5" /> Revoke link
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
