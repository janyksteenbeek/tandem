<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Archive,
    Bookmark,
    CheckCircle2,
    ChevronRight,
    Clock,
    FileText,
    Inbox,
    Layers,
    Mail,
    PenLine,
    Plus,
    Send,
    ShieldAlert,
    Star,
    Trash2,
    UserCheck,
    Users,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import Collapse from '@/components/Collapse.vue';
import ShellColumn from '@/components/shell/ShellColumn.vue';
import SidebarRow from '@/components/shell/SidebarRow.vue';
import SidebarSearchRow from '@/components/shell/SidebarSearchRow.vue';
import SidebarSection from '@/components/shell/SidebarSection.vue';
import WorkspaceSwitcher from '@/components/shell/WorkspaceSwitcher.vue';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { VIEW_LABELS as viewLabels } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type { MailNav, NavInbox, SavedView, ViewOption } from '@/types';

const props = defineProps<{
    activeInboxUuid?: string | null;
    activeView?: string | null;
    views?: ViewOption[];
    counts?: Record<string, number> | null;
    savedViews?: SavedView[];
}>();

const emit = defineEmits<{ compose: [] }>();

const page = usePage();
const nav = computed<MailNav | null>(() => page.props.mailNav ?? null);
const organization = computed(() => page.props.currentOrganization);
const orgSlug = computed(() => organization.value?.slug ?? '');

const viewIcons: Record<string, LucideIcon> = {
    inbox: Inbox,
    starred: Star,
    snoozed: Clock,
    drafts: FileText,
    sent: Send,
    archive: Archive,
    spam: ShieldAlert,
    trash: Trash2,
    all: Layers,
    open: Inbox,
    assigned: UserCheck,
    unassigned: Users,
    pending: Clock,
    closed: CheckCircle2,
};

const inboxUrl = (inbox: NavInbox | 'all', view?: string) => {
    const uuid = inbox === 'all' ? 'all' : inbox.uuid;
    const defaultView =
        inbox === 'all' || inbox.type === 'personal' ? 'inbox' : 'open';

    return `/${orgSlug.value}/mail/${uuid}/${view ?? defaultView}`;
};

const isActive = (inbox: NavInbox | 'all') =>
    inbox === 'all'
        ? props.activeInboxUuid === 'all'
        : props.activeInboxUuid === inbox.uuid;

const primaryViews = computed(() =>
    (props.views ?? []).filter(
        (v) =>
            ![
                'sent',
                'trash',
                'spam',
                'archive',
                'drafts',
                'starred',
                'all',
            ].includes(v.value),
    ),
);
const secondaryViews = computed(() =>
    (props.views ?? []).filter((v) =>
        [
            'drafts',
            'starred',
            'sent',
            'archive',
            'all',
            'spam',
            'trash',
        ].includes(v.value),
    ),
);

const showAllPersonal = computed(() => (nav.value?.personal.length ?? 0) > 1);

/**
 * Two levels, two treatments. A mailbox row (account, shared inbox) is a
 * heading that opens: when active it just gets weight, never a fill. The
 * view rows underneath carry the one brand-tinted active state.
 */
const moreRowClass =
    'flex h-7 cursor-pointer list-none items-center gap-2 rounded-md px-2 text-[13px] text-muted-foreground transition-colors select-none hover:bg-sidebar-accent hover:text-sidebar-foreground';

const manageInboxes = () => {
    if (organization.value) {
        router.visit(
            `/settings/organizations/${organization.value.slug}/inboxes`,
        );
    }
};

const savedViewUrl = (inbox: NavInbox | 'all', view: SavedView) => {
    const params = new URLSearchParams();

    Object.entries(view.filters ?? {}).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            value.forEach((v) => params.append(`${key}[]`, String(v)));
        } else if (value !== null && value !== undefined && value !== '') {
            params.set(key, String(value));
        }
    });

    const qs = params.toString();

    return `${inboxUrl(inbox, inbox === 'all' || inbox.type === 'personal' ? 'all' : 'open')}${qs ? `?${qs}` : ''}`;
};
</script>

<template>
    <ShellColumn label="Mailboxes">
        <template #header>
            <WorkspaceSwitcher />
            <Button
                class="mt-2 h-8 w-full justify-start gap-2 rounded-lg px-2.5 text-[13px] text-brand-foreground brand-button-surface"
                @click="emit('compose')"
            >
                <PenLine class="size-3.5" />
                <span class="flex-1 text-left">New message</span>
                <Kbd
                    class="h-4 min-w-4 bg-brand-foreground/15 px-1 text-[10px] text-brand-foreground/80"
                    >C</Kbd
                >
            </Button>
            <SidebarSearchRow class="mt-2" />
        </template>

        <nav aria-label="Mailboxes">
            <template v-if="nav">
                <SidebarSection label="Personal">
                    <div
                        v-if="nav.personal.length === 0"
                        class="mx-1 rounded-lg border border-dashed border-sidebar-border px-3 py-3 text-xs text-muted-foreground"
                    >
                        <p class="font-medium text-sidebar-foreground">
                            No mail accounts yet.
                        </p>
                        <Link
                            href="/settings/mail-accounts"
                            class="mt-2 inline-flex items-center gap-1 font-medium text-brand hover:underline"
                        >
                            <Plus class="size-3" /> Connect account
                        </Link>
                    </div>

                    <template v-if="showAllPersonal">
                        <SidebarRow
                            :href="inboxUrl('all')"
                            :icon="Layers"
                            variant="heading"
                            :active="isActive('all')"
                        >
                            All personal mail
                            <template v-if="nav.personal_unread > 0" #trailing>
                                <span
                                    class="text-[11px] font-semibold text-brand tabular-nums"
                                    >{{ nav.personal_unread }}</span
                                >
                            </template>
                        </SidebarRow>
                        <Collapse :open="isActive('all')">
                            <div class="mt-0.5 mb-2 space-y-px">
                                <SidebarRow
                                    v-for="view in views"
                                    :key="view.value"
                                    :href="inboxUrl('all', view.value)"
                                    :icon="viewIcons[view.value] ?? Mail"
                                    :depth="1"
                                    :active="activeView === view.value"
                                    :trailing="counts?.[view.value] || null"
                                >
                                    {{ viewLabels[view.value] ?? view.label }}
                                </SidebarRow>
                            </div>
                        </Collapse>
                    </template>

                    <template v-for="inbox in nav.personal" :key="inbox.uuid">
                        <SidebarRow
                            :href="inboxUrl(inbox)"
                            :icon="Mail"
                            variant="heading"
                            :active="isActive(inbox)"
                            :title="inbox.email ?? inbox.name"
                        >
                            {{ inbox.email ?? inbox.name }}
                            <template #trailing>
                                <Tooltip v-if="inbox.health === 'attention'">
                                    <TooltipTrigger as-child
                                        ><AlertTriangle
                                            class="size-3.5 text-warning"
                                    /></TooltipTrigger>
                                    <TooltipContent
                                        >This account needs
                                        attention</TooltipContent
                                    >
                                </Tooltip>
                                <span
                                    v-if="inbox.count > 0"
                                    class="text-[11px] font-semibold text-brand tabular-nums"
                                    >{{ inbox.count }}</span
                                >
                            </template>
                        </SidebarRow>
                        <Collapse :open="isActive(inbox)">
                            <div class="mt-0.5 mb-2 space-y-px">
                                <SidebarRow
                                    v-for="view in views"
                                    :key="view.value"
                                    :href="inboxUrl(inbox, view.value)"
                                    :icon="viewIcons[view.value] ?? Mail"
                                    :depth="1"
                                    :active="activeView === view.value"
                                    :trailing="counts?.[view.value] || null"
                                >
                                    {{ viewLabels[view.value] ?? view.label }}
                                </SidebarRow>
                            </div>
                        </Collapse>
                    </template>
                </SidebarSection>

                <SidebarSection
                    label="Shared"
                    :can-add="Boolean(organization)"
                    @add="manageInboxes"
                >
                    <div
                        v-if="nav.shared.length === 0"
                        class="mx-1 rounded-lg border border-dashed border-sidebar-border px-3 py-3 text-xs text-muted-foreground"
                    >
                        <p class="font-medium text-sidebar-foreground">
                            No shared inboxes.
                        </p>
                        <p class="mt-0.5">
                            Create one for support@, sales@ or finance@ and work
                            on it together.
                        </p>
                    </div>

                    <template v-for="inbox in nav.shared" :key="inbox.uuid">
                        <SidebarRow
                            :href="inboxUrl(inbox)"
                            :color="inbox.color"
                            variant="heading"
                            :active="isActive(inbox)"
                        >
                            {{ inbox.name }}
                            <template #trailing>
                                <Tooltip v-if="inbox.health === 'attention'">
                                    <TooltipTrigger as-child
                                        ><AlertTriangle
                                            class="size-3.5 text-warning"
                                    /></TooltipTrigger>
                                    <TooltipContent
                                        >A channel of this inbox needs
                                        attention</TooltipContent
                                    >
                                </Tooltip>
                                <span
                                    v-if="inbox.count > 0"
                                    class="text-[11px] text-muted-foreground tabular-nums"
                                    >{{ inbox.count }}</span
                                >
                            </template>
                        </SidebarRow>
                        <Collapse :open="isActive(inbox)">
                            <div class="mt-0.5 mb-2 space-y-px">
                                <SidebarRow
                                    v-for="view in primaryViews"
                                    :key="view.value"
                                    :href="inboxUrl(inbox, view.value)"
                                    :icon="viewIcons[view.value] ?? Mail"
                                    :depth="1"
                                    :active="activeView === view.value"
                                    :trailing="counts?.[view.value] || null"
                                >
                                    {{ viewLabels[view.value] ?? view.label }}
                                </SidebarRow>
                                <SidebarRow
                                    v-for="saved in savedViews ?? []"
                                    :key="`saved-${saved.id}`"
                                    :href="savedViewUrl(inbox, saved)"
                                    :icon="Bookmark"
                                    :depth="1"
                                >
                                    {{ saved.name }}
                                </SidebarRow>
                                <details class="group/more pl-4">
                                    <summary :class="moreRowClass">
                                        <ChevronRight
                                            class="size-3.5 transition group-open/more:rotate-90"
                                        />
                                        More
                                    </summary>
                                    <div class="-ml-4 space-y-px">
                                        <SidebarRow
                                            v-for="view in secondaryViews"
                                            :key="view.value"
                                            :href="inboxUrl(inbox, view.value)"
                                            :icon="
                                                viewIcons[view.value] ?? Mail
                                            "
                                            :depth="1"
                                            :active="activeView === view.value"
                                        >
                                            {{
                                                viewLabels[view.value] ??
                                                view.label
                                            }}
                                        </SidebarRow>
                                    </div>
                                </details>
                            </div>
                        </Collapse>
                    </template>
                </SidebarSection>
            </template>
        </nav>
    </ShellColumn>
</template>
