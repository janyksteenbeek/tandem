import type { LucideIcon } from '@lucide/vue';
import { CircleDot, Inbox, Settings, Video } from '@lucide/vue';
import { ref } from 'vue';

/** The four areas the rail switches between. Everything else lives inside one of them. */
export type AppSection = 'mail' | 'projects' | 'calls' | 'settings';

export type RailItem = {
    section: AppSection;
    title: string;
    href: string;
    icon: LucideIcon;
    badge?: number | null;
};

export type RailBadges = {
    mail?: number | null;
    projects?: number | null;
    calls?: number | null;
};

const SECTIONS: AppSection[] = ['mail', 'projects', 'calls', 'settings'];

/** Last URL visited per section, so the rail takes you back where you were. */
export type LastUrls = Partial<Record<AppSection, string>>;

const STORAGE_PREFIX = 'tandem:last-url';

/**
 * Remembered URLs, hydrated from sessionStorage after mount so server and first
 * client render agree on the rail's hrefs.
 */
export const rememberedUrls = ref<LastUrls>({});

const storage = (): Storage | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
};

const strip = (path: string): string => path.split('#')[0];

const pathname = (path: string): string => strip(path).split('?')[0];

/**
 * Which rail item a URL belongs to. Account and organization settings (`/settings/...`,
 * `/organizations/...`) and org settings (`/{org}/settings/...`) all count as settings;
 * `/{org}/calls/...` is calls; every other organization page that is not mail is projects.
 * The meeting room itself (`/meet/...`) has no rail and belongs to no section.
 */
export function sectionForPath(
    path: string,
    orgSlug: string | null,
): AppSection | null {
    const clean = pathname(path);

    if (clean.startsWith('/settings') || clean.startsWith('/organizations')) {
        return 'settings';
    }

    if (clean === '/' || clean.startsWith('/mail')) {
        return 'mail';
    }

    if (!orgSlug) {
        return null;
    }

    const prefix = `/${orgSlug}`;

    if (clean !== prefix && !clean.startsWith(`${prefix}/`)) {
        return null;
    }

    const rest = clean.slice(prefix.length) || '/';

    if (rest === '/mail' || rest.startsWith('/mail/')) {
        return 'mail';
    }

    if (rest === '/settings' || rest.startsWith('/settings/')) {
        return 'settings';
    }

    if (rest === '/calls' || rest.startsWith('/calls/')) {
        return 'calls';
    }

    return 'projects';
}

export function homeFor(section: AppSection | null, orgSlug: string): string {
    switch (section) {
        case 'projects':
            return `/${orgSlug}/my-issues`;
        case 'calls':
            return `/${orgSlug}/calls`;
        case 'settings':
            return '/settings/profile';
        default:
            return `/${orgSlug}/mail`;
    }
}

const key = (orgSlug: string, section: AppSection) =>
    `${STORAGE_PREFIX}:${orgSlug}:${section}`;

/** Read every remembered URL for an organization out of sessionStorage. */
export function loadRememberedUrls(orgSlug: string | null): void {
    const store = storage();

    if (!store || !orgSlug) {
        rememberedUrls.value = {};

        return;
    }

    const loaded: LastUrls = {};

    SECTIONS.forEach((section) => {
        const url = store.getItem(key(orgSlug, section));

        if (url) {
            loaded[section] = url;
        }
    });

    rememberedUrls.value = loaded;
}

/**
 * Remember a visited URL under its section. Index pages are remembered with their query
 * (filters, tabs); the hash is dropped.
 */
export function rememberVisit(path: string, orgSlug: string | null): void {
    const section = sectionForPath(path, orgSlug);

    if (!section || !orgSlug) {
        return;
    }

    const url = strip(path);

    rememberedUrls.value = { ...rememberedUrls.value, [section]: url };
    storage()?.setItem(key(orgSlug, section), url);
}

export function lastUrlFor(
    section: AppSection,
    orgSlug: string,
    remembered: LastUrls = rememberedUrls.value,
): string {
    return remembered[section] ?? homeFor(section, orgSlug);
}

/**
 * The rail: Mail, Projects and Calls on top, Settings in the bottom group. Each item returns
 * you to the last page you had open in that area.
 */
export function railItems(
    orgSlug: string | null,
    badges: RailBadges = {},
    remembered: LastUrls = rememberedUrls.value,
): RailItem[] {
    if (!orgSlug) {
        return [
            { section: 'mail', title: 'Mail', href: '/', icon: Inbox },
            {
                section: 'settings',
                title: 'Settings',
                href: '/settings/profile',
                icon: Settings,
            },
        ];
    }

    return [
        {
            section: 'mail',
            title: 'Mail',
            href: lastUrlFor('mail', orgSlug, remembered),
            icon: Inbox,
            badge: badges.mail ?? null,
        },
        {
            section: 'projects',
            title: 'Projects',
            href: lastUrlFor('projects', orgSlug, remembered),
            icon: CircleDot,
            badge: badges.projects ?? null,
        },
        {
            section: 'calls',
            title: 'Calls',
            href: lastUrlFor('calls', orgSlug, remembered),
            icon: Video,
            badge: badges.calls ?? null,
        },
        {
            section: 'settings',
            title: 'Settings',
            href: lastUrlFor('settings', orgSlug, remembered),
            icon: Settings,
        },
    ];
}
