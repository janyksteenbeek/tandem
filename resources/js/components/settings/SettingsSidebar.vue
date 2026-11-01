<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    AtSign,
    Building2,
    FileText,
    Keyboard,
    LayoutGrid,
    Mailbox,
    Palette,
    Plug,
    Search,
    ShieldCheck,
    UserRound,
    Users,
    Workflow,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, inject, ref } from 'vue';
import ShellColumn from '@/components/shell/ShellColumn.vue';
import ShellNavItem from '@/components/shell/ShellNavItem.vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAppearance } from '@/composables/useAppearance';
import { useBranding } from '@/composables/useBranding';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useKeyboard } from '@/composables/useKeyboard';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { index as automationsIndex } from '@/routes/automations';
import { index as mailAccountsIndex } from '@/routes/mail/accounts';
import {
    edit as organizationEdit,
    index as organizations,
} from '@/routes/organizations';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as inboxesIndex } from '@/routes/settings/inboxes';
import { edit as integrationsEdit } from '@/routes/settings/integrations';
import { index as teamsIndex } from '@/routes/settings/teams';
import { index as templatesIndex } from '@/routes/templates';

/**
 * A settings entry. `meta` is the quiet trailing text (a count, a state),
 * `attention` swaps it for a warning so problems are visible before you click.
 */
type SettingsItem = {
    title: string;
    href: Parameters<typeof toUrl>[0];
    icon: LucideIcon;
    keywords?: string;
    meta?: string | number | null;
    attention?: string | null;
};

type SettingsGroup = {
    label: string;
    initial?: string;
    items: SettingsItem[];
};

const page = usePage();
const branding = useBranding();
const { appearance } = useAppearance();
const { currentUrl } = useCurrentUrl();
const openShortcuts = inject<() => void>('openShortcuts', () => {});

const user = computed(() => page.props.auth.user);
const organization = computed(() => page.props.currentOrganization);
const canManage = computed(() => Boolean(page.props.canManageOrganization));
const nav = computed(() => page.props.mailNav);
const organizationCount = computed(() => page.props.organizations?.length ?? 0);

const personalAttention = computed(
    () =>
        nav.value?.personal.filter((inbox) => inbox.health === 'attention')
            .length ?? 0,
);
const sharedAttention = computed(
    () =>
        nav.value?.shared.filter((inbox) => inbox.health === 'attention')
            .length ?? 0,
);
const attentionTotal = computed(
    () => personalAttention.value + sharedAttention.value,
);

const appearanceLabel = computed(
    () => ({ light: 'Light', dark: 'Dark', system: 'Auto' })[appearance.value],
);

const plural = (count: number, singular: string, pluralForm: string) =>
    `${count} ${count === 1 ? singular : pluralForm}`;

const status = computed(() => {
    if (attentionTotal.value > 0) {
        return {
            tone: 'warning' as const,
            text: `${plural(attentionTotal.value, 'account needs', 'accounts need')} attention`,
        };
    }

    if (!user.value.two_factor_enabled) {
        return {
            tone: 'muted' as const,
            text: 'Two-factor is off.',
        };
    }

    return { tone: 'muted' as const, text: 'Everything is connected.' };
});

const groups = computed<SettingsGroup[]>(() => {
    const result: SettingsGroup[] = [
        {
            label: 'You',
            items: [
                {
                    title: 'Profile',
                    href: editProfile(),
                    icon: UserRound,
                    keywords: 'name email avatar account',
                },
                {
                    title: 'Mail accounts',
                    href: mailAccountsIndex(),
                    icon: AtSign,
                    keywords: 'gmail imap smtp connect sync health',
                    meta: nav.value?.personal.length ?? null,
                    attention:
                        personalAttention.value > 0
                            ? `${plural(personalAttention.value, 'account needs', 'accounts need')} attention`
                            : null,
                },
                {
                    title: 'Organizations',
                    href: organizations(),
                    icon: LayoutGrid,
                    keywords: 'workspaces switch create invitations',
                    meta:
                        organizationCount.value > 1
                            ? organizationCount.value
                            : null,
                },
                {
                    title: 'Security',
                    href: editSecurity(),
                    icon: ShieldCheck,
                    keywords: 'password two-factor 2fa passkeys sessions',
                    meta: user.value.two_factor_enabled ? null : '2FA off',
                },
                {
                    title: 'Appearance',
                    href: editAppearance(),
                    icon: Palette,
                    keywords: 'theme dark light mode',
                    meta: appearanceLabel.value,
                },
            ],
        },
    ];

    if (!organization.value) {
        return result;
    }

    const slug = organization.value.slug;

    const workspace: SettingsGroup = {
        label: organization.value.name,
        initial: organization.value.name.slice(0, 1).toUpperCase(),
        items: [
            {
                title: 'Organization',
                href: organizationEdit(slug),
                icon: Building2,
                keywords: 'name members roles invite leave delete',
            },
            {
                title: 'Inboxes',
                href: inboxesIndex(slug),
                icon: Mailbox,
                keywords: 'shared inbox forwarding channels access',
                meta: nav.value?.shared.length ?? null,
                attention:
                    sharedAttention.value > 0
                        ? `${plural(sharedAttention.value, 'inbox has', 'inboxes have')} a channel that needs attention`
                        : null,
            },
            {
                title: 'Teams',
                href: teamsIndex(slug),
                icon: Users,
                keywords: 'groups people members access',
            },
        ],
    };

    if (canManage.value) {
        workspace.items.push(
            {
                title: 'Integrations',
                href: integrationsEdit(slug),
                icon: Plug,
                keywords: 'google oauth smtp business hours',
            },
            {
                title: 'Automations',
                href: automationsIndex(slug),
                icon: Workflow,
                keywords: 'rules tag route assign reply',
            },
            {
                title: 'Templates',
                href: templatesIndex(slug),
                icon: FileText,
                keywords: 'canned responses snippets replies',
            },
        );
    }

    result.push(workspace);

    return result;
});

const query = ref('');
const filterInput = ref<HTMLInputElement | null>(null);

const matches = (item: SettingsItem): boolean => {
    const needle = query.value.trim().toLowerCase();

    if (!needle) {
        return true;
    }

    return `${item.title} ${item.keywords ?? ''}`
        .toLowerCase()
        .includes(needle);
};

const visibleGroups = computed(() =>
    groups.value
        .map((group) => ({ ...group, items: group.items.filter(matches) }))
        .filter((group) => group.items.length > 0),
);

/** The most specific matching item wins, so "Organizations" does not light up next to "Inboxes". */
const activeHref = computed(() => {
    const path = currentUrl.value;

    return groups.value
        .flatMap((group) => group.items)
        .map((item) => toUrl(item.href))
        .filter((href) => path === href || path.startsWith(`${href}/`))
        .sort((a, b) => b.length - a.length)[0];
});

const isActive = (item: SettingsItem): boolean =>
    toUrl(item.href) === activeHref.value;

const mailUrl = computed(() =>
    organization.value ? `/${organization.value.slug}/mail` : '/',
);

useKeyboard(() => [
    {
        keys: '/',
        description: 'Find a setting',
        handler: (event) => {
            event.preventDefault();
            filterInput.value?.focus();
        },
    },
]);
</script>

<template>
    <ShellColumn label="Settings">
        <template #header>
            <div class="flex items-start justify-between gap-2 px-3 pt-4 pb-2">
                <div class="min-w-0">
                    <p
                        class="font-display text-[19px] leading-tight text-sidebar-foreground"
                    >
                        Settings
                    </p>
                    <p
                        class="mt-0.5 truncate text-[11px]"
                        :class="
                            status.tone === 'warning'
                                ? 'font-medium text-warning-foreground dark:text-warning'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ status.text }}
                    </p>
                </div>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            variant="outline"
                            size="icon"
                            class="size-8 shrink-0 rounded-lg"
                            as-child
                        >
                            <Link :href="mailUrl" aria-label="Back to mail">
                                <ArrowLeft class="size-4" />
                            </Link>
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent side="bottom"
                        >Back to mail
                        <kbd class="ml-1 rounded border px-1 text-[10px]"
                            >G M</kbd
                        ></TooltipContent
                    >
                </Tooltip>
            </div>

            <label class="relative mx-3 mb-2 block">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    ref="filterInput"
                    v-model="query"
                    type="search"
                    placeholder="Find a setting…"
                    class="h-8 w-full rounded-lg border border-sidebar-border bg-background pr-8 pl-8 text-[13px] text-foreground placeholder:text-muted-foreground focus:border-brand/50 focus:ring-2 focus:ring-brand/20 focus:outline-none"
                    aria-label="Find a setting"
                />
                <kbd
                    v-if="!query"
                    class="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 rounded border px-1 text-[10px] text-muted-foreground"
                    >/</kbd
                >
            </label>
        </template>

        <nav class="flex flex-col gap-5 pt-1" aria-label="Settings sections">
            <section v-for="group in visibleGroups" :key="group.label">
                <p
                    class="mb-1 flex items-center gap-1.5 truncate px-2 text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase"
                >
                    <span
                        v-if="group.initial"
                        class="flex size-4 shrink-0 items-center justify-center rounded bg-brand-soft text-[9px] font-bold text-brand-soft-foreground"
                        aria-hidden="true"
                        >{{ group.initial }}</span
                    >
                    <span class="truncate">{{ group.label }}</span>
                </p>
                <div class="flex flex-col gap-0.5">
                    <ShellNavItem
                        v-for="item in group.items"
                        :key="toUrl(item.href)"
                        :href="item.href"
                        :icon="item.icon"
                        :active="isActive(item)"
                    >
                        {{ item.title }}
                        <template #trailing>
                            <Tooltip v-if="item.attention">
                                <TooltipTrigger as-child>
                                    <AlertTriangle
                                        class="size-3.5 shrink-0 text-warning"
                                    />
                                </TooltipTrigger>
                                <TooltipContent side="right">{{
                                    item.attention
                                }}</TooltipContent>
                            </Tooltip>
                            <span
                                v-else-if="
                                    item.meta !== null &&
                                    item.meta !== undefined
                                "
                                class="text-[11px] text-muted-foreground tabular-nums"
                                >{{ item.meta }}</span
                            >
                        </template>
                    </ShellNavItem>
                </div>
            </section>

            <div
                v-if="visibleGroups.length === 0"
                class="mx-1 rounded-lg border border-dashed border-sidebar-border px-3 py-3 text-xs text-muted-foreground"
            >
                <p class="font-medium text-sidebar-foreground">
                    Nothing called “{{ query.trim() }}”.
                </p>
            </div>
        </nav>

        <template #footer>
            <button
                type="button"
                class="flex items-center gap-1.5 rounded px-1 py-0.5 text-muted-foreground transition-colors hover:text-foreground"
                @click="openShortcuts()"
            >
                <Keyboard class="size-3.5" />
                Shortcuts
                <kbd class="rounded border px-1 text-[10px]">?</kbd>
            </button>
            <span class="ml-auto truncate font-display text-[13px] italic">{{
                branding.name
            }}</span>
        </template>
    </ShellColumn>
</template>
