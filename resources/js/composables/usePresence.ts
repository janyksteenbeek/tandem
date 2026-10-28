import { echo } from '@laravel/echo-vue';
import { onBeforeUnmount, ref, watch, type Ref } from 'vue';
import type { UserSummary } from '@/types';

type PresenceMember = UserSummary;

type TypingPayload = { user: UserSummary; mode: 'reply' | 'comment'; at: number };

/**
 * Presence + typing/collision detection on a conversation. Typing is sent as client
 * whispers so it never touches the server.
 */
export function usePresence(conversationUuid: Ref<string | null | undefined>, me: Ref<UserSummary>) {
    const viewers = ref<PresenceMember[]>([]);
    const typing = ref<TypingPayload[]>([]);
    let channelName: string | null = null;
    let sweep: ReturnType<typeof setInterval> | null = null;

    const leave = () => {
        if (channelName) {
            echo().leave(channelName);
            channelName = null;
        }

        viewers.value = [];
        typing.value = [];
    };

    const join = (uuid: string) => {
        leave();
        channelName = `presence.conversation.${uuid}`;

        echo()
            .join(channelName)
            .here((members: PresenceMember[]) => (viewers.value = members))
            .joining((member: PresenceMember) => {
                if (!viewers.value.some((m) => m.id === member.id)) {
                    viewers.value = [...viewers.value, member];
                }
            })
            .leaving((member: PresenceMember) => {
                viewers.value = viewers.value.filter((m) => m.id !== member.id);
                typing.value = typing.value.filter((t) => t.user.id !== member.id);
            })
            .listenForWhisper('typing', (payload: TypingPayload) => {
                if (payload.user.id === me.value.id) {
                    return;
                }

                typing.value = [...typing.value.filter((t) => t.user.id !== payload.user.id), { ...payload, at: Date.now() }];
            })
            .listenForWhisper('stopped-typing', (payload: { userId: number }) => {
                typing.value = typing.value.filter((t) => t.user.id !== payload.userId);
            });
    };

    let lastWhisper = 0;

    const whisperTyping = (mode: 'reply' | 'comment') => {
        if (!channelName || Date.now() - lastWhisper < 1500) {
            return;
        }

        lastWhisper = Date.now();
        echo().join(channelName).whisper('typing', { user: me.value, mode, at: Date.now() });
    };

    const whisperStopped = () => {
        if (!channelName) {
            return;
        }

        lastWhisper = 0;
        echo().join(channelName).whisper('stopped-typing', { userId: me.value.id });
    };

    watch(
        conversationUuid,
        (uuid) => {
            if (uuid) {
                join(uuid);
            } else {
                leave();
            }
        },
        { immediate: true },
    );

    sweep = setInterval(() => {
        const cutoff = Date.now() - 6000;
        typing.value = typing.value.filter((t) => t.at > cutoff);
    }, 2000);

    onBeforeUnmount(() => {
        leave();

        if (sweep) {
            clearInterval(sweep);
        }
    });

    return { viewers, typing, whisperTyping, whisperStopped };
}
