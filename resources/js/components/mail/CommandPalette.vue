<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Inbox, Layers, Mail, PenLine, Search, Settings, Users, Workflow } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import UserAvatar from '@/components/mail/UserAvatar.vue';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { api } from '@/lib/http';
import { displayName } from '@/lib/mail';
import { listTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { ConversationListItem, MailNav } from '@/types';

type Item = {
    id: string;
    group: string;
    label: string;
    hint?: string;
    keywords: string;
    icon?: unknown;
    color?: string;
    conversation?: ConversationListItem;
    run: () => void;
};

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ compose: [] }>();

const page = usePage();
const nav = computed<MailNav | null>(() => page.props.mailNav ?? null);
const organization = computed(() => page.props.currentOrganization);
const slug = computed(() => organization.value?.slug ?? '');

const query = ref('');
const results = ref<ConversationListItem[]>([]);
const searching = ref(false);
const highlighted = ref(0);
const input = ref<HTMLInputElement | null>(null);
const list = ref<HTMLElement | null>(null);

const go = (url: string) => {
    open.value = false;
    router.visit(url);
};

const search = useDebounceFn(async (term: string) => {
    if (term.trim().length < 2) {
        results.value = [];

        return;
    }

    searching.value = true;

    try {
        const data = await api.get<{ conversations: ConversationListItem[] }>('/search', { q: term, limit: 8 });

        if (query.value === term) {
            results.value = data.conversations;
        }
    } finally {
        searching.value = false;
    }
}, 200);

watch(query, (value) => {
    highlighted.value = 0;
    void search(value);
});

watch(open, async (value) => {
    if (!value) {
        query.value = '';
        results.value = [];

        return;
    }

    await nextTick();
    input.value?.focus();
});

const staticItems = computed<Item[]>(() => {
    const items: Item[] = [
        { id: 'compose', group: 'Actions', label: 'Compose new message', hint: 'C', keywords: 'compose new write email', icon: PenLine, run: () => { open.value = false; emit('compose'); } },
        { id: 'accounts', group: 'Actions', label: 'Mail accounts & connection health', keywords: 'settings accounts gmail imap connect health sync', icon: Settings, run: () => go('/settings/mail-accounts') },
    ];

    if (organization.value) {
        items.push({ id: 'inboxes', group: 'Actions', label: 'Shared inboxes & teams', keywords: 'settings inboxes teams shared organization', icon: Users, run: () => go(`/settings/organizations/${slug.value}/inboxes`) });
        items.push({ id: 'automations', group: 'Actions', label: 'Automations', keywords: 'automation rules autoresponder templates', icon: Workflow, run: () => go(`/${slug.value}/settings/automations`) });
    }

    if (nav.value) {
        if (nav.value.personal.length > 1) {
            items.push({ id: 'all', group: 'Jump to', label: 'All personal mail', keywords: 'all personal inbox', icon: Layers, run: () => go(`/${slug.value}/mail/all/inbox`) });
        }

        nav.value.personal.forEach((inbox) =>
            items.push({ id: inbox.uuid, group: 'Jump to', label: inbox.email ?? inbox.name, hint: inbox.count ? `${inbox.count} unread` : undefined, keywords: `${inbox.name} ${inbox.email ?? ''} personal`, icon: Mail, run: () => go(`/${slug.value}/mail/${inbox.uuid}/inbox`) }),
        );

        nav.value.shared.forEach((inbox) =>
            items.push({ id: inbox.uuid, group: 'Jump to', label: inbox.name, hint: inbox.count ? `${inbox.count} open` : undefined, keywords: `${inbox.name} ${inbox.email ?? ''} shared team`, color: inbox.color, run: () => go(`/${slug.value}/mail/${inbox.uuid}/open`) }),
        );
    }

    return items;
});

const visible = computed<Item[]>(() => {
    const term = query.value.trim().toLowerCase();

    const conversations: Item[] = results.value.map((conversation) => ({
        id: `c-${conversation.uuid}`,
        group: 'Conversations',
        label: conversation.subject ?? '(no subject)',
        keywords: '',
        conversation,
        run: () => go(`/${slug.value}/mail/${conversation.inbox_uuid}/all/${conversation.uuid}`),
    }));

    const statics = term ? staticItems.value.filter((item) => `${item.label} ${item.keywords}`.toLowerCase().includes(term)) : staticItems.value;

    return [...conversations, ...statics];
});

const groups = computed(() => {
    const map = new Map<string, Item[]>();
    visible.value.forEach((item) => map.set(item.group, [...(map.get(item.group) ?? []), item]));

    return Array.from(map.entries());
});

const move = (delta: number) => {
    if (visible.value.length === 0) {
        return;
    }

    highlighted.value = (highlighted.value + delta + visible.value.length) % visible.value.length;
    nextTick(() => list.value?.querySelector<HTMLElement>(`[data-index="${highlighted.value}"]`)?.scrollIntoView({ block: 'nearest' }));
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        visible.value[highlighted.value]?.run();
    }
};

const indexOf = (item: Item) => visible.value.indexOf(item);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="top-[12%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-2xl" :show-close-button="false">
            <DialogTitle class="sr-only">Command palette</DialogTitle>
            <DialogDescription class="sr-only">Search conversations, jump to inboxes and run commands.</DialogDescription>

            <div class="flex items-center gap-2 border-b px-4">
                <Search class="size-4 shrink-0 text-muted-foreground" />
                <input
                    ref="input"
                    v-model="query"
                    type="text"
                    placeholder="Search mail, jump to an inbox, or run a command…"
                    class="h-12 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    autocomplete="off"
                    spellcheck="false"
                    @keydown="onKeydown"
                />
                <span v-if="searching" class="size-3.5 animate-spin rounded-full border-2 border-muted-foreground/30 border-t-brand" />
            </div>

            <div ref="list" role="listbox" class="max-h-[60vh] overflow-y-auto p-2 scrollbar-thin">
                <p v-if="query.trim().length >= 2 && results.length === 0 && !searching" class="px-3 py-2 text-xs text-muted-foreground">No conversations match “{{ query }}”.</p>

                <template v-for="[group, items] in groups" :key="group">
                    <p class="px-2 pt-2 pb-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">{{ group }}</p>
                    <button
                        v-for="item in items"
                        :key="item.id"
                        type="button"
                        role="option"
                        :aria-selected="indexOf(item) === highlighted"
                        :data-index="indexOf(item)"
                        :class="cn('flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-left text-sm transition-colors', indexOf(item) === highlighted ? 'bg-accent' : 'hover:bg-accent/60')"
                        @mouseenter="highlighted = indexOf(item)"
                        @click="item.run()"
                    >
                        <template v-if="item.conversation">
                            <UserAvatar :name="item.conversation.counterpart?.name" :email="item.conversation.counterpart?.email" size="sm" />
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2">
                                    <span class="truncate font-medium">{{ displayName(item.conversation.counterpart) }}</span>
                                    <span class="truncate text-xs text-muted-foreground">{{ item.conversation.inbox_name }}</span>
                                </span>
                                <span class="block truncate text-xs text-muted-foreground">{{ item.label }}</span>
                            </span>
                            <span class="text-[11px] text-muted-foreground">{{ listTime(item.conversation.last_message_at) }}</span>
                        </template>
                        <template v-else>
                            <InboxDot v-if="item.color" :color="item.color" />
                            <component :is="item.icon" v-else class="size-4 text-muted-foreground" />
                            <span class="flex-1 truncate">{{ item.label }}</span>
                            <kbd v-if="item.hint" class="rounded border bg-muted px-1.5 text-[10px] text-muted-foreground">{{ item.hint }}</kbd>
                        </template>
                    </button>
                </template>
            </div>

            <div class="flex items-center gap-3 border-t px-3 py-2 text-[11px] text-muted-foreground">
                <span><kbd class="rounded border px-1">↑↓</kbd> move</span>
                <span><kbd class="rounded border px-1">↵</kbd> open</span>
                <span><kbd class="rounded border px-1">esc</kbd> close</span>
                <span class="ml-auto flex items-center gap-1"><Inbox class="size-3" /> {{ nav?.assigned_to_me ?? 0 }} assigned to you</span>
            </div>
        </DialogContent>
    </Dialog>
</template>
