import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { getKeyboardRegistry, PRIORITY } from '@/lib/keyboard';
import type { Shortcut } from '@/lib/keyboard';

export type { Shortcut };

type Options = { scope?: string; priority?: number; blocking?: boolean };

/**
 * Registers shortcuts for the lifetime of a component. Sequences like "g i" are written with a
 * space. Higher-priority layers (dialogs, editors) win; see lib/keyboard.ts.
 */
export function useKeyboard(
    shortcuts: () => Shortcut[],
    options: Options = {},
): void {
    let release: (() => void) | undefined;

    onMounted(() => {
        release = getKeyboardRegistry().register(
            options.scope ?? 'page',
            options.priority ?? PRIORITY.page,
            options.blocking ?? false,
            shortcuts,
        );
    });

    onBeforeUnmount(() => release?.());
}

/**
 * A blocking layer that is only active while `active` is true: rich-text editors on focus,
 * dialogs while open. Non-global single-key shortcuts below it stay quiet.
 */
export function useShortcutScope(
    scope: string,
    active: Ref<boolean>,
    priority: number = PRIORITY.dialog,
    shortcuts: () => Shortcut[] = () => [],
): void {
    let release: (() => void) | undefined;
    const mounted = ref(false);

    const sync = () => {
        release?.();
        release = undefined;

        if (mounted.value && active.value) {
            release = getKeyboardRegistry().register(
                scope,
                priority,
                true,
                shortcuts,
            );
        }
    };

    onMounted(() => {
        mounted.value = true;
        sync();
    });

    watch(active, sync);
    onBeforeUnmount(() => release?.());
}
