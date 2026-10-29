<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { useMediaQuery } from '@vueuse/core';
import { Loader2 } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import BulkBar from '@/components/mail/BulkBar.vue';
import ComposeDialog from '@/components/mail/ComposeDialog.vue';
import ConversationListItem from '@/components/mail/ConversationListItem.vue';
import ConversationPane from '@/components/mail/ConversationPane.vue';
import EmptyState from '@/components/mail/EmptyState.vue';
import ListSkeleton from '@/components/mail/ListSkeleton.vue';
import ListToolbar from '@/components/mail/ListToolbar.vue';
import { Button } from '@/components/ui/button';
import { ResizableHandle, ResizablePanel, ResizablePanelGroup } from '@/components/ui/resizable';
import { useConversationActions } from '@/composables/useConversationActions';
import { useKeyboard } from '@/composables/useKeyboard';
import MailLayout from '@/layouts/MailLayout.vue';
import { api, reportError } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { Conversation, ConversationFilters, ConversationListItem as ListItem, CursorPage, Inbox, SavedView, ShareTarget, Tag, UserSummary, ViewOption } from '@/types';

const props = defineProps<{
    inbox: Inbox;
    view: string;
    views: ViewOption[];
    counts?: Record<string, number> | null;
    filters: ConversationFilters;
    conversations: CursorPage<ListItem>;
    conversation: Conversation | null;
    members: UserSummary[];
    tags: Tag[];
    savedViews: SavedView[];
    shareTargets?: ShareTarget[] | null;
}>();

const page = usePage();
const slug = computed(() => page.props.currentOrganization?.slug ?? '');
const isMobile = useMediaQuery('(max-width: 767px)');
const isShared = computed(() => props.inbox.type === 'shared');
const userId = computed(() => page.props.auth.user.id);

const items = ref<ListItem[]>(props.conversations.data);
const nextCursor = ref<string | null>(props.conversations.meta.next_cursor);
const loadingMore = ref(false);
const loadingList = ref(false);
const selected = ref<Set<string>>(new Set());
const cursorIndex = ref(-1);
const composeOpen = ref(false);
const composeTo = ref<string | null>(null);
const toolbar = ref<InstanceType<typeof ListToolbar> | null>(null);
const listEl = ref<HTMLElement | null>(null);
const localShareTargets = ref<ShareTarget[] | null>(props.shareTargets ?? null);

watch(
    () => props.conversations,
    (value) => {
        items.value = value.data;
        nextCursor.value = value.meta.next_cursor;
        loadingMore.value = false;
        loadingList.value = false;
    },
);

watch(
    () => props.shareTargets,
    (value) => {
        if (value) {
            localShareTargets.value = value;
        }
    },
);

const activeUuid = computed(() => props.conversation?.uuid ?? null);
const viewLabel = computed(() => props.views.find((v) => v.value === props.view)?.label ?? props.view);
const title = computed(() => (props.inbox.uuid === 'all' ? 'All personal mail' : props.inbox.name));
const subtitle = computed(() => {
    if (props.filters.q) {
        return `Results for “${props.filters.q}”`;
    }

    return props.inbox.uuid === 'all' ? `${viewLabel.value} across ${props.inbox.accounts.length} accounts` : (props.inbox.email_address ?? viewLabel.value);
});

const baseUrl = computed(() => `/${slug.value}/mail/${props.inbox.uuid}/${props.view}`);

const queryString = (filters: ConversationFilters) => {
    const params = new URLSearchParams();

    Object.entries(filters).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            value.forEach((v) => params.append(`${key}[]`, v));
        } else if (value !== undefined && value !== '' && value !== null) {
            params.set(key, String(value));
        }
    });

    const qs = params.toString();

    return qs ? `?${qs}` : '';
};

const openConversation = (item: ListItem) => {
    router.visit(`${baseUrl.value}/${item.uuid}${queryString(props.filters)}`, {
        only: ['conversation'],
        preserveState: true,
        preserveScroll: true,
    });

    if (!item.is_read) {
        item.is_read = true;
        item.unread_count = 0;
    }
};

const closeConversation = () => {
    router.visit(`${baseUrl.value}${queryString(props.filters)}`, { only: ['conversation'], preserveState: true, preserveScroll: true });
};

const updateFilters = (filters: ConversationFilters) => {
    loadingList.value = true;
    router.visit(`${baseUrl.value}${queryString(filters)}`, { only: ['conversations', 'filters', 'counts'], preserveState: true, preserveScroll: true });
};

const loadMore = () => {
    if (!nextCursor.value || loadingMore.value) {
        return;
    }

    loadingMore.value = true;
    router.reload({ only: ['conversations'], data: { ...props.filters, cursor: nextCursor.value } });
};

const onScroll = (event: Event) => {
    const el = event.target as HTMLElement;

    if (el.scrollHeight - el.scrollTop - el.clientHeight < 400) {
        loadMore();
    }
};

const belongsToView = (item: ListItem): boolean => {
    const notJunk = !item.is_trashed && !item.is_spam;

    switch (props.view) {
        case 'inbox':
            return notJunk && item.in_inbox && item.status !== 'snoozed';
        case 'starred':
            return notJunk && item.is_starred;
        case 'snoozed':
            return item.status === 'snoozed';
        case 'sent':
        case 'drafts':
            return notJunk;
        case 'archive':
            return notJunk && item.is_archived;
        case 'spam':
            return item.is_spam;
        case 'trash':
            return item.is_trashed;
        case 'open':
            return notJunk && item.status === 'open';
        case 'assigned':
            return notJunk && item.assignee?.id === userId.value && ['open', 'pending'].includes(item.status);
        case 'unassigned':
            return notJunk && !item.assignee && item.status === 'open';
        case 'pending':
            return notJunk && item.status === 'pending';
        case 'closed':
            return notJunk && item.status === 'closed';
        default:
            return notJunk;
    }
};

const upsert = (incoming: ListItem) => {
    const index = items.value.findIndex((i) => i.uuid === incoming.uuid);
    const keep = belongsToView(incoming);

    if (index === -1) {
        if (keep && !props.filters.q) {
            items.value = [incoming, ...items.value];
        }

        return;
    }

    if (keep || incoming.uuid === activeUuid.value) {
        items.value.splice(index, 1, incoming);
    } else {
        items.value.splice(index, 1);
    }
};

const applyUpdated = (conversation: Conversation) => upsert(conversation);

const removeFromList = (uuid: string) => {
    const index = items.value.findIndex((i) => i.uuid === uuid);

    if (index !== -1) {
        items.value.splice(index, 1);
    }

    selected.value.delete(uuid);

    if (activeUuid.value === uuid) {
        const next = items.value[index] ?? items.value[index - 1];

        if (next && !isMobile.value) {
            openConversation(next);
        } else {
            closeConversation();
        }
    }
};

const channelIds = computed(() => (props.inbox.uuid === 'all' ? (page.props.mailNav?.personal ?? []).map((i) => i.id) : [props.inbox.id]));

channelIds.value.forEach((id) => {
    useEcho<{ reason: string; conversation: ListItem | null; removed_from_inbox_ids: number[] }>(`inbox.${id}`, '.conversation.updated', (payload) => {
        if (!payload.conversation) {
            return;
        }

        const stillVisible = payload.conversation.inbox_id === id || payload.conversation.shared_inbox_ids.includes(id);

        if (payload.reason === 'deleted' || (!stillVisible && payload.removed_from_inbox_ids.includes(id))) {
            const index = items.value.findIndex((i) => i.uuid === payload.conversation!.uuid);

            if (index !== -1) {
                items.value.splice(index, 1);
            }

            return;
        }

        upsert(payload.conversation);
    });
});

const actions = useConversationActions(applyUpdated, (updated) => {
    updated.forEach((item) => upsert(item));
    selected.value = new Set();
});

const toggleSelect = (item: ListItem, shift: boolean) => {
    const next = new Set(selected.value);

    if (shift && cursorIndex.value >= 0) {
        const target = items.value.findIndex((i) => i.uuid === item.uuid);
        const [from, to] = [Math.min(cursorIndex.value, target), Math.max(cursorIndex.value, target)];
        items.value.slice(from, to + 1).forEach((i) => next.add(i.uuid));
    } else if (next.has(item.uuid)) {
        next.delete(item.uuid);
    } else {
        next.add(item.uuid);
    }

    selected.value = next;
    cursorIndex.value = items.value.findIndex((i) => i.uuid === item.uuid);
};

const bulk = (action: string, params?: Record<string, unknown>) => {
    const uuids = Array.from(selected.value);

    if (uuids.length === 0) {
        return;
    }

    if (['archive', 'trash', 'spam'].includes(action) || (action === 'status' && params?.status !== props.view)) {
        uuids.forEach((uuid) => {
            const index = items.value.findIndex((i) => i.uuid === uuid);

            if (index !== -1 && props.view !== 'all') {
                items.value.splice(index, 1);
            }
        });
    }

    void actions.bulk(uuids, action, params);
};

const starItem = (item: ListItem) => {
    void actions.star(item.uuid, !item.is_starred, () => {
        item.is_starred = !item.is_starred;

        return () => (item.is_starred = !item.is_starred);
    });
};

const moveCursor = (delta: number) => {
    if (items.value.length === 0) {
        return;
    }

    const current = activeUuid.value ? items.value.findIndex((i) => i.uuid === activeUuid.value) : cursorIndex.value;
    const next = Math.max(0, Math.min(items.value.length - 1, (current === -1 ? (delta > 0 ? -1 : items.value.length) : current) + delta));
    cursorIndex.value = next;

    if (activeUuid.value || !isMobile.value) {
        openConversation(items.value[next]);
    }

    nextTick(() => listEl.value?.querySelectorAll('article')[next]?.scrollIntoView({ block: 'nearest' }));
};

const saveView = async () => {
    const name = window.prompt('Name this view');

    if (!name) {
        return;
    }

    try {
        await api.post('/saved-views', { name, filters: props.filters, inbox: props.inbox.uuid === 'all' ? null : props.inbox.uuid });
        router.reload({ only: ['savedViews'] });
    } catch (error) {
        reportError(error);
    }
};

const loadShareTargets = () => router.reload({ only: ['shareTargets'] });

const goView = (view: string) => router.visit(`/${slug.value}/mail/${props.inbox.uuid}/${view}`);

useKeyboard(() => [
    { keys: 'j', description: 'Next', handler: () => moveCursor(1) },
    { keys: 'k', description: 'Previous', handler: () => moveCursor(-1) },
    { keys: 'arrowdown', description: 'Next', handler: () => moveCursor(1) },
    { keys: 'arrowup', description: 'Previous', handler: () => moveCursor(-1) },
    { keys: 'enter', description: 'Open', handler: () => cursorIndex.value >= 0 && items.value[cursorIndex.value] && openConversation(items.value[cursorIndex.value]) },
    { keys: 'x', description: 'Select', handler: () => cursorIndex.value >= 0 && items.value[cursorIndex.value] && toggleSelect(items.value[cursorIndex.value], false) },
    { keys: '/', description: 'Search', handler: () => toolbar.value?.focusSearch() },
    { keys: 'g i', description: 'Inbox', handler: () => goView(isShared.value ? 'open' : 'inbox') },
    { keys: 'g s', description: 'Starred', handler: () => goView(isShared.value ? 'assigned' : 'starred') },
    { keys: 'g t', description: 'Sent', handler: () => goView('sent') },
    { keys: 'g d', description: 'Drafts', handler: () => goView('drafts') },
    { keys: 'escape', description: 'Clear selection', handler: () => { if (selected.value.size) { selected.value = new Set(); } } },
]);

const openCompose = (to?: string | null) => {
    composeTo.value = to ?? null;
    composeOpen.value = true;
};

const showList = computed(() => !isMobile.value || !props.conversation);
const showPane = computed(() => !isMobile.value || Boolean(props.conversation));
</script>

<template>
    <Head :title="`${title} · ${viewLabel}`" />

    <MailLayout :active-inbox-uuid="inbox.uuid" :active-view="view" :views="views" :counts="counts" :saved-views="savedViews" :hide-nav-button="isMobile && Boolean(conversation)" @compose="openCompose()">
        <ResizablePanelGroup direction="horizontal" class="h-full" auto-save-id="tandem-mail-panes">
            <ResizablePanel v-if="showList" :default-size="36" :min-size="26" :max-size="55" :class="cn('flex min-w-0 flex-col', isMobile && 'flex-1')">
                <ListToolbar ref="toolbar" :title="title" :subtitle="subtitle" :filters="filters" :members="members" :tags="tags" :is-shared="isShared" @update="updateFilters" @save-view="saveView" />
                <BulkBar v-if="selected.size" :count="selected.size" :is-shared="isShared" :members="members" :tags="tags" @clear="selected = new Set()" @action="bulk" />

                <div ref="listEl" class="relative flex-1 overflow-y-auto scrollbar-thin" @scroll.passive="onScroll">
                    <ListSkeleton v-if="loadingList && items.length === 0" />
                    <EmptyState v-else-if="items.length === 0" :view="filters.q ? 'search' : view">
                        <Button v-if="view === 'inbox' && inbox.accounts.length === 0" variant="outline" size="sm" class="mt-4" as="a" href="/settings/mail-accounts">Connect an account</Button>
                    </EmptyState>
                    <template v-else>
                        <ConversationListItem
                            v-for="(item, index) in items"
                            :key="item.uuid"
                            :item="item"
                            :active="item.uuid === activeUuid"
                            :selected="selected.has(item.uuid)"
                            :cursor="index === cursorIndex && !activeUuid"
                            :selection-mode="selected.size > 0"
                            :show-inbox="inbox.uuid === 'all'"
                            @open="openConversation"
                            @toggle="toggleSelect"
                            @star="starItem"
                        />
                        <div v-if="nextCursor" class="flex items-center justify-center py-4">
                            <Button variant="ghost" size="sm" class="gap-2 text-xs text-muted-foreground" :disabled="loadingMore" @click="loadMore">
                                <Loader2 v-if="loadingMore" class="size-3.5 animate-spin" /> {{ loadingMore ? 'Loading…' : 'Load more' }}
                            </Button>
                        </div>
                        <p v-else-if="items.length > 20" class="py-6 text-center font-display text-sm text-muted-foreground italic">That is everything. Nicely done.</p>
                    </template>
                </div>
            </ResizablePanel>

            <ResizableHandle v-if="showList && showPane && !isMobile" with-handle class="w-px bg-border" />

            <ResizablePanel v-if="showPane" :default-size="64" class="flex min-w-0 flex-col">
                <ConversationPane
                    v-if="conversation"
                    :key="conversation.uuid"
                    :conversation="conversation"
                    :inbox="inbox"
                    :members="members"
                    :tags="tags"
                    :share-targets="localShareTargets"
                    :is-mobile="isMobile"
                    @updated="applyUpdated"
                    @back="closeConversation"
                    @removed="removeFromList"
                    @load-share-targets="loadShareTargets"
                />
                <div v-else class="flex h-full flex-col items-center justify-center bg-surface-sunken/40 px-8 text-center">
                    <p class="font-display text-3xl text-foreground/80">Pick a conversation.</p>
                    <p class="mt-2 max-w-sm text-sm text-muted-foreground">
                        Use <kbd class="rounded border bg-muted px-1 text-[11px]">J</kbd> / <kbd class="rounded border bg-muted px-1 text-[11px]">K</kbd> to move, <kbd class="rounded border bg-muted px-1 text-[11px]">↵</kbd> to open, <kbd class="rounded border bg-muted px-1 text-[11px]">C</kbd> to compose and <kbd class="rounded border bg-muted px-1 text-[11px]">?</kbd> for the full cheat sheet.
                    </p>
                </div>
            </ResizablePanel>
        </ResizablePanelGroup>

        <ComposeDialog v-model:open="composeOpen" :inbox="inbox.uuid === 'all' ? null : inbox" :inboxes="[...(page.props.mailNav?.personal ?? []), ...(page.props.mailNav?.shared ?? [])]" :default-to="composeTo" />
    </MailLayout>
</template>
