<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
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
import { computed } from 'vue';
import Collapse from '@/components/Collapse.vue';
import InboxDot from '@/components/mail/InboxDot.vue';
import ShellColumn from '@/components/shell/ShellColumn.vue';
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
import { useBranding } from '@/composables/useBranding';

const branding = useBranding();

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

const viewIcons: Record<string, unknown> = {
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
const rowClass =
    'group flex h-8 items-center gap-2.5 rounded-lg px-2 text-[13px] text-sidebar-foreground/85 transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground';
const openRowClass = 'font-medium text-sidebar-foreground';
const viewRowClass =
    'group/view flex h-7 items-center gap-2 rounded-md px-2 text-[12.5px] text-sidebar-foreground/75 transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground';
const activeViewClass =
    'bg-brand-soft/70 font-medium text-brand-soft-foreground hover:bg-brand-soft hover:text-brand-soft-foreground';
const viewIconClass =
    'size-3.5 shrink-0 text-muted-foreground/80 transition-colors group-hover/view:text-sidebar-foreground';
const sectionLabelClass =
    'text-[10.5px] font-semibold tracking-[0.08em] text-muted-foreground/70 uppercase';

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
            <div class="px-3 pt-4 pb-2">
                <p
                    class="truncate text-[13px] font-semibold text-sidebar-foreground"
                >
                    {{ organization?.name ?? branding.name }}
                </p>
                <p class="truncate text-[11px] text-muted-foreground">
                    <template v-if="nav && nav.assigned_to_me > 0"
                        >{{ nav.assigned_to_me }} assigned to you</template
                    >
                    <template v-else>Nothing waiting on you</template>
                </p>

                <Button
                    class="mt-3 h-8 w-full justify-start gap-2 rounded-lg bg-brand px-2.5 text-[13px] text-brand-foreground shadow-none hover:bg-brand/90"
                    @click="emit('compose')"
                >
                    <PenLine class="size-3.5" />
                    <span class="flex-1 text-left">New message</span>
                    <Kbd
                        class="h-4 min-w-4 bg-brand-foreground/15 px-1 text-[10px] text-brand-foreground/80"
                        >C</Kbd
                    >
                </Button>
            </div>
        </template>

        <nav>
            <template v-if="nav">
                <section class="mt-2">
                    <p :class="cn('px-2 pb-1.5', sectionLabelClass)">
                        Personal
                    </p>

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
                        <Link
                            :href="inboxUrl('all')"
                            :class="
                                cn(rowClass, isActive('all') && openRowClass)
                            "
                        >
                            <Layers
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span class="flex-1 truncate"
                                >All personal mail</span
                            >
                            <span
                                v-if="nav.personal_unread > 0"
                                class="text-[11px] font-semibold text-brand tabular-nums"
                                >{{ nav.personal_unread }}</span
                            >
                        </Link>
                        <Collapse :open="isActive('all')">
                            <div class="mt-0.5 mb-2 ml-4 space-y-px">
                                <Link
                                    v-for="view in views"
                                    :key="view.value"
                                    :href="inboxUrl('all', view.value)"
                                    :class="
                                        cn(
                                            viewRowClass,
                                            activeView === view.value &&
                                                activeViewClass,
                                        )
                                    "
                                >
                                    <component
                                        :is="viewIcons[view.value] ?? Mail"
                                        :class="
                                            cn(
                                                viewIconClass,
                                                activeView === view.value &&
                                                    'text-brand',
                                            )
                                        "
                                    />
                                    <span class="flex-1 truncate">{{
                                        viewLabels[view.value] ?? view.label
                                    }}</span>
                                    <span
                                        v-if="counts && counts[view.value]"
                                        class="text-[11px] text-muted-foreground tabular-nums"
                                        >{{ counts[view.value] }}</span
                                    >
                                </Link>
                            </div>
                        </Collapse>
                    </template>

                    <template v-for="inbox in nav.personal" :key="inbox.uuid">
                        <Link
                            :href="inboxUrl(inbox)"
                            :class="
                                cn(rowClass, isActive(inbox) && openRowClass)
                            "
                        >
                            <Mail
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span
                                class="flex-1 truncate"
                                :title="inbox.email ?? inbox.name"
                                >{{ inbox.email ?? inbox.name }}</span
                            >
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
                            <ChevronRight
                                :class="
                                    cn(
                                        'size-3.5 shrink-0 text-muted-foreground/0 transition group-hover:text-muted-foreground/60',
                                        isActive(inbox) &&
                                            'rotate-90 text-muted-foreground/60',
                                    )
                                "
                            />
                        </Link>
                        <Collapse :open="isActive(inbox)">
                            <div class="mt-0.5 mb-2 ml-4 space-y-px">
                                <Link
                                    v-for="view in views"
                                    :key="view.value"
                                    :href="inboxUrl(inbox, view.value)"
                                    :class="
                                        cn(
                                            viewRowClass,
                                            activeView === view.value &&
                                                activeViewClass,
                                        )
                                    "
                                >
                                    <component
                                        :is="viewIcons[view.value] ?? Mail"
                                        :class="
                                            cn(
                                                viewIconClass,
                                                activeView === view.value &&
                                                    'text-brand',
                                            )
                                        "
                                    />
                                    <span class="flex-1 truncate">{{
                                        viewLabels[view.value] ?? view.label
                                    }}</span>
                                    <span
                                        v-if="counts && counts[view.value]"
                                        class="text-[11px] text-muted-foreground tabular-nums"
                                        >{{ counts[view.value] }}</span
                                    >
                                </Link>
                            </div>
                        </Collapse>
                    </template>
                </section>

                <section class="mt-6">
                    <div class="flex items-center justify-between px-2 pb-1.5">
                        <p :class="sectionLabelClass">Shared</p>
                        <Link
                            v-if="organization"
                            :href="`/settings/organizations/${organization.slug}/inboxes`"
                            class="-mr-1 rounded-md p-1 text-muted-foreground/60 transition-colors hover:bg-sidebar-accent hover:text-foreground"
                            title="Manage shared inboxes"
                        >
                            <Plus class="size-3.5" />
                        </Link>
                    </div>

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
                        <Link
                            :href="inboxUrl(inbox)"
                            :class="
                                cn(rowClass, isActive(inbox) && openRowClass)
                            "
                        >
                            <span
                                class="flex size-4 shrink-0 items-center justify-center"
                            >
                                <InboxDot :color="inbox.color" size="sm" />
                            </span>
                            <span class="flex-1 truncate">{{
                                inbox.name
                            }}</span>
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
                        </Link>
                        <Collapse :open="isActive(inbox)">
                            <div class="mt-0.5 mb-2 ml-4 space-y-px">
                                <Link
                                    v-for="view in primaryViews"
                                    :key="view.value"
                                    :href="inboxUrl(inbox, view.value)"
                                    :class="
                                        cn(
                                            viewRowClass,
                                            activeView === view.value &&
                                                activeViewClass,
                                        )
                                    "
                                >
                                    <component
                                        :is="viewIcons[view.value] ?? Mail"
                                        :class="
                                            cn(
                                                viewIconClass,
                                                activeView === view.value &&
                                                    'text-brand',
                                            )
                                        "
                                    />
                                    <span class="flex-1 truncate">{{
                                        viewLabels[view.value] ?? view.label
                                    }}</span>
                                    <span
                                        v-if="counts && counts[view.value]"
                                        class="text-[11px] text-muted-foreground tabular-nums"
                                        >{{ counts[view.value] }}</span
                                    >
                                </Link>
                                <Link
                                    v-for="saved in savedViews ?? []"
                                    :key="`saved-${saved.id}`"
                                    :href="savedViewUrl(inbox, saved)"
                                    :class="viewRowClass"
                                >
                                    <Bookmark
                                        class="size-3.5 shrink-0 text-brand"
                                    />
                                    <span class="flex-1 truncate">{{
                                        saved.name
                                    }}</span>
                                </Link>
                                <details class="group/more">
                                    <summary
                                        :class="
                                            cn(
                                                viewRowClass,
                                                'cursor-pointer list-none text-muted-foreground select-none',
                                            )
                                        "
                                    >
                                        <ChevronRight
                                            class="size-3 transition group-open/more:rotate-90"
                                        />
                                        More
                                    </summary>
                                    <Link
                                        v-for="view in secondaryViews"
                                        :key="view.value"
                                        :href="inboxUrl(inbox, view.value)"
                                        :class="
                                            cn(
                                                viewRowClass,
                                                activeView === view.value &&
                                                    activeViewClass,
                                            )
                                        "
                                    >
                                        <component
                                            :is="viewIcons[view.value] ?? Mail"
                                            :class="
                                                cn(
                                                    viewIconClass,
                                                    activeView === view.value &&
                                                        'text-brand',
                                                )
                                            "
                                        />
                                        <span class="flex-1 truncate">{{
                                            viewLabels[view.value] ?? view.label
                                        }}</span>
                                    </Link>
                                </details>
                            </div>
                        </Collapse>
                    </template>
                </section>
            </template>
        </nav>
    </ShellColumn>
</template>
