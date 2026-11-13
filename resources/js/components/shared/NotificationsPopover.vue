<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useEchoNotification } from '@laravel/echo-vue';
import {
    AtSign,
    CheckCheck,
    CircleAlert,
    CircleCheck,
    CircleDot,
    Clapperboard,
    Inbox,
    Link2Off,
    MessageSquareText,
    Reply,
    Repeat,
    UserCheck,
    Users,
    Video,
    Workflow,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { api } from '@/lib/http';
import { playNotificationSound } from '@/lib/notificationSound';
import { relative } from '@/lib/time';
import type { AppNotification } from '@/types';

const page = usePage();
const open = ref(false);
const items = ref<AppNotification[]>([]);
const unread = ref<number>(Number(page.props.unreadNotifications ?? 0));
const loading = ref(false);
const organization = computed(() => page.props.currentOrganization);

const icons: Record<string, unknown> = {
    assigned: UserCheck,
    mention: AtSign,
    comment: MessageSquareText,
    reply: Reply,
    automation: Workflow,
    issue_assigned: UserCheck,
    issue_owner: UserCheck,
    issue_mention: AtSign,
    issue_comment: MessageSquareText,
    issue_status: CircleDot,
    issue_ready: CircleCheck,
    issue_conflict: Repeat,
    blocker_resolved: Link2Off,
    handoff: Repeat,
    team_added: Users,
    issue_due_soon: CircleDot,
    issue_overdue: CircleDot,
    cycle_started: Repeat,
    issue_from_email: CircleDot,
    call_invitation: Video,
    call_recording_ready: Clapperboard,
    call_recording_partial: CircleAlert,
    call_recording_failed: CircleAlert,
};

const load = async () => {
    loading.value = true;

    try {
        const data = await api.get<{
            notifications: AppNotification[];
            unread_count: number;
        }>('/notifications');
        items.value = data.notifications;
        unread.value = data.unread_count;
    } finally {
        loading.value = false;
    }
};

watch(open, (value) => value && void load());

useEchoNotification<AppNotification['data']>(
    `App.Models.User.${page.props.auth.user.id}`,
    (notification) => {
        unread.value += 1;
        playNotificationSound();

        toast(notification.title, {
            description: notification.body ?? undefined,
            action:
                notification.conversation_uuid ||
                notification.issue_identifier ||
                callUrl(notification)
                    ? {
                          label: 'Open',
                          onClick: () => go(notification),
                      }
                    : undefined,
        });
    },
);

/**
 * Call notifications say where to go themselves (a meeting link, a recording). Only the path
 * is used, so the visit always stays on the origin the app is open on, whatever host the
 * server wrote the link with.
 */
const callUrl = (data: AppNotification['data']): string | null => {
    if (!data.kind.startsWith('call_') || !data.url) {
        return null;
    }

    try {
        const url = new URL(data.url, window.location.origin);

        return url.pathname.startsWith('/')
            ? url.pathname + url.search + url.hash
            : null;
    } catch {
        return null;
    }
};

const go = (data: AppNotification['data']) => {
    const call = callUrl(data);

    if (call) {
        open.value = false;
        router.visit(call);

        return;
    }

    if (!organization.value) {
        return;
    }

    if (data.issue_identifier) {
        open.value = false;
        router.visit(
            `/${organization.value.slug}/issues/${data.issue_identifier}`,
        );

        return;
    }

    if (data.team_slug && data.cycle_uuid) {
        open.value = false;
        router.visit(
            `/${organization.value.slug}/teams/${data.team_slug}/cycles/${data.cycle_uuid}`,
        );

        return;
    }

    if (!data.inbox_uuid || !data.conversation_uuid) {
        return;
    }

    open.value = false;
    router.visit(
        `/${organization.value.slug}/mail/${data.inbox_uuid}/all/${data.conversation_uuid}`,
    );
};

const openItem = async (item: AppNotification) => {
    if (!item.read_at) {
        item.read_at = new Date().toISOString();
        unread.value = Math.max(0, unread.value - 1);
        void api.post(`/notifications/${item.id}/read`);
    }

    go(item.data);
};

const readAll = async () => {
    await api.post('/notifications/read-all');
    items.value = items.value.map((item) => ({
        ...item,
        read_at: item.read_at ?? new Date().toISOString(),
    }));
    unread.value = 0;
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <slot name="trigger" :unread="unread" />
        </PopoverTrigger>
        <PopoverContent side="right" align="end" class="w-96 p-0">
            <div class="flex items-center justify-between border-b px-4 py-3">
                <div>
                    <p class="text-sm font-semibold">Notifications</p>
                    <p class="text-xs text-muted-foreground">
                        Assignments, mentions and replies.
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    class="gap-1 text-xs"
                    :disabled="unread === 0"
                    @click="readAll"
                >
                    <CheckCheck class="size-3.5" /> Mark all read
                </Button>
            </div>
            <div class="scrollbar-thin max-h-[420px] overflow-y-auto">
                <div v-if="loading && items.length === 0" class="space-y-3 p-4">
                    <div
                        v-for="n in 4"
                        :key="n"
                        class="h-12 shimmer rounded-md"
                    />
                </div>
                <div
                    v-else-if="items.length === 0"
                    class="flex flex-col items-center gap-2 px-6 py-10 text-center"
                >
                    <Inbox class="size-8 text-muted-foreground/60" />
                    <p class="font-display text-lg">All quiet.</p>
                </div>
                <button
                    v-for="item in items"
                    :key="item.id"
                    type="button"
                    class="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-accent"
                    :class="!item.read_at && 'bg-brand-soft/40'"
                    @click="openItem(item)"
                >
                    <span
                        class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    >
                        <component
                            :is="icons[item.data.kind] ?? Inbox"
                            class="size-4"
                        />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate text-sm"
                            :class="
                                !item.read_at ? 'font-semibold' : 'font-medium'
                            "
                            >{{ item.data.title }}</span
                        >
                        <span
                            v-if="item.data.body"
                            class="block truncate text-xs text-muted-foreground"
                            >{{ item.data.body }}</span
                        >
                        <span
                            class="mt-0.5 flex items-center gap-1.5 text-[11px] text-muted-foreground"
                        >
                            <UserAvatar
                                v-if="item.data.actor"
                                :name="item.data.actor.name"
                                size="xs"
                            />
                            {{ relative(item.created_at) }}
                        </span>
                    </span>
                    <span v-if="!item.read_at" class="mt-2 unread-dot" />
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
