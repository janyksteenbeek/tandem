import type { LucideIcon } from '@lucide/vue';
import {
    CircleDot,
    FolderKanban,
    Inbox,
    Package,
    Settings,
    Users,
} from '@lucide/vue';
import { ref } from 'vue';

export type AppSection =
    | 'mail'
    | 'my-issues'
    | 'products'
    | 'projects'
    | 'teams'
    | 'settings';

export type RailItem = {
    section: AppSection;
    title: string;
    href: string;
    icon: LucideIcon;
    badge?: number | null;
};

/** Org-relative path prefixes that light each rail item. */
const SECTION_PREFIXES: Record<AppSection, string[]> = {
    mail: ['/mail'],
    'my-issues': ['/my-issues', '/views', '/issues', '/reports'],
    products: ['/products'],
    projects: ['/projects', '/initiatives'],
    teams: ['/teams', '/cycles'],
    settings: ['/settings'],
};

const PROJECT_SECTIONS: AppSection[] = [
    'my-issues',
    'products',
    'projects',
    'teams',
];

/**
 * Issue pages are reachable from every projects section; the rail keeps the one
 * you came from. Defaults to My Issues so SSR and the first paint agree.
 */
export const lastProjectsSection = ref<AppSection>('my-issues');

export function isProjectsSection(section: AppSection | null): boolean {
    return section !== null && PROJECT_SECTIONS.includes(section);
}

/**
 * Which rail item a URL belongs to. Global settings (`/settings/...`) and organization
 * settings (`/{org}/settings/...`) both count as settings.
 */
export function sectionForPath(
    path: string,
    orgSlug: string | null,
): AppSection | null {
    const clean = path.split('?')[0].split('#')[0];

    if (clean.startsWith('/settings') || clean.startsWith('/organizations')) {
        return 'settings';
    }

    if (!orgSlug) {
        return null;
    }

    const prefix = `/${orgSlug}`;

    if (!clean.startsWith(prefix)) {
        return null;
    }

    const rest = clean.slice(prefix.length) || '/';

    if (rest.startsWith('/issues')) {
        return isProjectsSection(lastProjectsSection.value)
            ? lastProjectsSection.value
            : 'my-issues';
    }

    for (const [section, prefixes] of Object.entries(SECTION_PREFIXES) as [
        AppSection,
        string[],
    ][]) {
        if (prefixes.some((p) => rest === p || rest.startsWith(`${p}/`))) {
            return section;
        }
    }

    return null;
}

/** Remember the projects section a user is in so issue pages keep it lit. */
export function rememberSection(path: string, orgSlug: string | null): void {
    const section = sectionForPath(path, orgSlug);
    const rest = orgSlug ? path.replace(`/${orgSlug}`, '') : path;

    if (isProjectsSection(section) && !rest.startsWith('/issues')) {
        lastProjectsSection.value = section as AppSection;
    }
}

export function homeFor(section: AppSection | null, orgSlug: string): string {
    switch (section) {
        case 'my-issues':
            return `/${orgSlug}/my-issues`;
        case 'products':
            return `/${orgSlug}/products`;
        case 'projects':
            return `/${orgSlug}/projects/all`;
        case 'teams':
            return `/${orgSlug}/teams`;
        case 'settings':
            return `/${orgSlug}/settings`;
        default:
            return `/${orgSlug}/mail`;
    }
}

export function railItems(
    orgSlug: string | null,
    counts: { assigned?: number; due_today?: number } | null,
): RailItem[] {
    if (!orgSlug) {
        return [
            { section: 'mail', title: 'Mail', href: '/', icon: Inbox },
            {
                section: 'settings',
                title: 'Settings',
                href: '/settings/mail-accounts',
                icon: Settings,
            },
        ];
    }

    return [
        {
            section: 'mail',
            title: 'Mail',
            href: `/${orgSlug}/mail`,
            icon: Inbox,
        },
        {
            section: 'my-issues',
            title: 'My issues',
            href: `/${orgSlug}/my-issues`,
            icon: CircleDot,
            badge: counts?.due_today ?? null,
        },
        {
            section: 'products',
            title: 'Products',
            href: `/${orgSlug}/products`,
            icon: Package,
        },
        {
            section: 'projects',
            title: 'Projects',
            href: `/${orgSlug}/projects/all`,
            icon: FolderKanban,
        },
        {
            section: 'teams',
            title: 'Teams',
            href: `/${orgSlug}/teams`,
            icon: Users,
        },
        {
            section: 'settings',
            title: 'Settings',
            href: '/settings/mail-accounts',
            icon: Settings,
        },
    ];
}
