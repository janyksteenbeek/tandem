import { useEcho } from '@laravel/echo-vue';
import type { Ref } from 'vue';
import { computed, ref } from 'vue';
import { api, HttpError } from '@/lib/http';
import type { MeetEndpoints } from '@/lib/meet/endpoints';
import {
    countUnread,
    firstConfirmedId,
    lastConfirmedId,
    markFailed,
    markPending,
    mergeMessages,
    newClientId,
    removeLocal,
    upsertMessage,
} from '@/lib/meet/messages';
import type { LocalMessage, PendingUpload } from '@/lib/meet/types';
import { uploadFile } from '@/lib/meet/upload';
import type { CallFile, CallMe, CallMessage } from '@/types';

type Options = {
    endpoints: MeetEndpoints;
    callUuid: string;
    me: Ref<CallMe | null>;
    /** Whether the chat is on screen and scrolled to the newest message. */
    isReading: () => boolean;
    onIncoming: (message: LocalMessage) => void;
};

type MessageResponse = { message: CallMessage };
type IndexResponse = { messages: CallMessage[]; has_more: boolean };

/**
 * Meeting chat. The server is the source of truth: a message typed here is shown at once
 * (optimistic), confirmed by the HTTP response, and may also arrive over the realtime
 * channel; all three are reconciled into one entry. After a network drop the list catches
 * up by asking for everything after the last confirmed id.
 */
export function useChat(options: Options) {
    const { endpoints, callUuid, me } = options;

    const messages = ref<LocalMessage[]>([]);
    const loaded = ref(false);
    const loading = ref(false);
    const loadingOlder = ref(false);
    const hasOlder = ref(false);
    const lastReadId = ref(0);
    const uploads = ref<UploadEntry[]>([]);
    const replyingTo = ref<LocalMessage | null>(null);
    const editing = ref<LocalMessage | null>(null);

    /** Files kept per optimistic message so a failed send can be retried with them. */
    const outbox = new Map<
        string,
        { body: string | null; parent: string | null; files: CallFile[] }
    >();

    const unread = computed(() =>
        countUnread(messages.value, lastReadId.value, me.value?.uuid ?? null),
    );

    const seedReadMarker = (id: number | null) => {
        lastReadId.value = Math.max(lastReadId.value, id ?? 0);
    };

    // --- Loading -------------------------------------------------------------------------

    const load = async (): Promise<void> => {
        if (loading.value) {
            return;
        }

        loading.value = true;

        try {
            const response = await api.get<IndexResponse>(endpoints.messages());

            messages.value = mergeMessages(messages.value, response.messages);
            hasOlder.value = response.has_more;
            loaded.value = true;
        } catch {
            // Stays unloaded; the next sync tries again.
        } finally {
            loading.value = false;
        }
    };

    const loadOlder = async (): Promise<void> => {
        const before = firstConfirmedId(messages.value);

        if (loadingOlder.value || !hasOlder.value || before === null) {
            return;
        }

        loadingOlder.value = true;

        try {
            const response = await api.get<IndexResponse>(
                endpoints.messages(),
                { before },
            );

            messages.value = mergeMessages(messages.value, response.messages);
            hasOlder.value = response.has_more;
        } finally {
            loadingOlder.value = false;
        }
    };

    /** Fetch whatever was missed while realtime was down. */
    const catchUp = async (): Promise<void> => {
        if (!loaded.value) {
            return load();
        }

        try {
            const response = await api.get<IndexResponse>(
                endpoints.messages(),
                {
                    after: lastConfirmedId(messages.value),
                },
            );

            const known = new Set(
                messages.value.map((message) => message.uuid),
            );

            messages.value = mergeMessages(messages.value, response.messages);

            response.messages
                .filter((message) => !known.has(message.uuid))
                .forEach((message) => announce(message));
        } catch {
            // Offline; the next sync retries.
        }
    };

    const announce = (message: CallMessage) => {
        if (message.author?.uuid === me.value?.uuid || message.deleted) {
            return;
        }

        const entry = messages.value.find((m) => m.uuid === message.uuid);

        if (entry) {
            options.onIncoming(entry);
        }

        if (options.isReading()) {
            markRead();
        }
    };

    // --- Realtime ------------------------------------------------------------------------

    useEcho<MessageResponse>(
        `call.${callUuid}`,
        '.message.created',
        ({ message }) => {
            const isNew = !messages.value.some((m) => m.uuid === message.uuid);

            messages.value = upsertMessage(messages.value, message);

            if (isNew) {
                announce(message);
            }
        },
    );

    useEcho<MessageResponse>(
        `call.${callUuid}`,
        ['.message.updated', '.message.deleted'],
        ({ message }) => {
            // An edit to a message that is not loaded (older history) needs no entry.
            if (messages.value.some((m) => m.uuid === message.uuid)) {
                messages.value = upsertMessage(messages.value, message);
            }

            if (editing.value?.uuid === message.uuid && message.deleted) {
                editing.value = null;
            }
        },
    );

    // --- Reading -------------------------------------------------------------------------

    let readTimer: ReturnType<typeof setTimeout> | undefined;

    const markRead = () => {
        const newest = lastConfirmedId(messages.value);

        if (newest <= lastReadId.value) {
            return;
        }

        lastReadId.value = newest;

        clearTimeout(readTimer);
        readTimer = setTimeout(() => {
            void api
                .post(endpoints.readMessages(), { message_id: newest })
                .catch(() => {});
        }, 600);
    };

    // --- Sending -------------------------------------------------------------------------

    const deliver = async (clientId: string): Promise<void> => {
        const draft = outbox.get(clientId);

        if (!draft) {
            return;
        }

        try {
            const response = await api.post<MessageResponse>(
                endpoints.storeMessage(),
                {
                    body: draft.body ?? undefined,
                    client_id: clientId,
                    parent_uuid: draft.parent ?? undefined,
                    files: draft.files.map((file) => file.uuid),
                },
            );

            messages.value = upsertMessage(messages.value, response.message);
            lastReadId.value = Math.max(lastReadId.value, response.message.id);
            outbox.delete(clientId);
        } catch (error) {
            const reason =
                error instanceof HttpError
                    ? (Object.values(error.errors)[0]?.[0] ?? error.message)
                    : 'Not sent. Check your connection.';

            messages.value = markFailed(messages.value, clientId, reason);
        }
    };

    const send = (body: string): void => {
        const text = body.trim();
        const files = uploads.value
            .filter((upload) => upload.state === 'done' && upload.uuid !== null)
            .map((upload) => upload.file as CallFile);

        if ((text === '' && files.length === 0) || !me.value) {
            return;
        }

        const clientId = newClientId();
        const parent = replyingTo.value;

        outbox.set(clientId, {
            body: text === '' ? null : text,
            parent: parent?.uuid ?? null,
            files,
        });

        const optimistic: LocalMessage = {
            id: -Date.now(),
            uuid: `local-${clientId}`,
            client_id: clientId,
            body: text === '' ? null : text,
            deleted: false,
            author: {
                uuid: me.value.uuid,
                name: me.value.name,
                initials: me.value.initials,
                avatar: me.value.avatar,
                is_guest: me.value.is_guest,
            },
            parent: parent
                ? {
                      uuid: parent.uuid,
                      author: parent.author?.name ?? null,
                      excerpt: parent.body?.slice(0, 120) ?? null,
                  }
                : null,
            reactions: [],
            files,
            created_at: new Date().toISOString(),
            edited_at: null,
            state: 'pending',
            error: null,
        };

        messages.value = [...messages.value, optimistic];
        replyingTo.value = null;
        uploads.value
            .filter((upload) => upload.previewUrl)
            .forEach((upload) =>
                URL.revokeObjectURL(upload.previewUrl as string),
            );
        uploads.value = uploads.value.filter(
            (upload) => upload.state !== 'done',
        );

        void deliver(clientId);
    };

    const retry = (message: LocalMessage): void => {
        if (!message.client_id) {
            return;
        }

        messages.value = markPending(messages.value, message.client_id);
        void deliver(message.client_id);
    };

    const discard = (message: LocalMessage): void => {
        if (!message.client_id) {
            return;
        }

        outbox.delete(message.client_id);
        messages.value = removeLocal(messages.value, message.client_id);
    };

    const saveEdit = async (body: string): Promise<void> => {
        const target = editing.value;

        if (!target) {
            return;
        }

        const response = await api.patch<MessageResponse>(
            endpoints.updateMessage(target.uuid),
            { body },
        );

        messages.value = upsertMessage(messages.value, response.message);
        editing.value = null;
    };

    const destroy = async (message: LocalMessage): Promise<void> => {
        await api.delete(endpoints.destroyMessage(message.uuid));

        // The realtime event carries the tombstone; apply the same thing right away.
        messages.value = messages.value.map((m) =>
            m.uuid === message.uuid
                ? { ...m, deleted: true, body: null, files: [], reactions: [] }
                : m,
        );
    };

    const react = async (
        message: LocalMessage,
        emoji: string,
    ): Promise<void> => {
        const mine = message.reactions
            .find((reaction) => reaction.emoji === emoji)
            ?.participants.some((p) => p.uuid === me.value?.uuid);

        const response = await api.post<MessageResponse>(
            endpoints.react(message.uuid),
            {
                emoji,
                on: !mine,
            },
        );

        messages.value = upsertMessage(messages.value, response.message);
    };

    // --- Files ---------------------------------------------------------------------------

    const patchUpload = (key: string, changes: Partial<UploadEntry>) => {
        uploads.value = uploads.value.map((upload) =>
            upload.key === key ? { ...upload, ...changes } : upload,
        );
    };

    const addFiles = (files: File[]): void => {
        files.forEach((file) => {
            const key = newClientId();
            const handle = uploadFile<{ file: CallFile }>(
                endpoints.storeFile(),
                file,
                (progress) => patchUpload(key, { progress }),
            );

            uploads.value = [
                ...uploads.value,
                {
                    key,
                    name: file.name,
                    size: file.size,
                    progress: 0,
                    state: 'uploading',
                    error: null,
                    uuid: null,
                    previewUrl: file.type.startsWith('image/')
                        ? URL.createObjectURL(file)
                        : null,
                    abort: handle.abort,
                    file: null,
                    source: file,
                },
            ];

            handle.promise
                .then((response) =>
                    patchUpload(key, {
                        state: 'done',
                        progress: 1,
                        uuid: response.file.uuid,
                        file: response.file,
                    }),
                )
                .catch((error: unknown) => {
                    // Removed by the person while it was still going up.
                    if (!uploads.value.some((upload) => upload.key === key)) {
                        return;
                    }

                    patchUpload(key, {
                        state: 'failed',
                        error:
                            error instanceof HttpError
                                ? (Object.values(error.errors)[0]?.[0] ??
                                  error.message)
                                : 'Upload failed.',
                    });
                });
        });
    };

    const retryUpload = (key: string): void => {
        const upload = uploads.value.find((entry) => entry.key === key);

        if (!upload?.source) {
            return;
        }

        removeUpload(key);
        addFiles([upload.source]);
    };

    const removeUpload = (key: string): void => {
        const upload = uploads.value.find((entry) => entry.key === key);

        if (!upload) {
            return;
        }

        uploads.value = uploads.value.filter((entry) => entry.key !== key);

        if (upload.previewUrl) {
            URL.revokeObjectURL(upload.previewUrl);
        }

        if (upload.state === 'uploading') {
            upload.abort();
        } else if (upload.uuid) {
            void api.delete(endpoints.destroyFile(upload.uuid)).catch(() => {});
        }
    };

    const uploading = computed(() =>
        uploads.value.some((upload) => upload.state === 'uploading'),
    );

    return {
        messages,
        loaded,
        loading,
        loadingOlder,
        hasOlder,
        unread,
        uploads,
        uploading,
        replyingTo,
        editing,
        seedReadMarker,
        load,
        loadOlder,
        catchUp,
        markRead,
        send,
        retry,
        discard,
        saveEdit,
        destroy,
        react,
        addFiles,
        retryUpload,
        removeUpload,
    };
}

/** An upload in the composer, with the server's record once it exists. */
export type UploadEntry = PendingUpload & {
    file: CallFile | null;
    source: File | null;
};

export type Chat = ReturnType<typeof useChat>;
