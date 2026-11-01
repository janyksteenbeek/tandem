<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    KeyRound,
    Loader2,
    MoreHorizontal,
    PlugZap,
    RefreshCw,
    Send,
    Settings2,
    Signature,
    Trash2,
    Unplug,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import AccountHealthSheet from '@/components/settings/AccountHealthSheet.vue';
import ConfirmDialog from '@/components/settings/ConfirmDialog.vue';
import CopyField from '@/components/settings/CopyField.vue';
import IdentitiesDialog from '@/components/settings/IdentitiesDialog.vue';
import ImapAccountForm from '@/components/settings/ImapAccountForm.vue';
import ProviderBadge from '@/components/settings/ProviderBadge.vue';
import StatusPill from '@/components/settings/StatusPill.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usesForwardingAddress } from '@/lib/channels';
import { api, reportError } from '@/lib/http';
import { fullTime, relative } from '@/lib/time';
import {
    disconnect as disconnectRoute,
    remove as removeRoute,
    sync as syncRoute,
    test as testRoute,
} from '@/routes/mail/accounts';
import {
    destroy as destroyForwarding,
    test as testForwarding,
} from '@/routes/mail/forwarding';
import { redirect as gmailRedirect } from '@/routes/mail/gmail';
import type { ConnectionTestResponse, MailAccount } from '@/types';
import { useBranding } from '@/composables/useBranding';

const branding = useBranding();

const props = defineProps<{
    account: MailAccount;
    context: 'personal' | 'shared';
}>();

const syncing = ref(false);
const testing = ref(false);
const healthOpen = ref(false);
const identitiesOpen = ref(false);
const editOpen = ref(false);
const confirm = ref<'disconnect' | 'remove' | null>(null);
const confirmBusy = ref(false);

/** Forward and Google Group channels receive on a generated address instead of syncing a mailbox. */
const isForwarding = computed(() =>
    usesForwardingAddress(props.account.provider),
);
const needsReconnect = computed(
    () =>
        props.account.provider === 'gmail' &&
        ['needs_reauth', 'disconnected', 'error'].includes(
            props.account.status,
        ),
);
const reconnectUrl = computed(() =>
    gmailRedirect.url(
        props.context === 'shared'
            ? { query: { inbox: props.account.inbox.uuid } }
            : undefined,
    ),
);
const canSync = computed(
    () =>
        props.account.status !== 'needs_reauth' &&
        props.account.status !== 'disconnected',
);

const syncedCopy = computed(() => {
    if (isForwarding.value) {
        const received = `${props.account.received_count} received`;

        return props.account.last_synced_at
            ? `${received} · last ${relative(props.account.last_synced_at)}`
            : `${received} · nothing has arrived yet`;
    }

    if (
        props.account.status === 'syncing' ||
        (props.account.last_sync_started_at && !props.account.last_synced_at)
    ) {
        return 'Syncing right now';
    }

    if (!props.account.last_synced_at) {
        return props.account.initial_sync_completed_at
            ? 'Not synced recently'
            : 'First sync has not finished yet';
    }

    return `Synced ${relative(props.account.last_synced_at)}`;
});

async function syncNow(): Promise<void> {
    syncing.value = true;

    try {
        const result = await api.post<{ queued: boolean; type: string }>(
            syncRoute.url(props.account.uuid),
        );
        toast.success(
            result.type === 'initial'
                ? 'Full sync queued. The first pass can take a few minutes.'
                : 'Sync queued. New mail lands in a moment.',
        );
        setTimeout(
            () =>
                router.reload({ only: ['personalAccounts', 'sharedInboxes'] }),
            2500,
        );
    } catch (error) {
        reportError(error, 'Could not queue a sync.');
    } finally {
        syncing.value = false;
    }
}

async function testConnection(): Promise<void> {
    testing.value = true;

    try {
        if (isForwarding.value) {
            await api.post(testForwarding.url(props.account.uuid));
            toast.success(
                `Test sent to ${props.account.email}. Give it a minute, then look for “${branding.name} forwarding test”.`,
            );

            return;
        }

        const result = await api.post<ConnectionTestResponse>(
            testRoute.url(props.account.uuid),
        );

        if (result.ok) {
            toast.success(result.message ?? 'Connection looks good.');
        } else {
            toast.error(result.message ?? 'The provider did not answer.');
        }
    } catch (error) {
        reportError(error, 'Could not test the connection.');
    } finally {
        testing.value = false;
    }
}

function runConfirm(): void {
    confirmBusy.value = true;
    const options = {
        preserveScroll: true,
        onFinish: () => ((confirmBusy.value = false), (confirm.value = null)),
    };

    if (confirm.value === 'disconnect') {
        router.post(disconnectRoute.url(props.account.uuid), {}, options);
    } else if (confirm.value === 'remove' && isForwarding.value) {
        router.delete(destroyForwarding.url(props.account.uuid), options);
    } else if (confirm.value === 'remove') {
        router.delete(removeRoute.url(props.account.uuid), options);
    }
}
</script>

<template>
    <article
        class="rounded-2xl border bg-card p-4 shadow-pane transition hover:shadow-float sm:p-5"
        :class="
            !account.healthy &&
            account.status !== 'pending' &&
            'border-warning/50'
        "
    >
        <div class="flex items-start gap-3">
            <ProviderBadge
                :provider="account.provider"
                variant="icon"
                size="lg"
                class="mt-0.5"
            />
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <h3 class="truncate text-base font-semibold">
                        {{ account.email }}
                    </h3>
                    <StatusPill
                        :status="account.status"
                        :label="account.status_label"
                    />
                </div>
                <p
                    class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground"
                >
                    <span v-if="account.display_name">{{
                        account.display_name
                    }}</span>
                    <span v-if="account.display_name" aria-hidden="true"
                        >·</span
                    >
                    <span>{{ account.provider_label }}</span>
                    <template v-if="context === 'shared'">
                        <span aria-hidden="true">·</span>
                        <span>in {{ account.inbox.name }}</span>
                    </template>
                </p>
                <CopyField
                    v-if="isForwarding"
                    :value="account.email"
                    size="sm"
                    class="mt-2"
                    :toast-message="
                        account.provider === 'google_group'
                            ? 'Address copied. Add it to the group as a member.'
                            : 'Address copied. Paste it into your forwarding rule.'
                    "
                />
                <p
                    class="mt-1 text-xs text-muted-foreground"
                    :title="fullTime(account.last_synced_at)"
                >
                    {{ syncedCopy }}
                    <template v-if="account.identities.length">
                        · {{ account.identities.length }}
                        {{
                            account.identities.length === 1
                                ? 'identity'
                                : 'identities'
                        }}</template
                    >
                    <template
                        v-if="
                            account.provider === 'gmail' &&
                            account.watch_expires_at
                        "
                    >
                        · push renews
                        {{ relative(account.watch_expires_at) }}</template
                    >
                </p>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label="More actions"
                        ><MoreHorizontal
                    /></Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-56">
                    <DropdownMenuItem
                        v-if="!isForwarding"
                        @select="healthOpen = true"
                        ><Activity /> Health details</DropdownMenuItem
                    >
                    <DropdownMenuItem
                        v-if="!isForwarding"
                        @select="identitiesOpen = true"
                        ><Signature /> Identities &amp;
                        signature</DropdownMenuItem
                    >
                    <DropdownMenuItem
                        v-if="account.provider === 'imap'"
                        @select="editOpen = true"
                        ><Settings2 /> Edit connection</DropdownMenuItem
                    >
                    <DropdownMenuSeparator v-if="!isForwarding" />
                    <DropdownMenuItem
                        v-if="
                            !isForwarding && account.status !== 'disconnected'
                        "
                        @select="confirm = 'disconnect'"
                        ><Unplug /> Disconnect</DropdownMenuItem
                    >
                    <DropdownMenuItem
                        class="text-destructive focus:text-destructive"
                        @select="confirm = 'remove'"
                        ><Trash2 />
                        {{
                            isForwarding
                                ? 'Remove channel'
                                : `Remove from ${branding.name}`
                        }}</DropdownMenuItem
                    >
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <div
            v-if="account.last_error && !account.healthy"
            class="mt-3 flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/8 px-3 py-2 text-xs"
        >
            <AlertTriangle class="mt-0.5 size-3.5 shrink-0 text-destructive" />
            <div class="min-w-0">
                <p class="font-medium text-destructive">
                    {{
                        account.status === 'needs_reauth'
                            ? 'Google wants you to sign in again.'
                            : `Sync failed ${account.error_count} ${account.error_count === 1 ? 'time' : 'times'} in a row.`
                    }}
                </p>
                <p
                    class="truncate font-mono text-[11px] text-foreground/70"
                    :title="account.last_error"
                >
                    {{ account.last_error }}
                </p>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <Button
                v-if="needsReconnect"
                as-child
                size="sm"
                class="bg-brand text-brand-foreground hover:bg-brand/90"
            >
                <a :href="reconnectUrl"><KeyRound /> Reconnect Google</a>
            </Button>
            <Button
                v-if="!isForwarding"
                variant="outline"
                size="sm"
                :disabled="syncing || !canSync"
                @click="syncNow"
            >
                <Loader2 v-if="syncing" class="animate-spin" />
                <RefreshCw v-else />
                Sync now
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="testing"
                @click="testConnection"
            >
                <Loader2 v-if="testing" class="animate-spin" />
                <Send v-else-if="isForwarding" />
                <PlugZap v-else />
                {{ isForwarding ? 'Send test email' : 'Test connection' }}
            </Button>
            <Button
                v-if="!isForwarding"
                variant="ghost"
                size="sm"
                class="hidden sm:inline-flex"
                @click="healthOpen = true"
                ><Activity /> Health</Button
            >
            <Button
                v-if="!isForwarding"
                variant="ghost"
                size="sm"
                class="hidden sm:inline-flex"
                @click="identitiesOpen = true"
                ><Signature /> Identities</Button
            >
        </div>

        <AccountHealthSheet v-model:open="healthOpen" :account="account" />
        <IdentitiesDialog v-model:open="identitiesOpen" :account="account" />

        <Dialog v-if="account.provider === 'imap'" v-model:open="editOpen">
            <DialogContent
                class="scrollbar-thin max-h-[90dvh] overflow-y-auto sm:max-w-2xl"
            >
                <DialogHeader>
                    <DialogTitle class="font-display text-2xl"
                        >Edit connection</DialogTitle
                    >
                    <DialogDescription
                        >Change servers or credentials for {{ account.email }}.
                        We test the new settings before saving
                        them.</DialogDescription
                    >
                </DialogHeader>
                <ImapAccountForm
                    :account="account"
                    @saved="editOpen = false"
                    @cancel="editOpen = false"
                />
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="confirm !== null"
            :title="
                confirm === 'remove'
                    ? isForwarding
                        ? 'Remove this channel?'
                        : 'Remove this account?'
                    : 'Disconnect this account?'
            "
            :description="
                confirm === 'remove'
                    ? isForwarding
                        ? `Mail sent to ${account.email} will bounce from now on.`
                        : 'Received conversations stay. Nothing is deleted at the provider.'
                    : 'Syncing stops. You can reconnect any time.'
            "
            :confirm-label="
                confirm === 'remove'
                    ? isForwarding
                        ? 'Remove channel'
                        : 'Remove account'
                    : 'Disconnect'
            "
            :destructive="confirm === 'remove'"
            :busy="confirmBusy"
            @update:open="(value) => !value && (confirm = null)"
            @confirm="runConfirm"
        />
    </article>
</template>
