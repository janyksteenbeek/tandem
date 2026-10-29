<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Mail, Server, ShieldCheck } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ImapAccountForm from '@/components/settings/ImapAccountForm.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { redirect as gmailRedirect } from '@/routes/mail/gmail';
import { edit as integrationsEdit } from '@/routes/settings/integrations';

const props = defineProps<{
    inbox?: { uuid: string; name: string } | null;
    googleConfigured: boolean;
    initialProvider?: 'gmail' | 'imap' | null;
}>();
const open = defineModel<boolean>('open', { default: false });

const page = usePage();
const provider = ref<'gmail' | 'imap' | null>(null);

watch(open, (value) => {
    if (value) {
        provider.value = props.initialProvider ?? null;
    }
});

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
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="scrollbar-thin max-h-[90dvh] overflow-y-auto sm:max-w-2xl"
        >
            <DialogHeader>
                <DialogTitle class="font-display text-2xl">
                    {{
                        provider === 'imap'
                            ? 'Connect a mailbox over IMAP'
                            : provider === 'gmail'
                              ? 'Connect Gmail'
                              : 'Connect an account'
                    }}
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
                    <template v-else
                        >Works with any provider that speaks IMAP and SMTP:
                        Fastmail, Outlook, your own server. Passwords are
                        encrypted at rest.</template
                    >
                </DialogDescription>
            </DialogHeader>

            <div v-if="provider === null" class="grid gap-3 sm:grid-cols-2">
                <button
                    type="button"
                    class="group rounded-2xl border p-4 text-left transition hover:border-brand/60 hover:bg-brand-soft/30"
                    @click="provider = 'gmail'"
                >
                    <span
                        class="mb-3 flex size-10 items-center justify-center rounded-xl bg-(--chip)/15 text-(--chip) chip-rose"
                        ><Mail class="size-5"
                    /></span>
                    <p class="font-semibold">Gmail or Google Workspace</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Sign in with Google. Labels, threads and push
                        notifications come along for free.
                    </p>
                    <span
                        class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand"
                        >Continue
                        <ArrowRight
                            class="size-3.5 transition group-hover:translate-x-0.5"
                    /></span>
                </button>
                <button
                    type="button"
                    class="group rounded-2xl border p-4 text-left transition hover:border-brand/60 hover:bg-brand-soft/30"
                    @click="provider = 'imap'"
                >
                    <span
                        class="mb-3 flex size-10 items-center justify-center rounded-xl bg-(--chip)/15 text-(--chip) chip-sky"
                        ><Server class="size-5"
                    /></span>
                    <p class="font-semibold">IMAP / SMTP</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Any other mailbox. We poll for new mail every couple of
                        minutes and keep folders in sync.
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

            <div v-else class="space-y-4">
                <ImapAccountForm
                    :inbox-uuid="inbox?.uuid ?? null"
                    @saved="open = false"
                    @cancel="provider = null"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
