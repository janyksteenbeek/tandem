import { shallowReactive, triggerRef, shallowRef } from 'vue';
import type { Issue, IssueListItem } from '@/types';

type Entity = IssueListItem | Issue;
type Listener = (change: {
    type: 'upsert' | 'remove';
    uuid: string;
    issue: Entity | null;
}) => void;

/**
 * One reactive map of issues per browser session, keyed by uuid. Inertia props are trusted and
 * overwrite; API responses and Echo payloads only win when they are not older than what we hold.
 * `patch` applies an optimistic change and returns the rollback.
 */
const entities = shallowReactive(new Map<string, Entity>());
const version = shallowRef(0);
const listeners = new Set<Listener>();

function bump(): void {
    version.value++;
    triggerRef(version);
}

function notify(
    type: 'upsert' | 'remove',
    uuid: string,
    issue: Entity | null,
): void {
    for (const listener of listeners) {
        listener({ type, uuid, issue });
    }
}

function isNewer(incoming: Entity, current: Entity | undefined): boolean {
    if (!current || !current.updated_at || !incoming.updated_at) {
        return true;
    }

    return incoming.updated_at >= current.updated_at;
}

export const issueStore = {
    version,

    get(uuid: string): Entity | undefined {
        return entities.get(uuid);
    },

    has(uuid: string): boolean {
        return entities.has(uuid);
    },

    /** From Inertia props: always trusted. Keeps detail-only fields if the incoming is a list item. */
    ingest(items: Entity[]): void {
        for (const item of items) {
            const current = entities.get(item.uuid);
            entities.set(
                item.uuid,
                current &&
                    'description_html' in current &&
                    !('description_html' in item)
                    ? { ...current, ...item }
                    : item,
            );
        }

        bump();
    },

    /** From API responses and realtime: drop stale payloads. */
    upsert(item: Entity, options: { force?: boolean } = {}): boolean {
        const current = entities.get(item.uuid);

        if (!options.force && !isNewer(item, current)) {
            return false;
        }

        const merged =
            current &&
            'description_html' in current &&
            !('description_html' in item)
                ? { ...current, ...item }
                : item;
        entities.set(item.uuid, merged);
        bump();
        notify('upsert', item.uuid, merged);

        return true;
    },

    /** Optimistic local change; returns a rollback restoring the previous snapshot. */
    patch(uuid: string, partial: Partial<Issue>): () => void {
        const current = entities.get(uuid);

        if (!current) {
            return () => {};
        }

        const snapshot = current;
        entities.set(uuid, { ...current, ...partial } as Entity);
        bump();
        notify('upsert', uuid, entities.get(uuid) ?? null);

        return () => {
            entities.set(uuid, snapshot);
            bump();
            notify('upsert', uuid, snapshot);
        };
    },

    remove(uuid: string): void {
        if (entities.delete(uuid)) {
            bump();
            notify('remove', uuid, null);
        }
    },

    subscribe(listener: Listener): () => void {
        listeners.add(listener);

        return () => listeners.delete(listener);
    },

    /** Test helper. */
    clear(): void {
        entities.clear();
        bump();
    },
};
