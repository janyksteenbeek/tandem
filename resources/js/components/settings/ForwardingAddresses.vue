<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { Forward, Loader2, Plus, Send, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/settings/ConfirmDialog.vue';
import CopyField from '@/components/settings/CopyField.vue';
import StatusPill from '@/components/settings/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { api, reportError } from '@/lib/http';
import { destroy as destroyForwarding, store as storeForwarding, test as testForwarding } from '@/routes/mail/forwarding';
import type { MailAccount } from '@/types';

defineProps<{ inbox: { uuid: string; name: string }; accounts: MailAccount[]; forwardingDomain: string }>();

const generateOpen = ref(false);
const testing = ref<string | null>(null);
const removing = ref<MailAccount | null>(null);
const removeBusy = ref(false);

async function sendTest(account: MailAccount): Promise<void> {
    testing.value = account.uuid;

    try {
        await api.post(testForwarding.url(account.uuid));
        toast.success(`Test sent to ${account.email}. Give it a minute, then look for “Tandem forwarding test”.`);
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
            <div class="min-w-0">
                <p class="flex items-center gap-1.5 text-sm font-semibold"><Forward class="size-4 text-(--chip) chip-teal" /> Forwarding addresses</p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Point your Google Group, distribution list or provider's auto-forward at one of these. Anything that lands there shows up in {{ inbox.name }} within seconds.
                </p>
            </div>
            <Popover v-model:open="generateOpen">
                <PopoverTrigger as-child>
                    <Button size="sm" variant="outline"><Plus /> Generate address</Button>
                </PopoverTrigger>
                <PopoverContent align="end" class="w-72">
                    <Form v-bind="storeForwarding.form(inbox.uuid)" :options="{ preserveScroll: true }" class="space-y-3" v-slot="{ processing, errors }" @success="generateOpen = false">
                        <div class="grid gap-1.5">
                            <Label for="forwarding-label">Label <span class="font-normal text-muted-foreground">(optional)</span></Label>
                            <Input id="forwarding-label" name="label" placeholder="e.g. support@ Google Group" maxlength="80" />
                            <p v-if="errors.label" class="text-xs text-destructive">{{ errors.label }}</p>
                            <p class="text-xs text-muted-foreground">A random address on {{ forwardingDomain }} is generated for you.</p>
                        </div>
                        <Button type="submit" size="sm" class="w-full bg-brand text-brand-foreground hover:bg-brand/90" :disabled="processing">
                            <Loader2 v-if="processing" class="animate-spin" />
                            Generate
                        </Button>
                    </Form>
                </PopoverContent>
            </Popover>
        </div>

        <ul v-if="accounts.length > 0" class="mt-3 divide-y border-t">
            <li v-for="account in accounts" :key="account.uuid" class="grid gap-2 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                <div class="min-w-0 space-y-1.5">
                    <CopyField :value="account.email" size="sm" toast-message="Address copied. Paste it into your forwarding rule." />
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <span v-if="account.display_name" class="font-medium text-foreground">{{ account.display_name }}</span>
                        <span>{{ account.received_count }} received</span>
                        <StatusPill :status="account.status" :label="account.status_label" size="sm" />
                    </p>
                </div>
                <div class="flex items-center gap-1 sm:justify-end">
                    <Button variant="ghost" size="sm" :disabled="testing === account.uuid" @click="sendTest(account)">
                        <Loader2 v-if="testing === account.uuid" class="animate-spin" />
                        <Send v-else />
                        Send test email
                    </Button>
                    <Button variant="ghost" size="icon-sm" class="text-muted-foreground hover:text-destructive" aria-label="Remove forwarding address" @click="removing = account">
                        <Trash2 />
                    </Button>
                </div>
            </li>
        </ul>
        <p v-else class="mx-4 mt-3 mb-4 rounded-lg border border-dashed px-3 py-3 text-xs text-muted-foreground">
            No forwarding address yet. Generate one, then forward your existing address to it. Handy when you cannot hand over the mailbox password.
        </p>
        <p class="border-t px-4 py-2 text-[11px] text-muted-foreground">
            Replies go out through the organization's outbound SMTP server (see Integrations). See the docs on forwarding for Google Groups and Microsoft 365 set-up.
        </p>

        <ConfirmDialog
            :open="removing !== null"
            title="Remove this address?"
            :description="`Mail sent to ${removing?.email ?? 'this address'} will bounce from now on. Conversations already received stay in ${inbox.name}.`"
            confirm-label="Remove address"
            destructive
            :busy="removeBusy"
            @update:open="(value) => !value && (removing = null)"
            @confirm="confirmRemove"
        />
    </div>
</template>
