<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Inbox, Layers, Mail, PenLine, Search, Settings, Users, Workflow } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import { api } from '@/lib/http';
import { displayName } from '@/lib/mail';
import { listTime } from '@/lib/time';
import type { ConversationListItem, MailNav } from '@/types';

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ compose: [] }>();

const page = usePage();
const nav = computed<MailNav | null>(() => page.props.mailNav ?? null);
const organization = computed(() => page.props.currentOrganization);
const slug = computed(() => organization.value?.slug ?? '');

const query = ref('');
const results = ref<ConversationListItem[]>([]);
const searching = ref(false);

const search = useDebounceFn(async (term: string) => {
    if (term.trim().length < 2) {
        results.value = [];

        return;
    }

    searching.value = true;

    try {
        const data = await api.get<{ conversations: ConversationListItem[] }>('/search', { q: term, limit: 8 });
        results.value = data.conversations;
    } finally {
        searching.value = false;
    }
}, 220);

watch(query, (value) => void search(value));
watch(open, (value) => {
    if (!value) {
        query.value = '';
        results.value = [];
    }
});

const go = (url: string) => {
    open.value = false;
    router.visit(url);
};

const openConversation = (item: ConversationListItem) => go(`/${slug.value}/mail/${item.inbox_uuid}/all/${item.uuid}`);
</script>

<template>
    <CommandDialog v-model:open="open">
        <CommandInput v-model="query" placeholder="Search mail, jump to an inbox, or run a command…" />
        <CommandList class="max-h-[60vh]">
            <CommandEmpty>
                <span v-if="searching">Searching…</span>
                <span v-else-if="query.length >= 2">No conversations match “{{ query }}”.</span>
                <span v-else>Type to search across every account.</span>
            </CommandEmpty>

            <CommandGroup v-if="results.length" heading="Conversations">
                <CommandItem v-for="item in results" :key="item.uuid" :value="`conv-${item.uuid}`" class="gap-3" @select="openConversation(item)">
                    <UserAvatar :name="item.counterpart?.name" :email="item.counterpart?.email" size="sm" />
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2">
                            <span class="truncate text-sm font-medium">{{ displayName(item.counterpart) }}</span>
                            <span class="truncate text-xs text-muted-foreground">{{ item.inbox_name }}</span>
                        </span>
                        <span class="block truncate text-xs text-muted-foreground">{{ item.subject }}</span>
                    </span>
                    <span class="text-[11px] text-muted-foreground">{{ listTime(item.last_message_at) }}</span>
                </CommandItem>
            </CommandGroup>

            <CommandSeparator v-if="results.length" />

            <CommandGroup heading="Actions">
                <CommandItem value="compose" class="gap-2" @select="open = false; emit('compose')">
                    <PenLine class="size-4" /> Compose new message
                    <kbd class="ml-auto rounded border bg-muted px-1.5 text-[10px]">C</kbd>
                </CommandItem>
                <CommandItem value="settings-accounts" class="gap-2" @select="go('/settings/mail-accounts')">
                    <Settings class="size-4" /> Mail accounts & connection health
                </CommandItem>
                <CommandItem v-if="organization" value="settings-inboxes" class="gap-2" @select="go(`/settings/organizations/${slug}/inboxes`)">
                    <Users class="size-4" /> Shared inboxes & teams
                </CommandItem>
                <CommandItem v-if="organization" value="automations" class="gap-2" @select="go(`/${slug}/settings/automations`)">
                    <Workflow class="size-4" /> Automations
                </CommandItem>
            </CommandGroup>

            <CommandGroup v-if="nav" heading="Jump to">
                <CommandItem v-if="nav.personal.length > 1" value="inbox-all" class="gap-2" @select="go(`/${slug}/mail/all/inbox`)">
                    <Layers class="size-4 text-muted-foreground" /> All personal mail
                </CommandItem>
                <CommandItem v-for="inbox in nav.personal" :key="inbox.uuid" :value="`inbox-${inbox.uuid}`" class="gap-2" @select="go(`/${slug}/mail/${inbox.uuid}/inbox`)">
                    <Mail class="size-4 text-muted-foreground" /> {{ inbox.email ?? inbox.name }}
                    <span v-if="inbox.count" class="ml-auto text-xs text-brand">{{ inbox.count }}</span>
                </CommandItem>
                <CommandItem v-for="inbox in nav.shared" :key="inbox.uuid" :value="`shared-${inbox.uuid}`" class="gap-2" @select="go(`/${slug}/mail/${inbox.uuid}/open`)">
                    <InboxDot :color="inbox.color" /> {{ inbox.name }}
                    <span v-if="inbox.count" class="ml-auto text-xs text-muted-foreground">{{ inbox.count }} open</span>
                </CommandItem>
            </CommandGroup>
        </CommandList>
        <div class="flex items-center gap-3 border-t px-3 py-2 text-[11px] text-muted-foreground">
            <span class="flex items-center gap-1"><Search class="size-3" /> search</span>
            <span><kbd class="rounded border px-1">↑↓</kbd> move</span>
            <span><kbd class="rounded border px-1">↵</kbd> open</span>
            <span class="ml-auto flex items-center gap-1"><Inbox class="size-3" /> {{ nav?.assigned_to_me ?? 0 }} assigned to you</span>
        </div>
    </CommandDialog>
</template>
