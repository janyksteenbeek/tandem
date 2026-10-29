<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Maximize2, Minimize2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Composer from '@/components/mail/Composer.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useDraft } from '@/composables/useDraft';
import { reportError } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { Draft, Inbox, NavInbox } from '@/types';

const props = defineProps<{
    inbox: Inbox | null;
    inboxes: NavInbox[];
    defaultTo?: string | null;
}>();
const open = defineModel<boolean>('open', { default: false });

const page = usePage();
const expanded = ref(false);
const minimized = ref(false);
const draft = ref<Draft | null>(null);
const accounts = ref<Inbox['accounts']>([]);
const selectedInbox = ref<string>(
    props.inbox?.uuid && props.inbox.uuid !== 'all'
        ? props.inbox.uuid
        : (props.inboxes[0]?.uuid ?? ''),
);
const { create } = useDraft();

const candidates = computed(() =>
    props.inboxes.filter((i) => i.providers.length > 0 || i.type === 'shared'),
);
const isShared = computed(
    () =>
        props.inboxes.find((i) => i.uuid === selectedInbox.value)?.type ===
        'shared',
);

const start = async () => {
    if (!selectedInbox.value) {
        return;
    }

    try {
        const created = await create({
            inbox: selectedInbox.value,
            kind: 'new',
            is_shared: false,
        });

        if (props.defaultTo) {
            created.to = [{ name: null, email: props.defaultTo }];
        }

        draft.value = created;
        accounts.value =
            props.inbox && props.inbox.uuid === selectedInbox.value
                ? props.inbox.accounts
                : [];
    } catch (error) {
        reportError(error, 'Could not start a new message.');
        open.value = false;
    }
};

watch(open, (value) => {
    if (value && !draft.value) {
        void start();
    }

    if (!value) {
        minimized.value = false;
    }
});

watch(selectedInbox, async () => {
    if (open.value && draft.value) {
        draft.value = null;
        await start();
    }
});

const onSent = (payload: { conversation_uuid: string }) => {
    draft.value = null;
    open.value = false;
    const slug = page.props.currentOrganization?.slug;

    if (slug) {
        router.visit(
            `/${slug}/mail/${selectedInbox.value}/sent/${payload.conversation_uuid}`,
        );
    }
};

const close = () => {
    open.value = false;
    draft.value = null;
};
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            :class="
                cn(
                    'fixed z-50 flex animate-rise flex-col rounded-t-2xl border bg-card shadow-float transition-all',
                    expanded
                        ? 'inset-x-4 top-8 bottom-0 md:inset-x-[12%]'
                        : minimized
                          ? 'right-6 bottom-0 w-80'
                          : 'right-6 bottom-0 w-[min(680px,calc(100vw-2rem))]',
                )
            "
        >
            <div
                class="flex items-center gap-2 rounded-t-2xl bg-primary px-4 py-2 text-primary-foreground"
            >
                <span class="text-sm font-semibold">New message</span>
                <Select
                    v-if="candidates.length > 1 && !minimized"
                    v-model="selectedInbox"
                >
                    <SelectTrigger
                        class="ml-2 h-7 w-auto gap-1 border-primary-foreground/20 bg-primary-foreground/10 px-2 text-xs text-primary-foreground"
                    >
                        <SelectValue placeholder="From inbox" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="inbox in candidates"
                            :key="inbox.uuid"
                            :value="inbox.uuid"
                            >{{ inbox.name }}
                            <span class="text-muted-foreground">{{
                                inbox.email ? `· ${inbox.email}` : ''
                            }}</span></SelectItem
                        >
                    </SelectContent>
                </Select>
                <span class="ml-auto flex items-center gap-0.5">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7 text-primary-foreground hover:bg-primary-foreground/10 hover:text-primary-foreground"
                        @click="
                            minimized = !minimized;
                            expanded = false;
                        "
                        ><Minimize2 class="size-3.5"
                    /></Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7 text-primary-foreground hover:bg-primary-foreground/10 hover:text-primary-foreground"
                        @click="
                            expanded = !expanded;
                            minimized = false;
                        "
                        ><Maximize2 class="size-3.5"
                    /></Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7 text-primary-foreground hover:bg-primary-foreground/10 hover:text-primary-foreground"
                        @click="close"
                        ><X class="size-3.5"
                    /></Button>
                </span>
            </div>
            <div v-show="!minimized" class="flex-1 overflow-y-auto">
                <Composer
                    v-if="draft"
                    :key="draft.uuid"
                    :draft="draft"
                    :accounts="accounts"
                    :is-shared="Boolean(isShared)"
                    show-subject
                    @sent="onSent"
                    @discarded="close"
                />
                <div v-else class="space-y-3 p-4">
                    <div class="h-8 shimmer rounded" />
                    <div class="h-8 shimmer rounded" />
                    <div class="h-40 shimmer rounded" />
                </div>
            </div>
        </div>
    </Teleport>
</template>
