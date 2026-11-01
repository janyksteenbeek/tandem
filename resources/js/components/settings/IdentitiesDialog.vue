<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import DOMPurify from 'dompurify';
import { Loader2, Pencil, Plus, Star, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    destroy as destroyIdentity,
    store as storeIdentity,
    update as updateIdentity,
} from '@/routes/mail/identities';
import type { MailAccount, SenderIdentity } from '@/types';

const props = defineProps<{ account: MailAccount }>();
const open = defineModel<boolean>('open', { default: false });

const editing = ref<SenderIdentity | 'new' | null>(null);
const deleting = ref<number | null>(null);

const form = useForm({
    name: '',
    email: '',
    reply_to: '',
    signature_html: '',
    is_default: false,
});

watch(open, (value) => {
    if (!value) {
        editing.value = null;
        form.reset();
        form.clearErrors();
    }
});

function startNew(): void {
    form.reset();
    form.clearErrors();
    form.name = props.account.display_name ?? '';
    form.email = props.account.email;
    form.is_default = props.account.identities.length === 0;
    editing.value = 'new';
}

function startEdit(identity: SenderIdentity): void {
    form.clearErrors();
    form.name = identity.name;
    form.email = identity.email;
    form.reply_to = identity.reply_to ?? '';
    form.signature_html = identity.signature_html ?? '';
    form.is_default = identity.is_default;
    editing.value = identity;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    };

    if (editing.value === 'new') {
        form.post(storeIdentity.url(props.account.uuid), options);
    } else if (editing.value) {
        form.patch(updateIdentity.url(editing.value.id), options);
    }
}

function remove(identity: SenderIdentity): void {
    deleting.value = identity.id;
    router.delete(destroyIdentity.url(identity.id), {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
}

const preview = computed(() =>
    DOMPurify.sanitize(form.signature_html, { USE_PROFILES: { html: true } }),
);
const sortedIdentities = computed(() =>
    [...props.account.identities].sort(
        (a, b) => Number(b.is_default) - Number(a.is_default),
    ),
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="scrollbar-thin max-h-[90dvh] overflow-y-auto sm:max-w-2xl"
        >
            <DialogHeader>
                <DialogTitle class="font-display text-2xl"
                    >Identities &amp; signature</DialogTitle
                >
                <DialogDescription>
                    “From” addresses for {{ account.email }}.
                </DialogDescription>
            </DialogHeader>

            <template v-if="editing === null">
                <ul class="divide-y rounded-xl border">
                    <li
                        v-for="identity in sortedIdentities"
                        :key="identity.id"
                        class="flex items-start gap-3 px-4 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <p
                                class="flex flex-wrap items-center gap-2 text-sm font-medium"
                            >
                                {{ identity.name }}
                                <Badge
                                    v-if="identity.is_default"
                                    class="gap-1 bg-highlight/25 text-highlight-foreground"
                                    ><Star class="size-3 fill-current" />
                                    Default</Badge
                                >
                            </p>
                            <p class="truncate text-sm text-muted-foreground">
                                {{ identity.email
                                }}<template v-if="identity.reply_to">
                                    · replies to
                                    {{ identity.reply_to }}</template
                                >
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{
                                    identity.signature_html
                                        ? 'Has a signature'
                                        : 'No signature'
                                }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Edit identity"
                                @click="startEdit(identity)"
                                ><Pencil
                            /></Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-muted-foreground hover:text-destructive"
                                aria-label="Remove identity"
                                :disabled="
                                    account.identities.length <= 1 ||
                                    deleting === identity.id
                                "
                                :title="
                                    account.identities.length <= 1
                                        ? 'An account needs at least one identity'
                                        : 'Remove'
                                "
                                @click="remove(identity)"
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    </li>
                    <li
                        v-if="account.identities.length === 0"
                        class="px-4 py-6 text-center text-sm text-muted-foreground"
                    >
                        No identities yet. Add one so you can send from this
                        account.
                    </li>
                </ul>
                <div class="flex justify-end">
                    <Button variant="outline" @click="startNew"
                        ><Plus /> Add identity</Button
                    >
                </div>
            </template>

            <form v-else class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="identity-name">Name</Label>
                        <Input
                            id="identity-name"
                            v-model="form.name"
                            required
                            placeholder="Jamie at Northwind"
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="identity-email">Email address</Label>
                        <Input
                            id="identity-email"
                            v-model="form.email"
                            type="email"
                            required
                            placeholder="support@company.com"
                        />
                        <p class="text-xs text-muted-foreground">
                            An alias or group this mailbox can send as.
                        </p>
                        <InputError :message="form.errors.email" />
                    </div>
                </div>
                <div class="grid gap-1.5">
                    <Label for="identity-reply-to"
                        >Reply-to
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></Label
                    >
                    <Input
                        id="identity-reply-to"
                        v-model="form.reply_to"
                        type="email"
                        placeholder="Leave blank to receive replies at the address above"
                    />
                    <InputError :message="form.errors.reply_to" />
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="identity-signature">Signature (HTML)</Label>
                        <Textarea
                            id="identity-signature"
                            v-model="form.signature_html"
                            rows="8"
                            class="min-h-44 font-mono text-xs"
                            placeholder="<p>Jamie Okafor<br>Northwind Labs</p>"
                        />
                        <InputError :message="form.errors.signature_html" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Preview</Label>
                        <div
                            class="prose-tandem min-h-44 rounded-md border bg-card p-3 text-sm"
                        >
                            <div v-if="form.signature_html" v-html="preview" />
                            <p v-else class="text-sm text-muted-foreground">
                                Preview
                            </p>
                        </div>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.is_default"
                        @update:model-value="
                            (value) => (form.is_default = value === true)
                        "
                    />
                    Use as the default identity for this account
                </label>
                <div class="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        @click="editing = null"
                        >Back</Button
                    >
                    <Button
                        type="submit"
                        class="bg-brand text-brand-foreground hover:bg-brand/90"
                        :disabled="form.processing"
                    >
                        <Loader2 v-if="form.processing" class="animate-spin" />
                        {{
                            editing === 'new' ? 'Add identity' : 'Save identity'
                        }}
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
