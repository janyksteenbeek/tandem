import { usePage } from '@inertiajs/vue3';
import { useStorage } from '@vueuse/core';
import { computed } from 'vue';
import type { IssueDisplay } from '@/types';

type Preferences = {
    collapsed: Record<string, boolean>;
    expanded: Record<string, boolean>;
    display: Record<
        string,
        Partial<IssueDisplay> & {
            collapsedGroups?: string[];
            collapsedColumns?: number[];
        }
    >;
};

const DEFAULTS: Preferences = { collapsed: {}, expanded: {}, display: {} };

/**
 * Sidebar and list display preferences, per organization, in localStorage. SSR renders the
 * defaults and the browser applies the stored state after mount, so markup never mismatches.
 */
export function useSidebarPreferences() {
    const page = usePage();
    const slug = computed(() => page.props.currentOrganization?.slug ?? 'none');
    const state = useStorage<Preferences>(
        `tandem:projects-sidebar:v1:${slug.value}`,
        structuredClone(DEFAULTS),
        undefined,
        {
            initOnMounted: true,
            mergeDefaults: true,
        },
    );

    return {
        isCollapsed: (group: string) => state.value.collapsed[group] === true,
        toggleGroup: (group: string) => {
            state.value = {
                ...state.value,
                collapsed: {
                    ...state.value.collapsed,
                    [group]: !state.value.collapsed[group],
                },
            };
        },
        isExpanded: (key: string) => state.value.expanded[key] === true,
        toggleExpanded: (key: string) => {
            state.value = {
                ...state.value,
                expanded: {
                    ...state.value.expanded,
                    [key]: !state.value.expanded[key],
                },
            };
        },
        displayFor: (container: string) => state.value.display[container] ?? {},
        rememberDisplay: (
            container: string,
            display: Partial<IssueDisplay> & {
                collapsedGroups?: string[];
                collapsedColumns?: number[];
            },
        ) => {
            state.value = {
                ...state.value,
                display: {
                    ...state.value.display,
                    [container]: {
                        ...state.value.display[container],
                        ...display,
                    },
                },
            };
        },
    };
}
