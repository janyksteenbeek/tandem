<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    Clock,
    ExternalLink,
    Loader2,
    Mail,
    Send,
    ShieldCheck,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import BusinessHoursEditor from '@/components/settings/BusinessHoursEditor.vue';
import ConfirmDialog from '@/components/settings/ConfirmDialog.vue';
import CopyField from '@/components/settings/CopyField.vue';
import TimezonePicker from '@/components/settings/TimezonePicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    edit as integrationsEdit,
    update as integrationsUpdate,
} from '@/routes/settings/integrations';
import type {
    BusinessHours,
    BusinessHoursDay,
    GoogleIntegrationInfo,
    IntegrationOrganization,
    OutboundEncryption,
} from '@/types';

const props = defineProps<{
    organization: IntegrationOrganization;
    google: GoogleIntegrationInfo;
    forwardingDomain: string;
    timezones: string[];
}>();

const DAY_KEYS: BusinessHoursDay[] = [
    'mon',
    'tue',
    'wed',
    'thu',
    'fri',
    'sat',
    'sun',
];

function initialBusinessHours(): BusinessHours {
    const stored = props.organization.business_hours;
    const days = Object.fromEntries(
        DAY_KEYS.map((key) => [
            key,
            (stored?.days?.[key] ?? []).map((range) => ({ ...range })),
        ]),
    ) as BusinessHours['days'];

    if (!stored) {
        (['mon', 'tue', 'wed', 'thu', 'fri'] as BusinessHoursDay[]).forEach(
            (key) => (days[key] = [{ start: '09:00', end: '17:00' }]),
        );
    }

    return {
        timezone: stored?.timezone ?? props.organization.timezone,
        days,
        holidays: [...(stored?.holidays ?? [])],
    };
}

const form = useForm({
    timezone: props.organization.timezone,
    google_oauth_client_id: props.organization.google_oauth_client_id ?? '',
    google_oauth_client_secret: '',
    clear_google_secret: false,
    google_pubsub_topic: props.organization.google_pubsub_topic ?? '',
    business_hours: initialBusinessHours(),
    outbound: {
        host: props.organization.outbound.host ?? '',
        port: props.organization.outbound.port ?? 587,
        encryption: (props.organization.outbound.encryption ??
            'tls') as OutboundEncryption,
        username: props.organization.outbound.username ?? '',
        password: '',
        from_name: props.organization.outbound.from_name ?? '',
    },
    clear_outbound: false,
});

const saving = ref<'google' | 'hours' | 'outbound' | null>(null);
const replacingSecret = ref(!props.organization.google_oauth_client_secret_set);
const confirmClearOutbound = ref(false);

const googleReady = computed(
    () =>
        !!props.organization.google_oauth_client_id &&
        props.organization.google_oauth_client_secret_set,
);
const businessHoursErrors = computed(
    () =>
        Object.fromEntries(
            Object.entries(form.errors).filter(([key]) =>
                key.startsWith('business_hours'),
            ),
        ) as Record<string, string>,
);

function save(section: 'google' | 'hours' | 'outbound'): void {
    saving.value = section;
    form.patch(integrationsUpdate.url(props.organization.slug), {
        preserveScroll: true,
        onSuccess: () => {
            form.google_oauth_client_secret = '';
            form.outbound.password = '';
            form.clear_google_secret = false;
            form.clear_outbound = false;
            replacingSecret.value = false;
            confirmClearOutbound.value = false;
        },
        onFinish: () => (saving.value = null),
    });
}

function clearSecret(): void {
    form.clear_google_secret = true;
    form.google_oauth_client_secret = '';
    save('google');
}

function clearOutbound(): void {
    form.clear_outbound = true;
    form.outbound = {
        host: '',
        port: 587,
        encryption: 'tls',
        username: '',
        password: '',
        from_name: '',
    };
    save('outbound');
}

const STEPS = [
    {
        title: 'Create a Google Cloud project',
        body: 'Any project works. Use one your organization controls, not a personal one.',
    },
    {
        title: 'Enable the Gmail API',
        body: 'APIs & Services → Library → “Gmail API” → Enable. Also enable Cloud Pub/Sub while you are there.',
    },
    {
        title: 'Configure the OAuth consent screen',
        body: 'Internal is fine for Google Workspace. Add these scopes:',
        scopes: true,
    },
    {
        title: 'Create an OAuth client (Web application)',
        body: 'Add this redirect URI exactly as shown, then paste the client ID and secret below.',
        copy: 'redirect',
    },
    {
        title: 'Create a Pub/Sub topic',
        body: 'Grant the “Pub/Sub Publisher” role on the topic to gmail-api-push@system.gserviceaccount.com. Paste the full topic name below.',
        copy: 'publisher',
    },
    {
        title: 'Add a push subscription',
        body: 'Delivery type “Push” to this endpoint. The token in the URL is verified on arrival.',
        copy: 'push',
    },
] as const;
</script>

<template>
    <Head title="Integrations" />

    <h1 class="sr-only">Integrations</h1>

    <div class="space-y-10">
        <section class="overflow-hidden rounded-2xl border bg-card shadow-pane">
            <header
                class="flex flex-wrap items-start gap-3 border-b bg-surface/60 px-5 py-4"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-(--chip)/15 text-(--chip) chip-rose"
                    ><Mail class="size-5"
                /></span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-semibold">Google (Gmail)</h2>
                    <p class="text-sm text-muted-foreground">
                        Your own OAuth client so people can connect Gmail and
                        Workspace mailboxes. Push notifications keep sync
                        near-instant.
                    </p>
                </div>
                <span
                    v-if="googleReady"
                    class="inline-flex items-center gap-1.5 rounded-full bg-success/12 px-2.5 py-0.5 text-xs font-medium text-success"
                    ><ShieldCheck class="size-3.5" /> Configured</span
                >
                <span
                    v-else-if="google.env_fallback"
                    class="rounded-full bg-info/12 px-2.5 py-0.5 text-xs font-medium text-info"
                    >Using server default</span
                >
                <span
                    v-else
                    class="rounded-full bg-warning/20 px-2.5 py-0.5 text-xs font-medium text-warning-foreground"
                    >Not configured</span
                >
            </header>

            <div
                class="grid gap-8 p-5 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]"
            >
                <ol class="space-y-4">
                    <li
                        v-for="(step, index) in STEPS"
                        :key="step.title"
                        class="flex gap-3"
                    >
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand-soft-foreground"
                            >{{ index + 1 }}</span
                        >
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <p class="text-sm font-medium">{{ step.title }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ step.body }}
                            </p>
                            <ul v-if="'scopes' in step" class="space-y-1">
                                <li
                                    v-for="scope in google.scopes"
                                    :key="scope"
                                    class="truncate rounded bg-surface-sunken px-2 py-1 font-mono text-[11px]"
                                    :title="scope"
                                >
                                    {{ scope }}
                                </li>
                            </ul>
                            <CopyField
                                v-if="
                                    'copy' in step && step.copy === 'redirect'
                                "
                                :value="google.redirect_uri"
                                size="sm"
                                label="Authorized redirect URI"
                            />
                            <CopyField
                                v-if="
                                    'copy' in step && step.copy === 'publisher'
                                "
                                value="gmail-api-push@system.gserviceaccount.com"
                                size="sm"
                                label="Publisher principal"
                            />
                            <CopyField
                                v-if="'copy' in step && step.copy === 'push'"
                                :value="google.push_endpoint"
                                size="sm"
                                label="Push endpoint"
                            />
                        </div>
                    </li>
                    <li class="pl-9">
                        <a
                            href="https://console.cloud.google.com/apis/credentials"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 text-xs font-medium text-brand hover:underline"
                            >Open Google Cloud Console
                            <ExternalLink class="size-3"
                        /></a>
                    </li>
                </ol>

                <form
                    class="space-y-4 lg:border-l lg:pl-8"
                    @submit.prevent="save('google')"
                >
                    <div class="grid gap-1.5">
                        <Label for="google-client-id">OAuth client ID</Label>
                        <Input
                            id="google-client-id"
                            v-model="form.google_oauth_client_id"
                            placeholder="1234567890-abc.apps.googleusercontent.com"
                            autocomplete="off"
                            class="font-mono text-xs"
                        />
                        <InputError
                            :message="form.errors.google_oauth_client_id"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="google-client-secret"
                            >OAuth client secret</Label
                        >
                        <div
                            v-if="
                                organization.google_oauth_client_secret_set &&
                                !replacingSecret
                            "
                            class="flex items-center gap-2 rounded-md border bg-surface-sunken px-3 py-2 text-sm"
                        >
                            <ShieldCheck class="size-4 shrink-0 text-success" />
                            <span class="flex-1 font-mono text-xs">{{
                                organization.google_oauth_client_secret_hint
                            }}</span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="xs"
                                @click="replacingSecret = true"
                                >Replace</Button
                            >
                            <Button
                                type="button"
                                variant="ghost"
                                size="xs"
                                class="text-destructive hover:text-destructive"
                                :disabled="saving === 'google'"
                                @click="clearSecret"
                                >Clear</Button
                            >
                        </div>
                        <template v-else>
                            <PasswordInput
                                id="google-client-secret"
                                v-model="form.google_oauth_client_secret"
                                placeholder="GOCSPX-…"
                                autocomplete="new-password"
                            />
                            <p
                                v-if="
                                    organization.google_oauth_client_secret_set
                                "
                                class="text-xs text-muted-foreground"
                            >
                                Leave blank to keep the current secret.
                                <button
                                    type="button"
                                    class="font-medium text-brand hover:underline"
                                    @click="replacingSecret = false"
                                >
                                    Cancel
                                </button>
                            </p>
                            <p v-else class="text-xs text-muted-foreground">
                                Stored encrypted. We only ever show the last
                                four characters afterwards.
                            </p>
                        </template>
                        <InputError
                            :message="form.errors.google_oauth_client_secret"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="google-topic">Pub/Sub topic</Label>
                        <Input
                            id="google-topic"
                            v-model="form.google_pubsub_topic"
                            placeholder="projects/your-project/topics/tandem-gmail"
                            autocomplete="off"
                            class="font-mono text-xs"
                        />
                        <p class="text-xs text-muted-foreground">
                            Optional, but without it Gmail accounts fall back to
                            polling every 15 minutes.
                        </p>
                        <InputError
                            :message="form.errors.google_pubsub_topic"
                        />
                    </div>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <Button
                            type="submit"
                            class="bg-brand text-brand-foreground hover:bg-brand/90"
                            :disabled="form.processing"
                        >
                            <Loader2
                                v-if="saving === 'google'"
                                class="animate-spin"
                            />
                            Save Google settings
                        </Button>
                    </div>
                </form>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border bg-card shadow-pane">
            <header
                class="flex flex-wrap items-start gap-3 border-b bg-surface/60 px-5 py-4"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-(--chip)/15 text-(--chip) chip-amber"
                    ><Clock class="size-5"
                /></span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-semibold">Business hours</h2>
                    <p class="text-sm text-muted-foreground">
                        Lets automations tell “we are closed, expect us
                        tomorrow” from “someone is on it”.
                    </p>
                </div>
            </header>
            <form class="space-y-6 p-5" @submit.prevent="save('hours')">
                <div class="grid gap-1.5 sm:max-w-sm">
                    <Label for="organization-timezone"
                        >Organization timezone</Label
                    >
                    <TimezonePicker
                        id="organization-timezone"
                        v-model="form.timezone"
                        :timezones="timezones"
                    />
                    <p class="text-xs text-muted-foreground">
                        Used for reports, digests and anything that says
                        “today”.
                    </p>
                    <InputError :message="form.errors.timezone" />
                </div>
                <BusinessHoursEditor
                    v-model="form.business_hours"
                    :timezones="timezones"
                    :errors="businessHoursErrors"
                />
                <div class="flex items-center justify-end gap-2">
                    <Button
                        type="submit"
                        class="bg-brand text-brand-foreground hover:bg-brand/90"
                        :disabled="form.processing"
                    >
                        <Loader2
                            v-if="saving === 'hours'"
                            class="animate-spin"
                        />
                        Save business hours
                    </Button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border bg-card shadow-pane">
            <header
                class="flex flex-wrap items-start gap-3 border-b bg-surface/60 px-5 py-4"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-(--chip)/15 text-(--chip) chip-teal"
                    ><Send class="size-5"
                /></span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-semibold">Outbound SMTP</h2>
                    <p class="text-sm text-muted-foreground">
                        Replies from forwarding-only inboxes (addresses on
                        {{ forwardingDomain }}) and autoresponders leave through
                        this server. Gmail and IMAP accounts send through their
                        own.
                    </p>
                </div>
                <span
                    v-if="organization.outbound.host"
                    class="inline-flex items-center gap-1.5 rounded-full bg-success/12 px-2.5 py-0.5 text-xs font-medium text-success"
                    ><ShieldCheck class="size-3.5" /> Configured</span
                >
                <span
                    v-else
                    class="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground"
                    >Using server default</span
                >
            </header>
            <form class="space-y-5 p-5" @submit.prevent="save('outbound')">
                <div class="grid gap-4 sm:grid-cols-[1fr_6rem_9rem]">
                    <div class="grid gap-1.5">
                        <Label for="outbound-host">Host</Label>
                        <Input
                            id="outbound-host"
                            v-model="form.outbound.host"
                            placeholder="smtp.company.com"
                            autocomplete="off"
                        />
                        <InputError :message="form.errors['outbound.host']" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="outbound-port">Port</Label>
                        <Input
                            id="outbound-port"
                            v-model.number="form.outbound.port"
                            type="number"
                            min="1"
                            max="65535"
                        />
                        <InputError :message="form.errors['outbound.port']" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="outbound-encryption">Security</Label>
                        <Select v-model="form.outbound.encryption">
                            <SelectTrigger
                                id="outbound-encryption"
                                class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="tls"
                                    >STARTTLS (587)</SelectItem
                                >
                                <SelectItem value="ssl"
                                    >SSL / TLS (465)</SelectItem
                                >
                                <SelectItem value="none">None</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            :message="form.errors['outbound.encryption']"
                        />
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <Label for="outbound-username">Username</Label>
                        <Input
                            id="outbound-username"
                            v-model="form.outbound.username"
                            autocomplete="off"
                        />
                        <InputError
                            :message="form.errors['outbound.username']"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="outbound-password">Password</Label>
                        <PasswordInput
                            id="outbound-password"
                            v-model="form.outbound.password"
                            :placeholder="
                                organization.outbound.password_set
                                    ? 'Saved. Leave blank to keep it.'
                                    : 'App password'
                            "
                            autocomplete="new-password"
                        />
                        <InputError
                            :message="form.errors['outbound.password']"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="outbound-from-name">From name</Label>
                        <Input
                            id="outbound-from-name"
                            v-model="form.outbound.from_name"
                            :placeholder="organization.name"
                            maxlength="120"
                        />
                        <p class="text-xs text-muted-foreground">
                            The address stays the inbox's own.
                        </p>
                        <InputError
                            :message="form.errors['outbound.from_name']"
                        />
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Button
                        v-if="organization.outbound.host"
                        type="button"
                        variant="ghost"
                        class="text-destructive hover:text-destructive"
                        :disabled="form.processing"
                        @click="confirmClearOutbound = true"
                        ><Trash2 /> Clear SMTP settings</Button
                    >
                    <span v-else />
                    <Button
                        type="submit"
                        class="bg-brand text-brand-foreground hover:bg-brand/90"
                        :disabled="form.processing"
                    >
                        <Loader2
                            v-if="saving === 'outbound'"
                            class="animate-spin"
                        />
                        Save SMTP settings
                    </Button>
                </div>
            </form>
        </section>
    </div>

    <ConfirmDialog
        v-model:open="confirmClearOutbound"
        title="Clear outbound SMTP?"
        description="Forwarding-only inboxes fall back to the server-wide mailer. Replies may land in spam if that mailer cannot send as your domain."
        confirm-label="Clear settings"
        destructive
        :busy="saving === 'outbound'"
        @confirm="clearOutbound"
    />
</template>
