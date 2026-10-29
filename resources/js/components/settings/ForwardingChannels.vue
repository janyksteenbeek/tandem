<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Loader2, Plus, Send, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/settings/ConfirmDialog.vue';
import CopyField from '@/components/settings/CopyField.vue';
import ProviderBadge from '@/components/settings/ProviderBadge.vue';
import StatusPill from '@/components/settings/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { FORWARDING_CHANNELS, PROVIDER_META } from '@/lib/channels';
import { api, reportError } from '@/lib/http';
import {
    destroy as destroyForwarding,
    test as testForwarding,
} from '@/routes/mail/forwarding';
import type { ForwardingChannel, MailAccount } from '@/types';

defineProps<{
    inbox: { uuid: string; name: string };
    accounts: MailAccount[];
    forwardingDomain: string;
}>();

const emit = defineEmits<{ add: [channel: ForwardingChannel] }>();

const testing = ref<string | null>(null);
const removing = ref<MailAccount | null>(null);
const removeBusy = ref(false);

async function sendTest(account: MailAccount): Promise<void> {
    testing.value = account.uuid;

    try {
        await api.post(testForwarding.url(account.uuid));
        toast.success(
            `Test sent to ${account.email}. Give it a minute, then look for “Tandem forwarding test”.`,
        );
    } catch (error) {
        reportError(error, 'The test message could not be sent.');
    } finally {
        testing.value = null;
    }
}

function confirmRemove(): void {
    if (!removing.value) {
        return;
    }

    removeBusy.value = true;
    router.delete(destroyForwarding.url(removing.value.uuid), {
        preserveScroll: true,
        onFinish: () => {
            removeBusy.value = false;
            removing.value = null;
        },
    });
}
</script>

<template>
    <div class="rounded-xl border bg-surface">
        <div class="flex flex-wrap items-start justify-between gap-3 px-4 pt-4">
            <div class="min-w-0 flex-1 basis-64">
                <p class="text-sm font-semibold">Google Group &amp; Forward</p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Both channels deliver to a private address on
                    {{ forwardingDomain }}. Anything that lands there shows up
                    in {{ inbox.name }} within seconds.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="channel in FORWARDING_CHANNELS"
                    :key="channel"
                    size="sm"
                    variant="outline"
                    @click="emit('add', channel)"
                >
                    <Plus /> {{ PROVIDER_META[channel].label }}
                </Button>
            </div>
        </div>

        <ul v-if="accounts.length > 0" class="mt-3 divide-y border-t">
            <li
                v-for="account in accounts"
                :key="account.uuid"
                class="grid gap-2 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
            >
                <div class="min-w-0 space-y-1.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <ProviderBadge :provider="account.provider" />
                        <span
                            v-if="account.display_name"
                            class="truncate text-sm font-medium"
                            >{{ account.display_name }}</span
                        >
                    </div>
                    <CopyField
                        :value="account.email"
                        size="sm"
                        :toast-message="
                            account.provider === 'google_group'
                                ? 'Address copied. Add it to the group as a member.'
                                : 'Address copied. Paste it into your forwarding rule.'
                        "
                    />
                    <p
                        class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
                    >
                        <span>{{ account.received_count }} received</span>
                        <StatusPill
                            :status="account.status"
                            :label="account.status_label"
                            size="sm"
                        />
                    </p>
                </div>
                <div class="flex items-center gap-1 sm:justify-end">
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="testing === account.uuid"
                        @click="sendTest(account)"
                    >
                        <Loader2
                            v-if="testing === account.uuid"
                            class="animate-spin"
                        />
                        <Send v-else />
                        Send test email
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        class="text-muted-foreground hover:text-destructive"
                        :aria-label="`Remove ${PROVIDER_META[account.provider].label} channel`"
                        @click="removing = account"
                    >
                        <Trash2 />
                    </Button>
                </div>
            </li>
        </ul>
        <p
            v-else
            class="mx-4 mt-3 mb-4 rounded-lg border border-dashed px-3 py-3 text-xs text-muted-foreground"
        >
            Nothing here yet. Add a Google Group when the team already answers
            from one, or a Forward for any other address you cannot hand over.
        </p>
        <p class="border-t px-4 py-2 text-[11px] text-muted-foreground">
            Replies go out through the organization's outbound SMTP server (see
            Integrations) and show the inbox address as sender.
        </p>

        <ConfirmDialog
            :open="removing !== null"
            title="Remove this channel?"
            :description="`Mail sent to ${removing?.email ?? 'this address'} will bounce from now on. Conversations already received stay in ${inbox.name}.`"
            confirm-label="Remove channel"
            destructive
            :busy="removeBusy"
            @update:open="(value) => !value && (removing = null)"
            @confirm="confirmRemove"
        />
    </div>
</template>
