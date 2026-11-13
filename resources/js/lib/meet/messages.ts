import type { CallMessage } from '@/types';
import type { LocalMessage } from './types';

/**
 * Keeping the chat list right when the same message can arrive three ways: optimistically
 * from the composer, in the HTTP response, and over the realtime channel, in any order.
 */

export function newClientId(): string {
    const random =
        typeof crypto !== 'undefined' && 'randomUUID' in crypto
            ? crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(16).slice(2)}`;

    return random.slice(0, 36);
}

/** Server messages sort by id; unsent ones stay at the end in the order they were typed. */
function compare(a: LocalMessage, b: LocalMessage): number {
    const aPending = a.state !== 'sent';
    const bPending = b.state !== 'sent';

    if (aPending !== bPending) {
        return aPending ? 1 : -1;
    }

    if (aPending) {
        return (a.created_at ?? '').localeCompare(b.created_at ?? '');
    }

    return a.id - b.id;
}

/**
 * Put a message from the server into the list. It replaces the entry with the same uuid, or
 * the optimistic entry it confirms (same author and client id); otherwise it is added.
 */
export function upsertMessage(
    list: LocalMessage[],
    incoming: CallMessage,
): LocalMessage[] {
    const confirmed: LocalMessage = { ...incoming, state: 'sent', error: null };

    const index = list.findIndex(
        (message) =>
            message.uuid === incoming.uuid ||
            (incoming.client_id !== null &&
                message.client_id === incoming.client_id &&
                message.author?.uuid === incoming.author?.uuid),
    );

    const next =
        index === -1
            ? [...list, confirmed]
            : list.map((message, i) => (i === index ? confirmed : message));

    return next.sort(compare);
}

export function mergeMessages(
    list: LocalMessage[],
    incoming: CallMessage[],
): LocalMessage[] {
    return incoming.reduce(upsertMessage, list);
}

export function markFailed(
    list: LocalMessage[],
    clientId: string,
    error: string,
): LocalMessage[] {
    return list.map((message) =>
        message.client_id === clientId && message.state !== 'sent'
            ? { ...message, state: 'failed', error }
            : message,
    );
}

export function markPending(
    list: LocalMessage[],
    clientId: string,
): LocalMessage[] {
    return list.map((message) =>
        message.client_id === clientId && message.state === 'failed'
            ? { ...message, state: 'pending', error: null }
            : message,
    );
}

export function removeLocal(
    list: LocalMessage[],
    clientId: string,
): LocalMessage[] {
    return list.filter(
        (message) =>
            !(message.client_id === clientId && message.state !== 'sent'),
    );
}

/** The highest id the server has confirmed: where "everything after" starts. */
export function lastConfirmedId(list: LocalMessage[]): number {
    return list.reduce(
        (highest, message) =>
            message.state === 'sent' && message.id > highest
                ? message.id
                : highest,
        0,
    );
}

export function firstConfirmedId(list: LocalMessage[]): number | null {
    const ids = list
        .filter((message) => message.state === 'sent')
        .map((message) => message.id);

    return ids.length > 0 ? Math.min(...ids) : null;
}

/** Messages from other people that arrived after the last one this person has read. */
export function countUnread(
    list: LocalMessage[],
    lastReadId: number,
    myUuid: string | null,
): number {
    return list.filter(
        (message) =>
            message.state === 'sent' &&
            !message.deleted &&
            message.id > lastReadId &&
            message.author?.uuid !== myUuid,
    ).length;
}

/**
 * Whether a message continues the previous one closely enough (same author, within five
 * minutes, not a reply) to be shown without repeating the name and avatar.
 */
export function continuesPrevious(
    message: LocalMessage,
    previous: LocalMessage | undefined,
): boolean {
    if (!previous || previous.deleted || message.parent) {
        return false;
    }

    if (previous.author?.uuid !== message.author?.uuid) {
        return false;
    }

    const gap =
        new Date(message.created_at ?? 0).getTime() -
        new Date(previous.created_at ?? 0).getTime();

    return gap >= 0 && gap < 5 * 60 * 1000;
}
