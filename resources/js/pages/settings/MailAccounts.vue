<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Inbox, Mail, Plus, Server } from '@lucide/vue';
import { computed, ref } from 'vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import ConnectAccountDialog from '@/components/settings/ConnectAccountDialog.vue';
import ForwardingChannels from '@/components/settings/ForwardingChannels.vue';
import MailAccountCard from '@/components/settings/MailAccountCard.vue';
import SettingsEmpty from '@/components/settings/SettingsEmpty.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { usesForwardingAddress } from '@/lib/channels';
import { index as mailAccountsIndex } from '@/routes/mail/accounts';
import { index as inboxesIndex } from '@/routes/settings/inboxes';
import type { MailAccount, MailProvider, SharedInboxChannels } from '@/types';

const props = defineProps<{
    personalAccounts: MailAccount[];
    sharedInboxes: SharedInboxChannels[];
    forwardingDomain: string;
    googleConfigured: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mail accounts', href: mailAccountsIndex() }],
    },
});

const page = usePage();
const organization = computed(() => page.props.currentOrganization);

const connectOpen = ref(false);
const connectTarget = ref<{ uuid: string; name: string } | null>(null);
const connectProvider = ref<MailProvider | null>(null);

function connect(
    inbox: { uuid: string; name: string } | null = null,
    provider: MailProvider | null = null,
): void {
    connectTarget.value = inbox;
    connectProvider.value = provider;
    connectOpen.value = true;
}

const unhealthy = computed(
    () =>
        props.personalAccounts.filter(
            (account) => !account.healthy && account.status !== 'pending',
        ).length,
);

/** Mailboxes connected directly (Gmail, IMAP). */
const mailboxesOf = (inbox: SharedInboxChannels): MailAccount[] =>
    inbox.accounts.filter(
        (account) => !usesForwardingAddress(account.provider),
    );
/** Google Group and Forward channels, which receive on a generated address. */
const forwardingOf = (inbox: SharedInboxChannels): MailAccount[] =>
    inbox.accounts.filter((account) => usesForwardingAddress(account.provider));
</script>

<template>
    <Head title="Mail accounts" />

    <h1 class="sr-only">Mail accounts</h1>

    <div class="space-y-12">
        <SettingsSection
            title="Personal accounts"
            :count="personalAccounts.length"
            :description="
                personalAccounts.length === 0
                    ? 'Nothing is syncing because nothing is connected yet.'
                    : unhealthy > 0
                      ? `${unhealthy} ${unhealthy === 1 ? 'account needs' : 'accounts need'} attention.`
                      : 'Everything here is yours alone. Teammates never see it unless you share a conversation.'
            "
        >
            <template #actions>
                <Button
                    class="bg-brand text-brand-foreground shadow-float hover:bg-brand/90"
                    @click="connect()"
                    ><Plus /> Connect account</Button
                >
            </template>

            <SettingsEmpty
                v-if="personalAccounts.length === 0"
                :icon="Mail"
                title="Your inbox is waiting for you."
                body="Connect Gmail or any IMAP mailbox and the last 90 days of mail arrive within minutes. Your personal mail stays private to you."
            >
                <Button variant="outline" @click="connect(null, 'gmail')"
                    ><Mail /> Connect Gmail</Button
                >
                <Button variant="outline" @click="connect(null, 'imap')"
                    ><Server /> Connect IMAP</Button
                >
            </SettingsEmpty>

            <div v-else class="grid gap-3">
                <MailAccountCard
                    v-for="account in personalAccounts"
                    :key="account.uuid"
                    :account="account"
                    context="personal"
                />
            </div>
        </SettingsSection>

        <SettingsSection
            title="Shared inboxes you manage"
            :count="sharedInboxes.length"
            description="Channels feed mail into a team inbox. Connect Gmail or IMAP directly, add a Google Group, or forward an existing address."
        >
            <SettingsEmpty
                v-if="sharedInboxes.length === 0"
                :icon="Inbox"
                title="No shared inboxes to manage."
                :body="
                    organization
                        ? 'Inboxes where you are an admin show up here with their channels.'
                        : 'Join or create an organization to set up shared inboxes for your team.'
                "
            >
                <Button
                    v-if="organization && page.props.canManageOrganization"
                    as-child
                    variant="outline"
                >
                    <Link :href="inboxesIndex(organization.slug)"
                        >Go to Inboxes</Link
                    >
                </Button>
            </SettingsEmpty>

            <div v-else class="space-y-6">
                <article
                    v-for="inbox in sharedInboxes"
                    :key="inbox.uuid"
                    class="space-y-3 rounded-2xl border bg-surface/60 p-4 sm:p-5"
                >
                    <header class="flex flex-wrap items-center gap-3">
                        <InboxDot :color="inbox.color" class="size-3" />
                        <div class="min-w-0 flex-1">
                            <h3 class="truncate text-base font-semibold">
                                {{ inbox.name }}
                            </h3>
                            <p class="truncate text-sm text-muted-foreground">
                                {{
                                    inbox.email_address ??
                                    'No public address yet'
                                }}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="connect(inbox)"
                            ><Plus /> Add channel</Button
                        >
                    </header>

                    <div
                        v-if="mailboxesOf(inbox).length > 0"
                        class="grid gap-3"
                    >
                        <MailAccountCard
                            v-for="account in mailboxesOf(inbox)"
                            :key="account.uuid"
                            :account="account"
                            context="shared"
                        />
                    </div>
                    <p
                        v-else
                        class="rounded-xl border border-dashed bg-card px-4 py-3 text-sm text-muted-foreground"
                    >
                        No mailbox connected directly. Add Gmail or IMAP above,
                        or use a Google Group or Forward channel below if you
                        would rather not share a password.
                    </p>

                    <ForwardingChannels
                        :inbox="inbox"
                        :accounts="forwardingOf(inbox)"
                        :forwarding-domain="forwardingDomain"
                        @add="(channel) => connect(inbox, channel)"
                    />
                </article>
            </div>
        </SettingsSection>
    </div>

    <ConnectAccountDialog
        v-model:open="connectOpen"
        :inbox="connectTarget"
        :google-configured="googleConfigured"
        :forwarding-domain="forwardingDomain"
        :initial-provider="connectProvider"
    />
</template>
