<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Loader2, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import ConfirmDialog from '@/components/settings/ConfirmDialog.vue';
import InboxFields from '@/components/settings/InboxFields.vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { destroy as destroyInbox, update as updateInbox } from '@/routes/settings/inboxes';
import type { InboxSettings } from '@/types';

const props = defineProps<{ organizationSlug: string; inbox: InboxSettings | null }>();
const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    name: '',
    email_address: '',
    color: 'violet',
    description: '',
});

const confirmDelete = ref(false);
const deleting = ref(false);

watch(
    () => [open.value, props.inbox] as const,
    ([isOpen, inbox]) => {
        if (isOpen && inbox) {
            form.clearErrors();
            form.name = inbox.name;
            form.email_address = inbox.email_address ?? '';
            form.color = inbox.color;
            form.description = inbox.description ?? '';
        }
    },
    { immediate: true },
);

function submit(): void {
    if (!props.inbox) {
        return;
    }

    form.patch(updateInbox.url([props.organizationSlug, props.inbox.uuid]), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

function destroy(): void {
    if (!props.inbox) {
        return;
    }

    deleting.value = true;
    router.delete(destroyInbox.url([props.organizationSlug, props.inbox.uuid]), {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            confirmDelete.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 p-0 sm:max-w-md">
            <form class="flex h-full flex-col" @submit.prevent="submit">
                <SheetHeader class="border-b px-6 py-5">
                    <SheetTitle class="font-display text-2xl">Edit inbox</SheetTitle>
                    <SheetDescription>Rename it, change the colour, or update the public address.</SheetDescription>
                </SheetHeader>

                <div class="flex-1 overflow-y-auto px-6 py-5 scrollbar-thin">
                    <InboxFields v-model:name="form.name" v-model:email-address="form.email_address" v-model:color="form.color" v-model:description="form.description" :errors="form.errors" id-prefix="edit-inbox" />

                    <div class="mt-8 rounded-xl border border-destructive/30 p-4">
                        <p class="text-sm font-semibold">Delete this inbox</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Removes the inbox, its channels and {{ inbox?.conversation_count ?? 0 }} conversations from Tandem. Mail at the provider is untouched.
                        </p>
                        <Button type="button" variant="outline" size="sm" class="mt-3 text-destructive hover:bg-destructive/10 hover:text-destructive" @click="confirmDelete = true">
                            <Trash2 /> Delete inbox
                        </Button>
                    </div>
                </div>

                <SheetFooter class="flex-row justify-end gap-2 border-t px-6 py-4">
                    <Button type="button" variant="ghost" @click="open = false">Cancel</Button>
                    <Button type="submit" class="bg-brand text-brand-foreground hover:bg-brand/90" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="animate-spin" />
                        Save changes
                    </Button>
                </SheetFooter>
            </form>
        </SheetContent>

        <ConfirmDialog
            v-model:open="confirmDelete"
            :title="`Delete ${inbox?.name ?? 'this inbox'}?`"
            description="Everyone loses access immediately and the conversations are gone from Tandem. This cannot be undone."
            confirm-label="Delete inbox"
            destructive
            :busy="deleting"
            @confirm="destroy"
        />
    </Sheet>
</template>
