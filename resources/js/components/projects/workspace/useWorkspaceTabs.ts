import { router } from '@inertiajs/vue3';
import type { WorkspaceTab } from '@/components/projects/workspace/WorkspaceTabs.vue';

/**
 * Tab definitions for a workspace page: each tab is a URL under the entity and a partial visit
 * that fetches only its own payload (plus the display state the list tabs need).
 */
export function workspaceTabs(
    baseUrl: string,
    tabs: {
        key: string;
        label: string;
        props?: string[];
        count?: number | null;
        query?: string;
    }[],
): WorkspaceTab[] {
    return tabs.map((tab) => ({
        key: tab.key,
        label: tab.label,
        href: `${baseUrl}/${tab.key}${tab.query ?? ''}`,
        count: tab.count ?? null,
        only: ['tab', 'filters', 'display', ...(tab.props ?? [tab.key])],
    }));
}

export function visitTab(baseUrl: string, tab: WorkspaceTab): void {
    router.visit(tab.href, {
        only: tab.only,
        preserveState: true,
        preserveScroll: true,
    });
}

export { type WorkspaceTab };
