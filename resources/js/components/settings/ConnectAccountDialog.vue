<script setup lang="ts">
import { Form, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    CircleCheck,
    Loader2,
    Mail,
    Send,
    ShieldCheck,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import CopyField from '@/components/settings/CopyField.vue';
import ImapAccountForm from '@/components/settings/ImapAccountForm.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    CHANNEL_INTRO,
    CHANNEL_STEPS,
    FORWARDING_CHANNELS,
    PROVIDER_META,
    usesForwardingAddress,
} from '@/lib/channels';
import { api, reportError } from '@/lib/http';
import { cn } from '@/lib/utils';
import {
    store as storeForwarding,
    test as testForwarding,
} from '@/routes/mail/forwarding';
import { redirect as gmailRedirect } from '@/routes/mail/gmail';
import { edit as integrationsEdit } from '@/routes/settings/integrations';
import type { CreatedChannel, MailProvider } from '@/types';

const props = defineProps<{
    inbox?: { uuid: string; name: string } | null;
    googleConfigured: boolean;
    forwardingDomain?: string;
    initialProvider?: MailProvider | null;
}>();
const open = defineModel<boolean>('open', { default: false });

const page = usePage();
const provider = ref<MailProvider | null>(null);
const created = ref<CreatedChannel | null>(null);
const testing = ref(false);

watch(open, (value) => {
    if (value) {
        provider.value = props.initialProvider ?? null;
        created.value = null;
    }
});

/** Mailboxes can feed personal or shared inboxes; forwarding channels only make sense for a team inbox. */
const choices = computed<MailProvider[]>(() =>
    props.inbox ? ['gmail', 'imap', ...FORWARDING_CHANNELS] : ['gmail', 'imap'],
);

const forwardingChannel = computed(() =>
    provider.value && usesForwardingAddress(provider.value)
        ? provider.value
        : null,
);

const gmailUrl = computed(() =>
    gmailRedirect.url(
        props.inbox ? { query: { inbox: props.inbox.uuid } } : undefined,
    ),
);
const canConfigureGoogle = computed(
    () => !!page.props.currentOrganization && page.props.canManageOrganization,
);
const target = computed(() =>
    props.inbox ? `the ${props.inbox.name} inbox` : 'your personal mail',
);

const title = computed(() => {
    switch (provider.value) {
        case 'gmail':
            return 'Connect Gmail';
        case 'imap':
            return 'Connect a mailbox over IMAP';
        case 'google_group':
            return created.value
                ? 'Your group address is ready'
                : 'Add a Google Group';
        case 'forwarding':
            return created.value ? 'Your address is ready' : 'Add a forward';
        default:
            return props.inbox ? 'Add a channel' : 'Connect an account';
    }
});

function onFlash(flash: { channel?: CreatedChannel }): void {
    if (flash.channel && flash.channel.provider === provider.value) {
        created.value = flash.channel;
    }
}

async function sendTest(): Promise<void> {
    if (!created.value) {
        return;
    }

    testing.value = true;

    try {
        await api.post(testForwarding.url(created.value.uuid));
        toast.success(
            `Test sent to ${created.value.email}. Give it a minute, then look for “Tandem forwarding test”.`,
        );
    } catch (error) {
        reportError(error, 'The test message could not be sent.');
    } finally {
        testing.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="scrollbar-thin max-h-[90dvh] overflow-y-auto sm:max-w-2xl"
        >
            <DialogHeader>
                <DialogTitle class="font-display text-2xl">
                    {{ title }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="provider === null"
                        >Choose how mail should reach {{ target }}. You can add
                        more channels later.</template
                    >
                    <template v-else-if="provider === 'gmail'"
                        >Google asks you to approve access, then we start
                        syncing into {{ target }}.</template
                    >
                    <template v-else-if="provider === 'imap'"
                        >Works with any provider that speaks IMAP and SMTP:
                        Fastmail, Outlook, your own server. Passwords are
                        encrypted at rest.</template
                    >
                    <template v-else-if="created"
                        >Finish the set-up on the other side and mail starts
                        flowing into {{ target }}.</template
                    >
                    <template v-else-if="provider === 'google_group'"
                        >Keep answering from the group address while the whole
                        team reads along in {{ target }}.</template
                    >
                    <template v-else
                        >Handy when you cannot or would rather not hand over a
                        mailbox password.</template
                    >
                </DialogDescription>
            </DialogHeader>

            <div v-if="provider === null" class="grid gap-3 sm:grid-cols-2">
                <button
                    v-for="choice in choices"
                    :key="choice"
                    type="button"
                    class="group rounded-2xl border p-4 text-left transition hover:border-brand/60 hover:bg-brand-soft/30"
                    :data-testid="`channel-${choice}`"
                    @click="provider = choice"
                >
                    <span
                        :class="
                            cn(
                                'mb-3 flex size-10 items-center justify-center rounded-xl bg-(--chip)/15 text-(--chip)',
                                PROVIDER_META[choice].tone,
                            )
                        "
                        ><component
                            :is="PROVIDER_META[choice].icon"
                            class="size-5"
                    /></span>
                    <p class="font-semibold">
                        {{
                            choice === 'gmail'
                                ? 'Gmail or Google Workspace'
                                : PROVIDER_META[choice].label
                        }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ PROVIDER_META[choice].tagline }}
                    </p>
                    <span
                        class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand"
                        >Continue
                        <ArrowRight
                            class="size-3.5 transition group-hover:translate-x-0.5"
                    /></span>
                </button>
            </div>

            <div v-else-if="provider === 'gmail'" class="space-y-5">
                <ol class="space-y-3 text-sm">
                    <li
                        v-for="(step, index) in [
                            'You are sent to Google and pick the account to connect.',
                            'Google shows the permissions Tandem needs: read, label and send mail on your behalf.',
                            'You land back here and the first sync starts. The last 90 days arrive within minutes.',
                        ]"
                        :key="index"
                        class="flex gap-3"
                    >
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand-soft-foreground"
                            >{{ index + 1 }}</span
                        >
                        <span class="pt-0.5">{{ step }}</span>
                    </li>
                </ol>

                <div
                    class="flex items-start gap-2 rounded-lg bg-surface-sunken px-3 py-2.5 text-xs text-muted-foreground"
                >
                    <ShieldCheck
                        class="mt-0.5 size-3.5 shrink-0 text-success"
                    />
                    <span
                        >Tokens are stored encrypted. Disconnecting revokes them
                        at Google, and your mail stays readable in Tandem.</span
                    >
                </div>

                <div
                    v-if="!googleConfigured"
                    class="rounded-lg border border-warning/50 bg-warning/10 px-3 py-2.5 text-sm"
                >
                    <p class="font-medium text-warning-foreground">
                        Google is not set up for this organization yet.
                    </p>
                    <p class="mt-0.5 text-foreground/80">
                        Connecting Gmail needs an OAuth client.
                        <Link
                            v-if="canConfigureGoogle"
                            :href="
                                integrationsEdit(
                                    page.props.currentOrganization!.slug,
                                )
                            "
                            class="font-medium text-brand underline-offset-2 hover:underline"
                            >Configure it under Integrations</Link
                        >
                        <template v-else
                            >Ask an organization admin to configure it under
                            Integrations.</template
                        >
                    </p>
                </div>

                <div
                    class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        @click="provider = null"
                        ><ArrowLeft /> Back</Button
                    >
                    <Button
                        as-child
                        :class="
                            cn(
                                'bg-brand text-brand-foreground hover:bg-brand/90',
                                !googleConfigured &&
                                    'pointer-events-none opacity-50',
                            )
                        "
                    >
                        <a :href="gmailUrl" :aria-disabled="!googleConfigured"
                            ><Mail /> Continue with Google</a
                        >
                    </Button>
                </div>
            </div>

            <div v-else-if="provider === 'imap'" class="space-y-4">
                <ImapAccountForm
                    :inbox-uuid="inbox?.uuid ?? null"
                    @saved="open = false"
                    @cancel="provider = null"
                />
            </div>

            <!-- Forward and Google Group share one flow: generate an address, then set it up elsewhere. -->
            <div
                v-else-if="forwardingChannel && inbox && created"
                class="space-y-5"
            >
                <div
                    class="flex items-start gap-3 rounded-2xl border border-success/40 bg-success/8 p-4"
                >
                    <CircleCheck class="mt-0.5 size-5 shrink-0 text-success" />
                    <div class="min-w-0 flex-1 space-y-2">
                        <p class="text-sm font-medium">
                            {{ PROVIDER_META[forwardingChannel].label }} channel
                            added to {{ inbox.name }}.
                        </p>
                        <CopyField
                            :value="created.email"
                            toast-message="Address copied."
                        />
                    </div>
                </div>

                <ol class="space-y-3 text-sm">
                    <li
                        v-for="(step, index) in CHANNEL_STEPS[
                            forwardingChannel
                        ]"
                        :key="index"
                        class="flex gap-3"
                    >
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand-soft-foreground"
                            >{{ index + 1 }}</span
                        >
                        <span class="pt-0.5">{{ step }}</span>
                    </li>
                </ol>

                <p
                    class="rounded-lg bg-surface-sunken px-3 py-2.5 text-xs text-muted-foreground"
                >
                    Replies go out through the organization's outbound SMTP
                    server (see Integrations) and show the inbox address as
                    sender, never this generated one.
                </p>

                <div
                    class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="testing"
                        @click="sendTest"
                    >
                        <Loader2 v-if="testing" class="animate-spin" />
                        <Send v-else />
                        Send test email
                    </Button>
                    <Button
                        type="button"
                        class="bg-brand text-brand-foreground hover:bg-brand/90"
                        @click="open = false"
                        >Done</Button
                    >
                </div>
            </div>

            <Form
                v-else-if="forwardingChannel && inbox"
                v-bind="storeForwarding.form(inbox.uuid)"
                :options="{ preserveScroll: true }"
                class="space-y-5"
                v-slot="{ processing, errors }"
                @flash="onFlash"
            >
                <input
                    type="hidden"
                    name="channel"
                    :value="forwardingChannel"
                />

                <div
                    class="flex items-start gap-3 rounded-2xl bg-surface-sunken p-4 text-sm"
                >
                    <span
                        :class="
                            cn(
                                'flex size-9 shrink-0 items-center justify-center rounded-xl bg-(--chip)/15 text-(--chip)',
                                PROVIDER_META[forwardingChannel].tone,
                            )
                        "
                        ><component
                            :is="PROVIDER_META[forwardingChannel].icon"
                            class="size-4"
                    /></span>
                    <p class="text-foreground/80">
                        {{ CHANNEL_INTRO[forwardingChannel] }}
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="channel-label"
                        >Label
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></Label
                    >
                    <Input
                        id="channel-label"
                        name="label"
                        maxlength="80"
                        :placeholder="
                            forwardingChannel === 'google_group'
                                ? 'e.g. support@ group'
                                : 'e.g. old helpdesk alias'
                        "
                    />
                    <p v-if="errors.label" class="text-xs text-destructive">
                        {{ errors.label }}
                    </p>
                    <p v-if="errors.channel" class="text-xs text-destructive">
                        {{ errors.channel }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        A random, unguessable address on
                        {{ forwardingDomain ?? 'the forwarding domain' }} is
                        generated for you. You can add several per inbox.
                    </p>
                </div>

                <div
                    class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        @click="provider = null"
                        ><ArrowLeft /> Back</Button
                    >
                    <Button
                        type="submit"
                        class="bg-brand text-brand-foreground hover:bg-brand/90"
                        :disabled="processing"
                    >
                        <Loader2 v-if="processing" class="animate-spin" />
                        Generate address
                    </Button>
                </div>
            </Form>
        </DialogContent>
    </Dialog>
</template>
