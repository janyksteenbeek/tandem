import { onBeforeUnmount, onMounted } from 'vue';

export type Shortcut = {
    keys: string;
    description: string;
    handler: (event: KeyboardEvent) => void;
    allowInInput?: boolean;
};

const MODIFIER_PATTERN = /^(mod|ctrl|meta|alt|shift)\+/i;

function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    const tag = target.tagName.toLowerCase();

    return tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable;
}

function matches(combo: string, event: KeyboardEvent): boolean {
    const parts = combo.toLowerCase().split('+');
    const key = parts[parts.length - 1];
    const wantsMod = parts.includes('mod');
    const wantsShift = parts.includes('shift');
    const wantsAlt = parts.includes('alt');

    const modPressed = navigator.platform.toUpperCase().includes('MAC') ? event.metaKey : event.ctrlKey;

    if (wantsMod !== modPressed) {
        return false;
    }

    if (wantsShift !== event.shiftKey) {
        return false;
    }

    if (wantsAlt !== event.altKey) {
        return false;
    }

    if (!wantsMod && (event.metaKey || event.ctrlKey)) {
        return false;
    }

    return event.key.toLowerCase() === key;
}

/**
 * Registers single-key and chorded shortcuts for the lifetime of a component.
 * Sequences like "g i" are expressed as `keys: 'g i'` and resolved within 800ms.
 */
export function useKeyboard(shortcuts: () => Shortcut[]): void {
    let pending: string | null = null;
    let pendingTimer: ReturnType<typeof setTimeout> | null = null;

    const handler = (event: KeyboardEvent): void => {
        const typing = isTypingTarget(event.target);

        for (const shortcut of shortcuts()) {
            if (typing && !shortcut.allowInInput && !MODIFIER_PATTERN.test(shortcut.keys)) {
                continue;
            }

            if (shortcut.keys.includes(' ')) {
                const [first, second] = shortcut.keys.split(' ');

                if (pending === first && matches(second, event)) {
                    pending = null;
                    event.preventDefault();
                    shortcut.handler(event);

                    return;
                }

                if (!pending && matches(first, event) && !typing) {
                    pending = first;

                    if (pendingTimer) {
                        clearTimeout(pendingTimer);
                    }

                    pendingTimer = setTimeout(() => (pending = null), 800);
                    event.preventDefault();

                    return;
                }

                continue;
            }

            if (matches(shortcut.keys, event)) {
                event.preventDefault();
                shortcut.handler(event);

                return;
            }
        }
    };

    onMounted(() => window.addEventListener('keydown', handler));
    onBeforeUnmount(() => window.removeEventListener('keydown', handler));
}
