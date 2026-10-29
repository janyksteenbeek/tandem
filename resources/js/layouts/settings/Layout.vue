<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    FileText,
    Inbox,
    Mail,
    Palette,
    Plug,
    ShieldCheck,
    UserRound,
    Users,
    Workflow,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { index as automationsIndex } from '@/routes/automations';
import { index as mailAccountsIndex } from '@/routes/mail/accounts';
import { index as organizations } from '@/routes/organizations';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as inboxesIndex } from '@/routes/settings/inboxes';
import { edit as integrationsEdit } from '@/routes/settings/integrations';
import { index as teamsIndex } from '@/routes/settings/teams';
import { index as templatesIndex } from '@/routes/templates';
import type { NavItem } from '@/types';

type NavGroup = { label: string; items: (NavItem & { icon: LucideIcon })[] };

const page = usePage();
const organization = computed(() => page.props.currentOrganization);
const canManage = computed(() => page.props.canManageOrganization);

const groups = computed<NavGroup[]>(() => {
    const result: NavGroup[] = [
        {
            label: 'You',
            items: [
                { title: 'Profile', href: editProfile(), icon: UserRound },
                { title: 'Security', href: editSecurity(), icon: ShieldCheck },
                { title: 'Appearance', href: editAppearance(), icon: Palette },
            ],
        },
        {
            label: 'Mail',
            items: [
                {
                    title: 'Mail accounts',
                    href: mailAccountsIndex(),
                    icon: Mail,
                },
            ],
        },
    ];

    const workspace: NavGroup = {
        label: organization.value?.name ?? 'Organization',
        items: [
            { title: 'Organizations', href: organizations(), icon: Building2 },
        ],
    };

    if (organization.value) {
        const slug = organization.value.slug;

        workspace.items.push(
            { title: 'Inboxes', href: inboxesIndex(slug), icon: Inbox },
            { title: 'Teams', href: teamsIndex(slug), icon: Users },
        );

        if (canManage.value) {
            workspace.items.push(
                {
                    title: 'Integrations',
                    href: integrationsEdit(slug),
                    icon: Plug,
                },
                {
                    title: 'Automations',
                    href: automationsIndex(slug),
                    icon: Workflow,
                },
                {
                    title: 'Templates',
                    href: templatesIndex(slug),
                    icon: FileText,
                },
            );
        }
    }

    result.push(workspace);

    return result;
});

const mailUrl = computed(() =>
    organization.value ? `/${organization.value.slug}/mail` : '/',
);

/** Account pages keep the compact column from the starter kit; everything else is denser and wants the room. */
const narrow = computed(() =>
    ['settings/Profile', 'settings/Security', 'settings/Appearance'].includes(
        page.component,
    ),
);

const { currentUrl } = useCurrentUrl();

/** The most specific matching item wins, so "Organizations" does not light up next to "Inboxes". */
const activeHref = computed(() => {
    const path = currentUrl.value;

    return groups.value
        .flatMap((group) => group.items)
        .map((item) => toUrl(item.href))
        .filter((href) => path === href || path.startsWith(`${href}/`))
        .sort((a, b) => b.length - a.length)[0];
});

const isActive = (href: NavItem['href']): boolean =>
    toUrl(href) === activeHref.value;
</script>

<template>
    <div class="px-4 py-6 sm:px-6">
        <header class="mb-8 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="font-display text-3xl leading-tight">Settings</h1>
                <p class="text-sm text-muted-foreground">
                    Your account, your mail, and how
                    {{ organization?.name ?? 'your team' }} works together.
                </p>
            </div>
            <Button variant="outline" size="sm" as-child>
                <Link :href="mailUrl"><ArrowLeft /> Back to mail</Link>
            </Button>
        </header>

        <div class="flex flex-col gap-8 lg:flex-row lg:gap-12">
            <aside class="w-full shrink-0 lg:w-52">
                <nav class="flex flex-col gap-5" aria-label="Settings">
                    <div v-for="group in groups" :key="group.label">
                        <p
                            class="mb-1 truncate px-2 text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase"
                        >
                            {{ group.label }}
                        </p>
                        <div
                            class="-mx-1 flex scrollbar-thin gap-1 overflow-x-auto px-1 pb-1 lg:mx-0 lg:flex-col lg:overflow-visible lg:px-0 lg:pb-0"
                        >
                            <Button
                                v-for="item in group.items"
                                :key="toUrl(item.href)"
                                variant="ghost"
                                size="sm"
                                :class="
                                    cn(
                                        'shrink-0 justify-start gap-2 text-muted-foreground hover:text-foreground lg:w-full',
                                        isActive(item.href) &&
                                            'bg-brand-soft/60 font-medium text-brand-soft-foreground hover:bg-brand-soft/80 hover:text-brand-soft-foreground',
                                    )
                                "
                                as-child
                            >
                                <Link :href="item.href">
                                    <component :is="item.icon" class="size-4" />
                                    {{ item.title }}
                                </Link>
                            </Button>
                        </div>
                    </div>
                </nav>
            </aside>

            <Separator class="lg:hidden" />

            <div class="min-w-0 flex-1">
                <section
                    :class="cn('space-y-12', narrow ? 'max-w-xl' : 'max-w-5xl')"
                >
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
