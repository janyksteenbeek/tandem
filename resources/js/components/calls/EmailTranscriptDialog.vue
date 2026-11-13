<script setup lang="ts">
import { Info, X } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { parseEmails, plural } from '@/lib/calls/format';
import type { Recipient } from '@/lib/calls/types';
import { api, reportError } from '@/lib/http';
import { email as emailRoute } from '@/routes/calls/transcript';
import type { Call } from '@/types';

/**
 * Email Meeting Chat: choose who gets the transcript and whether it carries links to the
 * shared files and the recording. Each recipient gets their own tracked email.
 */
const props = defineProps<{
    call: Call;
    suggested: { email: string; name: string }[];
    hasFiles: boolean;
    hasRecording: boolean;
}>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ sent: [] }>();

const recipients = ref<Recipient[]>([]);
const draft = ref('');
const includeFiles = ref(true);
const includeRecording = ref(true);
const invalid = ref<string | null>(null);
const sending = ref(false);
const inputId = useId();
const errorId = useId();

watch(open, (value) => {
    if (!value) {
        return;
    }

    recipients.value = props.suggested.map((person) => ({ ...person }));
    draft.value = '';
    invalid.value = null;
    includeFiles.value = props.hasFiles;
    includeRecording.value = props.hasRecording;
});

const others = computed(() =>
    props.suggested.filter(
        (person) =>
            !recipients.value.some(
                (recipient) => recipient.email === person.email.toLowerCase(),
            ),
    ),
);

const add = (person: Recipient) => {
    const email = person.email.toLowerCase();

    if (!recipients.value.some((recipient) => recipient.email === email)) {
        recipients.value.push({ email, name: person.name });
    }
};

const remove = (email: string) => {
    recipients.value = recipients.value.filter(
        (recipient) => recipient.email !== email,
    );
};

/** Turn whatever is typed or pasted into recipients; leave what is not an address in place. */
const commit = (): boolean => {
    const { emails, invalid: rejected } = parseEmails(draft.value);

    emails.forEach((email) => add({ email, name: null }));

    if (rejected.length > 0) {
        draft.value = rejected.join(' ');
        invalid.value = `“${rejected[0]}” is not an email address.`;

        return false;
    }

    if (draft.value.trim() !== '' && emails.length === 0) {
        invalid.value = `“${draft.value.trim()}” is not an email address.`;

        return false;
    }

    draft.value = '';
    invalid.value = null;

    return true;
};

const onKeydown = (event: KeyboardEvent) => {
    if (['Enter', ',', ';', ' ', 'Tab'].includes(event.key)) {
        if (draft.value.trim() === '') {
            if (event.key !== 'Tab' && event.key !== 'Enter') {
                event.preventDefault();
            }

            return;
        }

        if (event.key !== 'Tab') {
            event.preventDefault();
        }

        commit();
    } else if (
        event.key === 'Backspace' &&
        draft.value === '' &&
        recipients.value.length > 0
    ) {
        recipients.value.pop();
    }
};

const onPaste = (event: ClipboardEvent) => {
    const text = event.clipboardData?.getData('text') ?? '';

    if (/[\s,;]/.test(text.trim())) {
        event.preventDefault();
        draft.value = `${draft.value} ${text}`.trim();
        commit();
    }
};

const send = async () => {
    if (!commit() || recipients.value.length === 0) {
        if (recipients.value.length === 0 && !invalid.value) {
            invalid.value = 'Add at least one recipient.';
        }

        return;
    }

    sending.value = true;

    try {
        const data = await api.post<{ queued: number }>(
            emailRoute.url([props.call.organization.slug, props.call.uuid]),
            {
                recipients: recipients.value.map((recipient) => ({
                    email: recipient.email,
                    name: recipient.name,
                })),
                include_files: includeFiles.value,
                include_recording: includeRecording.value,
            },
        );

        toast.success(
            `Meeting chat is on its way to ${plural(data.queued, 'recipient')}`,
        );
        open.value = false;
        emit('sent');
    } catch (error) {
        reportError(error, 'The meeting chat could not be sent.');
    } finally {
        sending.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Email meeting chat</DialogTitle>
                <DialogDescription>
                    Sends the full chat of “{{ call.display_title }}” with the
                    date, duration and who took part.
                </DialogDescription>
            </DialogHeader>

            <form
                id="email-transcript"
                class="space-y-4"
                novalidate
                @submit.prevent="send"
            >
                <div class="space-y-1.5">
                    <Label :for="inputId" class="text-xs">To</Label>
                    <div
                        class="flex min-h-10 flex-wrap items-center gap-1.5 rounded-md border bg-background px-2 py-1.5 focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
                        :class="invalid && 'border-destructive'"
                        @click="
                            ($event.currentTarget as HTMLElement)
                                .querySelector('input')
                                ?.focus()
                        "
                    >
                        <span
                            v-for="recipient in recipients"
                            :key="recipient.email"
                            class="flex h-6 max-w-full items-center gap-1 rounded-full bg-brand-soft pr-1 pl-2 text-[12px] text-brand-soft-foreground"
                            :title="recipient.email"
                            data-test="recipient"
                        >
                            <span class="truncate">{{
                                recipient.name || recipient.email
                            }}</span>
                            <button
                                type="button"
                                class="flex size-4 shrink-0 items-center justify-center rounded-full hover:bg-brand/15"
                                :aria-label="`Remove ${recipient.name || recipient.email}`"
                                @click.stop="remove(recipient.email)"
                            >
                                <X class="size-3" />
                            </button>
                        </span>
                        <input
                            :id="inputId"
                            v-model="draft"
                            type="email"
                            multiple
                            autocomplete="off"
                            class="h-6 min-w-40 flex-1 bg-transparent text-[13px] outline-none placeholder:text-muted-foreground"
                            :placeholder="
                                recipients.length === 0
                                    ? 'name@example.com'
                                    : 'Add another address'
                            "
                            :aria-invalid="invalid !== null"
                            :aria-describedby="invalid ? errorId : undefined"
                            @keydown="onKeydown"
                            @paste="onPaste"
                            @blur="draft.trim() !== '' && commit()"
                            @input="invalid = null"
                        />
                    </div>
                    <p
                        v-if="invalid"
                        :id="errorId"
                        role="alert"
                        class="text-[12px] text-destructive"
                    >
                        {{ invalid }}
                    </p>
                    <p
                        v-if="others.length > 0"
                        class="flex flex-wrap items-center gap-1.5 text-[12px] text-muted-foreground"
                    >
                        Also in the meeting:
                        <button
                            v-for="person in others"
                            :key="person.email"
                            type="button"
                            class="rounded-full border border-dashed px-2 py-0.5 text-foreground hover:border-brand/50 hover:bg-brand-soft/50"
                            @click="add(person)"
                        >
                            + {{ person.name }}
                        </button>
                    </p>
                </div>

                <div class="divide-y rounded-lg border">
                    <label
                        class="flex items-start justify-between gap-4 px-3.5 py-2.5"
                        :class="
                            hasFiles ? 'cursor-pointer' : 'cursor-not-allowed'
                        "
                    >
                        <span>
                            <span class="block text-[13px] font-medium"
                                >Include links to shared files</span
                            >
                            <span
                                class="block text-[12px] text-muted-foreground"
                                >{{
                                    hasFiles
                                        ? 'Each file in the chat gets a download link.'
                                        : 'No files were shared in this meeting.'
                                }}</span
                            >
                        </span>
                        <Switch
                            v-model="includeFiles"
                            :disabled="!hasFiles"
                            class="mt-0.5"
                        />
                    </label>
                    <label
                        class="flex items-start justify-between gap-4 px-3.5 py-2.5"
                        :class="
                            hasRecording
                                ? 'cursor-pointer'
                                : 'cursor-not-allowed'
                        "
                    >
                        <span>
                            <span class="block text-[13px] font-medium"
                                >Include a link to the recording</span
                            >
                            <span
                                class="block text-[12px] text-muted-foreground"
                                >{{
                                    hasRecording
                                        ? 'Recipients can watch it in their browser.'
                                        : 'There is no finished recording of this meeting.'
                                }}</span
                            >
                        </span>
                        <Switch
                            v-model="includeRecording"
                            :disabled="!hasRecording"
                            class="mt-0.5"
                        />
                    </label>
                </div>

                <p
                    class="flex gap-2 rounded-lg bg-muted/50 px-3 py-2.5 text-[12px] leading-relaxed text-muted-foreground"
                >
                    <Info class="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        File and recording links work without an account and
                        expire on the date stated in the email. They open only
                        those files and that recording: people outside
                        {{ call.organization.name }} cannot reach anything else.
                        The “open in app” link needs an account here.
                    </span>
                </p>
            </form>

            <DialogFooter>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="sending"
                    @click="open = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="email-transcript"
                    size="sm"
                    class="gap-1.5"
                    :disabled="sending"
                >
                    <Spinner v-if="sending" class="size-3.5" />
                    Send{{
                        recipients.length > 0
                            ? ` to ${plural(recipients.length, 'person', 'people')}`
                            : ''
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
