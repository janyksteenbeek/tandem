import { useStorage } from '@vueuse/core';
import type { InjectionKey, Ref } from 'vue';
import { inject, provide, ref } from 'vue';

export type SidebarState = {
    /** The shell column is hidden on desktop. The mobile sheet ignores this. */
    collapsed: Ref<boolean>;
    toggle: () => void;
};

const sidebarKey: InjectionKey<SidebarState> = Symbol('app-sidebar-state');

export const SIDEBAR_STORAGE_KEY = 'tandem:sidebar-collapsed';

/**
 * Called once by the app layout. The preference lives in localStorage and is applied after
 * mount so the server render and the first client render agree.
 */
export function provideSidebarState(): SidebarState {
    const collapsed = useStorage<boolean>(
        SIDEBAR_STORAGE_KEY,
        false,
        undefined,
        {
            initOnMounted: true,
        },
    );

    const state: SidebarState = {
        collapsed,
        toggle: () => {
            collapsed.value = !collapsed.value;
        },
    };

    provide(sidebarKey, state);

    return state;
}

/** Anything inside the shell can read or toggle the column; outside it the column is always open. */
export function useSidebarState(): SidebarState {
    return (
        inject(sidebarKey, null) ?? {
            collapsed: ref(false),
            toggle: () => {},
        }
    );
}
