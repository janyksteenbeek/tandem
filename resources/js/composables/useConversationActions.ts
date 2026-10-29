import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { api, reportError } from '@/lib/http';
import type {
    Conversation,
    ConversationListItem,
    ConversationStatus,
} from '@/types';

type Updater = (conversation: Conversation) => void;
type ConversationResponse = { conversation: Conversation };
type ListUpdater = (items: ConversationListItem[]) => void;

/**
 * Optimistic conversation mutations. Each call patches the local copy immediately, then
 * reconciles with the server response (or rolls back and toasts on failure).
 */
export function useConversationActions(
    onUpdated: Updater,
    onBulk?: ListUpdater,
) {
    const run = async (
        request: () => Promise<ConversationResponse>,
        optimistic?: () => () => void,
        success?: string,
    ): Promise<Conversation | null> => {
        const rollback = optimistic?.();

        try {
            const data = await request();
            onUpdated(data.conversation);

            if (success) {
                toast.success(success);
            }

            return data.conversation;
        } catch (error) {
            rollback?.();
            reportError(error);

            return null;
        }
    };

    return {
        assign: (
            uuid: string,
            assigneeId: number | null,
            optimistic?: () => () => void,
        ) =>
            run(
                () =>
                    api.post<ConversationResponse>(
                        `/conversations/${uuid}/assign`,
                        { assignee_id: assigneeId },
                    ),
                optimistic,
            ),

        setStatus: (
            uuid: string,
            status: ConversationStatus,
            snoozedUntil?: string,
            optimistic?: () => () => void,
        ) =>
            run(
                () =>
                    api.post<ConversationResponse>(
                        `/conversations/${uuid}/status`,
                        { status, snoozed_until: snoozedUntil },
                    ),
                optimistic,
                status === 'closed'
                    ? 'Marked done'
                    : status === 'snoozed'
                      ? 'Snoozed'
                      : undefined,
            ),

        setPriority: (
            uuid: string,
            priority: string,
            optimistic?: () => () => void,
        ) =>
            run(
                () =>
                    api.post<ConversationResponse>(
                        `/conversations/${uuid}/priority`,
                        { priority },
                    ),
                optimistic,
            ),

        toggleTag: (
            uuid: string,
            tagId: number | null,
            attached: boolean,
            name?: string,
            optimistic?: () => () => void,
        ) =>
            run(
                () =>
                    api.post<ConversationResponse>(
                        `/conversations/${uuid}/tags`,
                        { tag_id: tagId, name, attached },
                    ),
                optimistic,
            ),

        star: (uuid: string, starred: boolean, optimistic?: () => () => void) =>
            run(
                () =>
                    api.post<ConversationResponse>(
                        `/conversations/${uuid}/star`,
                        { starred },
                    ),
                optimistic,
            ),

        markRead: (
            uuid: string,
            read: boolean,
            optimistic?: () => () => void,
        ) =>
            run(
                () =>
                    api.post<ConversationResponse>(
                        `/conversations/${uuid}/read`,
                        { read },
                    ),
                optimistic,
            ),

        mailbox: (
            uuid: string,
            action:
                | 'archive'
                | 'inbox'
                | 'trash'
                | 'restore'
                | 'spam'
                | 'not_spam',
            optimistic?: () => () => void,
        ) =>
            run(
                () =>
                    api.post<ConversationResponse>(
                        `/conversations/${uuid}/mailbox`,
                        { action },
                    ),
                optimistic,
                {
                    archive: 'Archived',
                    inbox: 'Moved to inbox',
                    trash: 'Moved to trash',
                    restore: 'Restored',
                    spam: 'Marked as spam',
                    not_spam: 'Not spam',
                }[action],
            ),

        follow: (uuid: string, following: boolean) =>
            run(() =>
                api.post<ConversationResponse>(
                    `/conversations/${uuid}/follow`,
                    { following },
                ),
            ),

        share: (
            uuid: string,
            inbox: string,
            mode: 'share' | 'transfer',
            note?: string,
        ) =>
            run(
                () =>
                    api.post<ConversationResponse>(
                        `/conversations/${uuid}/share`,
                        { inbox, mode, note },
                    ),
                undefined,
                mode === 'share'
                    ? 'Shared with the team'
                    : 'Moved to the team inbox',
            ),

        unshare: (uuid: string, inboxUuid: string) =>
            run(() =>
                api.delete<ConversationResponse>(
                    `/conversations/${uuid}/share/${inboxUuid}`,
                ),
            ),

        destroy: async (uuid: string) => {
            try {
                await api.delete<ConversationResponse>(
                    `/conversations/${uuid}`,
                );
                toast.success('Conversation deleted');

                return true;
            } catch (error) {
                reportError(error);

                return false;
            }
        },

        bulk: async (
            uuids: string[],
            action: string,
            params: Record<string, unknown> = {},
        ) => {
            try {
                const data = await api.post<{
                    conversations: ConversationListItem[];
                }>('/conversations/bulk', {
                    conversations: uuids,
                    action,
                    ...params,
                });

                onBulk?.(data.conversations);
                toast.success(
                    `Updated ${data.conversations.length} conversation${data.conversations.length === 1 ? '' : 's'}`,
                );

                return data.conversations;
            } catch (error) {
                reportError(error);

                return [];
            }
        },

        refreshNav: () => router.reload({ only: ['mailNav', 'counts'] }),
    };
}
