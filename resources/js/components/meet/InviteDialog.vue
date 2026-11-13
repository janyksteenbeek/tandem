<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { HttpError } from '@/lib/http';

/**
 * Invite people: copy the link, or send it by email. People invited by email skip the
 * waiting room.
 */
const props = defineProps<{
    open: boolean;
    joinUrl: string;
    canEmail: boolean;
    send: (emails: string[], note: string | null) => Promise<number>;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    copy: [];
    sent: [count: number];
}>();

const addresses = ref('');
const note = ref('');
const sending = ref(false);
const error = ref<string | null>(null);
const copied = ref(false);

const emails = computed(() =>
    addresses.value
        .split(/[\s,;]+/)
        .map((value) => value.trim())
        .filter((value) => value !== ''),
);

const invalid = computed(() =>
    emails.value.filter((email) => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)),
);

watch(
    () => props.open,
    (open) => {
        if (open) {
            error.value = null;
            copied.value = false;
        }
    },
);

const copy = () => {
    emit('copy');
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
};

const submit = async () => {
    if (emails.value.length === 0 || invalid.value.length > 0) {
        error.value =
            emails.value.length === 0
                ? 'Enter at least one email address.'
                : `Check this address: ${invalid.value[0]}`;

        return;
    }

    sending.value = true;
    error.value = null;

    try {
        const count = await props.send(emails.value, note.value.trim() || null);

        addresses.value = '';
        note.value = '';
        emit('sent', count);
        emit('update:open', false);
    } catch (caught) {
        error.value =
            caught instanceof HttpError
                ? (Object.values(caught.errors)[0]?.[0] ?? caught.message)
                : 'The invitations could not be sent.';
    } finally {
        sending.value = false;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Invite people</DialogTitle>
                <DialogDescription>
                    Anyone with the link can ask to join. No account or install
                    needed.
                </DialogDescription>
            </DialogHeader>

            <div class="flex items-center gap-2">
                <Input
                    :model-value="joinUrl"
                    readonly
                    class="h-10 rounded-xl font-mono text-xs"
                    aria-label="Meeting link"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <Button
                    variant="outline"
                    class="h-10 shrink-0 rounded-xl"
                    @click="copy"
                >
                    <Check v-if="copied" class="text-success" />
                    <Copy v-else />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>

            <form
                v-if="canEmail"
                class="flex flex-col gap-3 border-t pt-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-1.5">
                    <Label for="invite-emails">Send the link by email</Label>
                    <Input
                        id="invite-emails"
                        v-model="addresses"
                        placeholder="ada@example.com, grace@example.com"
                        autocomplete="off"
                        class="h-10 rounded-xl"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="invite-note"
                        >Add a note
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></Label
                    >
                    <Textarea
                        id="invite-note"
                        v-model="note"
                        rows="2"
                        maxlength="500"
                        class="rounded-xl"
                    />
                </div>
                <p
                    v-if="error"
                    role="alert"
                    class="text-[13px] font-medium text-destructive"
                >
                    {{ error }}
                </p>
                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="sending"
                        class="rounded-xl bg-brand text-brand-foreground hover:bg-brand/90"
                    >
                        <Spinner v-if="sending" /> Send invitations
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
