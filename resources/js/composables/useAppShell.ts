import type { Component, ComputedRef, InjectionKey, ShallowRef } from 'vue';
import { computed, inject, onBeforeUnmount, provide, shallowRef } from 'vue';

/**
 * What a page wants in the shell's second column, next to the rail.
 * Listeners go in `props` as `onX` keys so the column can talk back to the page.
 */
export type ShellColumn = {
    component: Component;
    props?: Record<string, unknown>;
    /** Hide the floating "open navigation" button on small screens, e.g. while reading a conversation. */
    hideMobileTrigger?: boolean;
    /** Handles "Compose" from the command palette while this page is open. */
    compose?: () => void;
};

type ColumnSlot = ShallowRef<ComputedRef<ShellColumn | null> | null>;

const columnKey: InjectionKey<ColumnSlot> = Symbol('app-shell-column');

/**
 * Called once by the persistent app layout; returns the column the current page registered.
 */
export function provideAppShell(): ComputedRef<ShellColumn | null> {
    const slot: ColumnSlot = shallowRef(null);

    provide(columnKey, slot);

    return computed(() => slot.value?.value ?? null);
}

/**
 * Called by a page or nested layout to place a component in the shell column.
 * The getter is reactive, so the column follows page props across visits.
 */
export function useShellColumn(getter: () => ShellColumn | null): void {
    const slot = inject(columnKey, null);

    if (!slot) {
        return;
    }

    const column = computed(getter);

    slot.value = column;

    onBeforeUnmount(() => {
        if (slot.value === column) {
            slot.value = null;
        }
    });
}
