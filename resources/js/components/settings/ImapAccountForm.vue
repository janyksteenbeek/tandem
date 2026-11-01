<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CheckCircle2, Loader2, PlugZap, XCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { api, HttpError } from '@/lib/http';
import {
    store as imapStore,
    test as imapTest,
    update as imapUpdate,
} from '@/routes/mail/imap';
import type {
    ConnectionTestResponse,
    ImapEncryption,
    MailAccount,
} from '@/types';

const props = defineProps<{
    inboxUuid?: string | null;
    account?: MailAccount | null;
}>();
const emit = defineEmits<{ saved: []; cancel: [] }>();

const ENCRYPTIONS: { value: ImapEncryption; label: string }[] = [
    { value: 'ssl', label: 'SSL / TLS' },
    { value: 'starttls', label: 'STARTTLS' },
    { value: 'tls', label: 'TLS' },
    { value: 'none', label: 'None' },
];

const editing = computed(() => !!props.account);
const imap = props.account?.settings.imap;
const smtp = props.account?.settings.smtp;

const form = useForm({
    name: props.account?.display_name ?? '',
    email: props.account?.email ?? '',
    host: imap?.host ?? '',
    port: imap?.port ?? 993,
    encryption: (imap?.encryption ?? 'ssl') as ImapEncryption,
    username: imap?.username ?? '',
    password: '',
    validate_cert: imap?.validate_cert ?? true,
    smtp_host: smtp?.host ?? '',
    smtp_port: smtp?.port ?? 587,
    smtp_encryption: (smtp?.encryption ?? 'starttls') as ImapEncryption,
    smtp_username:
        smtp?.username && smtp.username !== imap?.username ? smtp.username : '',
    smtp_password: '',
    append_sent: smtp?.append_sent ?? true,
    inbox: props.inboxUuid ?? null,
});

const testing = ref(false);
const testResult = ref<ConnectionTestResponse | null>(null);

/** Keep ports in step with the chosen encryption until someone types their own. */
const portTouched = ref({ imap: !!imap, smtp: !!smtp });

watch(
    () => form.encryption,
    (value) => {
        if (!portTouched.value.imap) {
            form.port = value === 'ssl' ? 993 : 143;
        }
    },
);

watch(
    () => form.smtp_encryption,
    (value) => {
        if (!portTouched.value.smtp) {
            form.smtp_port =
                value === 'ssl' ? 465 : value === 'none' ? 25 : 587;
        }
    },
);

/** Guess the servers from the mailbox domain so most people only type a password. */
watch(
    () => form.email,
    (value) => {
        if (editing.value) {
            return;
        }

        const domain = value.split('@')[1];

        if (!domain || !domain.includes('.')) {
            return;
        }

        if (!form.username) {
            form.username = value;
        }

        if (!form.host) {
            form.host = `imap.${domain}`;
        }

        if (!form.smtp_host) {
            form.smtp_host = `smtp.${domain}`;
        }
    },
);

watch(
    () => [
        form.host,
        form.port,
        form.encryption,
        form.username,
        form.password,
        form.smtp_host,
        form.smtp_port,
        form.smtp_encryption,
        form.smtp_username,
        form.smtp_password,
    ],
    () => (testResult.value = null),
);

const canTest = computed(
    () =>
        form.host &&
        form.username &&
        (form.password || editing.value) &&
        form.smtp_host,
);

async function testConnection(): Promise<void> {
    testing.value = true;
    testResult.value = null;
    form.clearErrors();

    try {
        testResult.value = await api.post<ConnectionTestResponse>(
            imapTest.url(),
            form.data(),
        );
    } catch (error) {
        if (
            error instanceof HttpError &&
            Object.keys(error.errors).length > 0
        ) {
            Object.entries(error.errors).forEach(([field, messages]) =>
                form.setError(field as keyof typeof form.data, messages[0]),
            );
            testResult.value = {
                ok: false,
                message: 'Fix the highlighted fields and try again.',
                details: {},
            };
        } else {
            testResult.value = {
                ok: false,
                message:
                    error instanceof HttpError
                        ? error.message
                        : 'Could not reach the server. Check the host and port.',
                details: {},
            };
        }
    } finally {
        testing.value = false;
    }
}

function submit(): void {
    if (editing.value && props.account) {
        form.transform(
            ({ email: _email, inbox: _inbox, ...rest }) => rest,
        ).patch(imapUpdate.url(props.account.uuid), {
            preserveScroll: true,
            onSuccess: () => emit('saved'),
        });

        return;
    }

    form.post(imapStore.url(), {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
}
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="imap-name">Name</Label>
                <Input
                    id="imap-name"
                    v-model="form.name"
                    placeholder="Support, or your name"
                    required
                    autocomplete="off"
                />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="imap-email">Email address</Label>
                <Input
                    id="imap-email"
                    v-model="form.email"
                    type="email"
                    placeholder="you@company.com"
                    :disabled="editing"
                    autocomplete="off"
                    required
                />
                <InputError :message="form.errors.email" />
            </div>
        </div>

        <fieldset class="space-y-3 rounded-xl border bg-surface p-4">
            <legend
                class="px-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                Incoming (IMAP)
            </legend>
            <div class="grid gap-3 sm:grid-cols-[1fr_6rem_9rem]">
                <div class="grid gap-1.5">
                    <Label for="imap-host">Host</Label>
                    <Input
                        id="imap-host"
                        v-model="form.host"
                        placeholder="imap.company.com"
                        autocomplete="off"
                        required
                    />
                    <InputError :message="form.errors.host" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="imap-port">Port</Label>
                    <Input
                        id="imap-port"
                        v-model.number="form.port"
                        type="number"
                        min="1"
                        max="65535"
                        required
                        @input="portTouched.imap = true"
                    />
                    <InputError :message="form.errors.port" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="imap-encryption">Security</Label>
                    <Select v-model="form.encryption">
                        <SelectTrigger id="imap-encryption" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in ENCRYPTIONS"
                                :key="option.value"
                                :value="option.value"
                                >{{ option.label }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="imap-username">Username</Label>
                    <Input
                        id="imap-username"
                        v-model="form.username"
                        placeholder="Usually the full email address"
                        autocomplete="off"
                        required
                    />
                    <InputError :message="form.errors.username" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="imap-password">Password</Label>
                    <PasswordInput
                        id="imap-password"
                        v-model="form.password"
                        :placeholder="
                            editing
                                ? 'Leave blank to keep the stored password'
                                : 'App password or mailbox password'
                        "
                        autocomplete="new-password"
                        :required="!editing"
                    />
                    <InputError :message="form.errors.password" />
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <Checkbox
                    :model-value="form.validate_cert"
                    @update:model-value="
                        (value) => (form.validate_cert = value === true)
                    "
                />
                Verify the server certificate
                <span class="text-xs text-muted-foreground"
                    >(turn off only for self-signed certificates)</span
                >
            </label>
        </fieldset>

        <fieldset class="space-y-3 rounded-xl border bg-surface p-4">
            <legend
                class="px-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                Outgoing (SMTP)
            </legend>
            <div class="grid gap-3 sm:grid-cols-[1fr_6rem_9rem]">
                <div class="grid gap-1.5">
                    <Label for="smtp-host">Host</Label>
                    <Input
                        id="smtp-host"
                        v-model="form.smtp_host"
                        placeholder="smtp.company.com"
                        autocomplete="off"
                        required
                    />
                    <InputError :message="form.errors.smtp_host" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="smtp-port">Port</Label>
                    <Input
                        id="smtp-port"
                        v-model.number="form.smtp_port"
                        type="number"
                        min="1"
                        max="65535"
                        required
                        @input="portTouched.smtp = true"
                    />
                    <InputError :message="form.errors.smtp_port" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="smtp-encryption">Security</Label>
                    <Select v-model="form.smtp_encryption">
                        <SelectTrigger id="smtp-encryption" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in ENCRYPTIONS"
                                :key="option.value"
                                :value="option.value"
                                >{{ option.label }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="smtp-username"
                        >Username
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></Label
                    >
                    <Input
                        id="smtp-username"
                        v-model="form.smtp_username"
                        placeholder="Same as IMAP when left blank"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.smtp_username" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="smtp-password"
                        >Password
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></Label
                    >
                    <PasswordInput
                        id="smtp-password"
                        v-model="form.smtp_password"
                        placeholder="Same as IMAP when left blank"
                        autocomplete="new-password"
                    />
                    <InputError :message="form.errors.smtp_password" />
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <Checkbox
                    :model-value="form.append_sent"
                    @update:model-value="
                        (value) => (form.append_sent = value === true)
                    "
                />
                Save a copy of sent mail to the Sent folder
            </label>
        </fieldset>

        <div
            v-if="testResult"
            class="flex animate-pop items-start gap-2 rounded-lg border px-3 py-2.5 text-sm"
            :class="
                testResult.ok
                    ? 'border-success/40 bg-success/10 text-success'
                    : 'border-destructive/40 bg-destructive/10 text-destructive'
            "
            role="status"
        >
            <CheckCircle2 v-if="testResult.ok" class="mt-0.5 size-4 shrink-0" />
            <XCircle v-else class="mt-0.5 size-4 shrink-0" />
            <div class="min-w-0">
                <p class="font-medium">
                    {{
                        testResult.ok
                            ? 'Both servers answered.'
                            : 'That did not work.'
                    }}
                </p>
                <p v-if="testResult.message" class="text-foreground/80">
                    {{ testResult.message }}
                </p>
            </div>
        </div>

        <div
            class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between"
        >
            <Button
                type="button"
                variant="outline"
                :disabled="!canTest || testing"
                @click="testConnection"
            >
                <Loader2 v-if="testing" class="animate-spin" />
                <PlugZap v-else />
                Test connection
            </Button>
            <div class="flex gap-2 sm:justify-end">
                <Button
                    type="button"
                    variant="ghost"
                    class="flex-1 sm:flex-none"
                    @click="emit('cancel')"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    class="flex-1 bg-brand text-brand-foreground hover:bg-brand/90 sm:flex-none"
                    :disabled="form.processing"
                >
                    <Loader2 v-if="form.processing" class="animate-spin" />
                    {{ editing ? 'Save connection' : 'Connect mailbox' }}
                </Button>
            </div>
        </div>
    </form>
</template>
