import { usePage } from '@inertiajs/vue3';
import { useConnectionStatus, useEcho } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from '@/lib/http';
import type { MeetEndpoints } from '@/lib/meet/endpoints';
import type {
    Call,
    CallMe,
    CallParticipant,
    CallRecording,
    CallSettings,
    CallUpdatedEvent,
    RecordingMode,
    RoomState,
} from '@/types';

type Options = {
    endpoints: MeetEndpoints;
    callUuid: string;
    onEnded: () => void;
    onRemoved: () => void;
    /** Every `.call.updated`, after the state has been applied. */
    onCallEvent: (event: CallUpdatedEvent) => void;
    /** Called whenever realtime (re)connects or a poll runs, so chat can catch up too. */
    onSync: () => void;
};

/** How often to re-read state when realtime is healthy, and when it is not. */
const SAFETY_POLL_MS = 45_000;
const FALLBACK_POLL_MS = 4000;

/**
 * What the application server knows about the meeting: settings, who is waiting, who may do
 * what, and the state of the recording. Realtime (Reverb) pushes changes; a poll backs it up
 * so the room stays correct with realtime switched off or interrupted.
 */
export function useCallState(options: Options) {
    const { endpoints, callUuid } = options;

    const call = ref<Call | null>(null);
    const me = ref<CallMe | null>(null);
    const serverParticipants = ref<CallParticipant[]>([]);
    const recording = ref<CallRecording | null>(null);
    const initialUnread = ref(0);
    const loaded = ref(false);

    const realtime = useConnectionStatus();
    // Without a Reverb key Echo runs on its "null" broadcaster, which always claims to be
    // connected while delivering nothing: that counts as no realtime at all.
    const realtimeConfigured = Boolean(usePage().props.reverb?.key);
    const realtimeHealthy = computed(
        () => realtimeConfigured && realtime.value === 'connected',
    );

    const waiting = computed(() =>
        me.value?.can.manage
            ? serverParticipants.value.filter((p) => p.status === 'waiting')
            : [],
    );

    const settings = computed<CallSettings | null>(
        () => call.value?.settings ?? null,
    );

    let timer: ReturnType<typeof setTimeout> | undefined;
    let stopped = false;

    const apply = (state: RoomState) => {
        call.value = state.call;
        me.value = state.me;
        serverParticipants.value = state.participants;
        recording.value = state.recording;

        if (!loaded.value) {
            initialUnread.value = state.unread_count;
        }

        loaded.value = true;

        if (state.call.status === 'ended') {
            options.onEnded();
        }
    };

    const refresh = async (): Promise<void> => {
        try {
            apply(await api.get<RoomState>(endpoints.state()));
        } catch (error) {
            const status = (error as { status?: number }).status;

            // 403: no longer inside this call (removed, or the session ended).
            if (status === 403) {
                options.onRemoved();
            }
        }
    };

    const schedule = () => {
        clearTimeout(timer);

        if (stopped) {
            return;
        }

        timer = setTimeout(
            async () => {
                // No point polling in a hidden tab while realtime is delivering.
                if (!(document.hidden && realtimeHealthy.value)) {
                    await refresh();
                    options.onSync();
                }

                schedule();
            },
            realtimeHealthy.value ? SAFETY_POLL_MS : FALLBACK_POLL_MS,
        );
    };

    const sync = async (): Promise<void> => {
        await refresh();
        options.onSync();
        schedule();
    };

    // --- Realtime ------------------------------------------------------------------------

    useEcho<CallUpdatedEvent>(`call.${callUuid}`, '.call.updated', (event) => {
        call.value = event.call;
        recording.value = event.recording;

        // Settings decide what each person may do; abilities are computed per participant
        // on the server, so re-read rather than guess.
        if (
            event.reason === 'settings' ||
            event.reason.startsWith('recording.')
        ) {
            void refresh();
        }

        options.onCallEvent(event);

        if (event.reason === 'ended' || event.call.status === 'ended') {
            options.onEnded();
        }
    });

    useEcho<{ participant: CallParticipant }>(
        `call.${callUuid}`,
        '.participant.updated',
        ({ participant }) => {
            const index = serverParticipants.value.findIndex(
                (p) => p.uuid === participant.uuid,
            );

            serverParticipants.value =
                index === -1
                    ? [...serverParticipants.value, participant]
                    : serverParticipants.value.map((p, i) =>
                          i === index ? participant : p,
                      );

            if (
                participant.uuid === me.value?.uuid &&
                participant.status === 'removed'
            ) {
                options.onRemoved();
            }
        },
    );

    watch(realtimeHealthy, (healthy, wasHealthy) => {
        // Anything broadcast while the socket was down was missed: catch up.
        if (healthy && wasHealthy === false) {
            void sync();
        } else {
            schedule();
        }
    });

    const handleVisibility = () => {
        if (!document.hidden) {
            void sync();
        }
    };

    onMounted(() => {
        void sync();
        document.addEventListener('visibilitychange', handleVisibility);
    });

    onBeforeUnmount(() => {
        stopped = true;
        clearTimeout(timer);
        document.removeEventListener('visibilitychange', handleVisibility);
    });

    // --- Host controls -------------------------------------------------------------------

    const replaceParticipant = (participant: CallParticipant) => {
        serverParticipants.value = serverParticipants.value.map((p) =>
            p.uuid === participant.uuid ? participant : p,
        );
    };

    const admit = async (uuid: string): Promise<void> => {
        const { participant } = await api.post<{
            participant: CallParticipant;
        }>(endpoints.admit(uuid));

        replaceParticipant(participant);
    };

    const deny = async (uuid: string): Promise<void> => {
        const { participant } = await api.post<{
            participant: CallParticipant;
        }>(endpoints.deny(uuid));

        replaceParticipant(participant);
    };

    const remove = async (uuid: string): Promise<void> => {
        const { participant } = await api.post<{
            participant: CallParticipant;
        }>(endpoints.remove(uuid));

        replaceParticipant(participant);
    };

    const mute = async (uuid: string): Promise<boolean> =>
        (await api.post<{ muted: boolean }>(endpoints.mute(uuid))).muted;

    const updateSettings = async (
        changes: Partial<CallSettings> & {
            title?: string | null;
            password?: string | null;
        },
    ): Promise<void> => {
        const response = await api.patch<{ call: Call }>(
            endpoints.settings(),
            changes,
        );

        call.value = response.call;
        await refresh();
    };

    const endForEveryone = async (): Promise<void> => {
        await api.post(endpoints.end());
    };

    const invite = async (
        emails: string[],
        note: string | null,
    ): Promise<number> =>
        (
            await api.post<{ invited: number }>(endpoints.invitations(), {
                emails,
                note: note || undefined,
            })
        ).invited;

    // --- Recording -----------------------------------------------------------------------

    const startRecording = async (mode: RecordingMode): Promise<void> => {
        const response = await api.post<{ recording: CallRecording }>(
            endpoints.startRecording(),
            { mode },
        );

        recording.value = response.recording;
        await refresh();
    };

    const stopRecording = async (): Promise<void> => {
        if (!recording.value) {
            return;
        }

        const response = await api.post<{ recording: CallRecording }>(
            endpoints.stopRecording(recording.value.uuid),
        );

        recording.value = response.recording;
    };

    const consentToRecording = async (): Promise<void> => {
        const response = await api.post<{
            recording_consent_at: string | null;
        }>(endpoints.recordingConsent());

        if (me.value) {
            me.value = {
                ...me.value,
                recording_consent_at: response.recording_consent_at,
            };
        }
    };

    return {
        call,
        me,
        settings,
        serverParticipants,
        waiting,
        recording,
        initialUnread,
        loaded,
        realtimeHealthy,
        refresh,
        admit,
        deny,
        remove,
        mute,
        updateSettings,
        endForEveryone,
        invite,
        startRecording,
        stopRecording,
        consentToRecording,
    };
}

export type CallState = ReturnType<typeof useCallState>;
