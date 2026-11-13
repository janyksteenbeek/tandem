/**
 * One window keydown listener, many layers. Each layer owns a set of shortcuts and a priority;
 * blocking layers (dialogs, editors) stop lower layers from firing single-key shortcuts while
 * `global` shortcuts (⌘K, ?) always get through.
 */
export type Shortcut = {
    keys: string;
    description: string;
    handler: (event: KeyboardEvent) => void;
    /** Fire even when the focus is in an input or editor. */
    allowInInput?: boolean;
    /** Fire through blocking layers (dialogs, editors). */
    global?: boolean;
    /** Keep out of the shortcuts dialog. */
    hidden?: boolean;
    /** Grouping label in the shortcuts dialog. */
    group?: string;
};

export type Layer = {
    id: number;
    scope: string;
    priority: number;
    blocking: boolean;
    shortcuts: () => Shortcut[];
};

export const PRIORITY = {
    global: 0,
    section: 5,
    page: 10,
    detail: 20,
    picker: 50,
    dialog: 100,
    editor: 200,
} as const;

const MODIFIER_PATTERN = /^(mod|ctrl|meta|alt|shift)\+/i;
const SEQUENCE_TIMEOUT = 800;

export function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    const tag = target.tagName.toLowerCase();

    return (
        tag === 'input' ||
        tag === 'textarea' ||
        tag === 'select' ||
        target.isContentEditable
    );
}

export function matches(combo: string, event: KeyboardEvent): boolean {
    const parts = combo.toLowerCase().split('+');
    const key = parts[parts.length - 1];
    const wantsMod = parts.includes('mod');
    const wantsShift = parts.includes('shift');
    const wantsAlt = parts.includes('alt');
    const isMac =
        typeof navigator !== 'undefined' &&
        navigator.platform.toUpperCase().includes('MAC');
    const modPressed = isMac ? event.metaKey : event.ctrlKey;

    if (
        wantsMod !== modPressed ||
        wantsShift !== event.shiftKey ||
        wantsAlt !== event.altKey
    ) {
        return false;
    }

    if (!wantsMod && (event.metaKey || event.ctrlKey)) {
        return false;
    }

    const pressed = event.key.toLowerCase();

    if (key === 'escape') {
        return pressed === 'escape' || pressed === 'esc';
    }

    if (
        key === 'arrowup' ||
        key === 'arrowdown' ||
        key === 'arrowleft' ||
        key === 'arrowright'
    ) {
        return pressed === key;
    }

    return pressed === key || (key === '/' && pressed === '?' && !wantsShift);
}

/** "mod+k" → "⌘ K" on a Mac, "Ctrl K" elsewhere; "g i" → "G then I". */
export function formatKeys(keys: string): string {
    const isMac =
        typeof navigator !== 'undefined' &&
        navigator.platform.toUpperCase().includes('MAC');

    if (keys.includes(' ')) {
        return keys.split(' ').map(formatKeys).join(' then ');
    }

    return keys
        .split('+')
        .map((part) => {
            switch (part.toLowerCase()) {
                case 'mod':
                    return isMac ? '⌘' : 'Ctrl';
                case 'shift':
                    return '⇧';
                case 'alt':
                    return isMac ? '⌥' : 'Alt';
                case 'escape':
                    return 'Esc';
                case 'enter':
                    return '↵';
                case 'arrowup':
                    return '↑';
                case 'arrowdown':
                    return '↓';
                case 'backspace':
                    return '⌫';
                default:
                    return part.length === 1 ? part.toUpperCase() : part;
            }
        })
        .join(' ');
}

class KeyboardRegistry {
    private layers: Layer[] = [];

    private nextId = 1;

    private pending: string | null = null;

    private pendingTimer: ReturnType<typeof setTimeout> | null = null;

    private attached = false;

    register(
        scope: string,
        priority: number,
        blocking: boolean,
        shortcuts: () => Shortcut[],
    ): () => void {
        const layer: Layer = {
            id: this.nextId++,
            scope,
            priority,
            blocking,
            shortcuts,
        };
        this.layers.push(layer);
        this.attach();

        return () => {
            this.layers = this.layers.filter((l) => l.id !== layer.id);
        };
    }

    /** Shortcuts visible in the help dialog, grouped by group label or scope. */
    visible(): { group: string; shortcuts: Shortcut[] }[] {
        const groups = new Map<string, Shortcut[]>();

        for (const layer of [...this.layers].sort(
            (a, b) => a.priority - b.priority,
        )) {
            for (const shortcut of layer.shortcuts()) {
                if (shortcut.hidden) {
                    continue;
                }

                const name = shortcut.group ?? layer.scope;
                const list = groups.get(name) ?? [];

                if (!list.some((s) => s.keys === shortcut.keys)) {
                    list.push(shortcut);
                }

                groups.set(name, list);
            }
        }

        return [...groups.entries()].map(([group, shortcuts]) => ({
            group,
            shortcuts,
        }));
    }

    private attach(): void {
        if (this.attached || typeof window === 'undefined') {
            return;
        }

        window.addEventListener('keydown', this.handle);
        this.attached = true;
    }

    private handle = (event: KeyboardEvent): void => {
        const typing = isTypingTarget(event.target);
        const ordered = [...this.layers].sort(
            (a, b) => b.priority - a.priority,
        );
        let blocked = false;

        for (const layer of ordered) {
            for (const shortcut of layer.shortcuts()) {
                if (blocked && !shortcut.global) {
                    continue;
                }

                if (
                    typing &&
                    !shortcut.allowInInput &&
                    !MODIFIER_PATTERN.test(shortcut.keys)
                ) {
                    continue;
                }

                if (shortcut.keys.includes(' ')) {
                    const [first, second] = shortcut.keys.split(' ');

                    if (this.pending === first && matches(second, event)) {
                        this.clearPending();
                        event.preventDefault();
                        shortcut.handler(event);

                        return;
                    }

                    if (!this.pending && matches(first, event) && !typing) {
                        this.pending = first;
                        this.pendingTimer = setTimeout(
                            () => this.clearPending(),
                            SEQUENCE_TIMEOUT,
                        );
                        event.preventDefault();

                        return;
                    }

                    continue;
                }

                if (matches(shortcut.keys, event)) {
                    this.clearPending();
                    event.preventDefault();
                    shortcut.handler(event);

                    return;
                }
            }

            if (layer.blocking) {
                blocked = true;
            }
        }
    };

    private clearPending(): void {
        this.pending = null;

        if (this.pendingTimer) {
            clearTimeout(this.pendingTimer);
            this.pendingTimer = null;
        }
    }
}

let registry: KeyboardRegistry | null = null;

export function getKeyboardRegistry(): KeyboardRegistry {
    return (registry ??= new KeyboardRegistry());
}
