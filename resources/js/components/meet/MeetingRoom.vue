<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Lock, RefreshCw, TriangleAlert, VolumeX, X } from '@lucide/vue';
import { useNow, useOnline } from '@vueuse/core';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import BrandMark from '@/components/BrandMark.vue';
import ChatPanel from '@/components/meet/ChatPanel.vue';
import ControlBar from '@/components/meet/ControlBar.vue';
import InviteDialog from '@/components/meet/InviteDialog.vue';
import MeetPip from '@/components/meet/MeetPip.vue';
import MeetStage from '@/components/meet/MeetStage.vue';
import ParticipantsPanel from '@/components/meet/ParticipantsPanel.vue';
import QualityBars from '@/components/meet/QualityBars.vue';
import RecordingConsentDialog from '@/components/meet/RecordingConsentDialog.vue';
import SettingsDialog from '@/components/meet/SettingsDialog.vue';
import TrackAudio from '@/components/meet/TrackAudio.vue';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAudioLevel } from '@/composables/meet/useAudioLevel';
import { useCallState } from '@/composables/meet/useCallState';
import { useChat } from '@/composables/meet/useChat';
import { useConnectionStats } from '@/composables/meet/useConnectionStats';
import { useDevices } from '@/composables/meet/useDevices';
import { useMeetPreferences } from '@/composables/meet/useMeetPreferences';
import { provideParticipantVolumes } from '@/composables/meet/useParticipantVolumes';
import { usePictureInPicture } from '@/composables/meet/usePictureInPicture';
import type { SessionEnd } from '@/composables/meet/useRoomSession';
import { useRoomSession } from '@/composables/meet/useRoomSession';
import { useKeyboard } from '@/composables/useKeyboard';
import { reportError } from '@/lib/http';
import { meetEndpoints } from '@/lib/meet/endpoints';
import { formatDuration } from '@/lib/meet/format';
import { orderParticipants } from '@/lib/meet/layout';
import { pipParticipants, pipScreen, pipVideoElement } from '@/lib/meet/pip';
import { resolveDevice } from '@/lib/meet/preferences';
import {
    QUALITY_LABEL,
    qualityFromStats,
    worstQuality,
} from '@/lib/meet/quality';
import type { RoomParticipant } from '@/lib/meet/types';
import type { CallUpdatedEvent, RecordingMode } from '@/types';

/**
 * The meeting. Ties together the three things that make one: the media session (who is
 * heard and seen), the call's state on the server (rules, waiting room, recording) and the
 * chat. Everything visible hangs off those.
 */
const props = defineProps<{
    code: string;
    callUuid: string;
    title: string;
    historyUrl: string | null;
    callsUrl: string | null;
}>();

const emit = defineEmits<{ end: [end: SessionEnd] }>();

const endpoints = meetEndpoints(props.code);
const preferences = useMeetPreferences();
const volumes = provideParticipantVolumes(props.callUuid);

const panel = ref<'chat' | 'people' | null>(null);
const chatAtBottom = ref(true);
const settingsOpen = ref(false);
const settingsTab = ref('audio');
const inviteOpen = ref(false);
const selectedScreenId = ref<string | null>(null);
const pinnedIdentity = ref<string | null>(null);
const stage = ref<InstanceType<typeof MeetStage> | null>(null);
let finished = false;

const finish = (end: SessionEnd) => {
    if (finished) {
        return;
    }

    finished = true;
    void session.disconnect(end.kind === 'left');
    emit('end', end);
};

// --- Media -------------------------------------------------------------------------------

const session = useRoomSession({
    endpoints,
    preferences,
    onEnd: finish,
    onNotice: (level, message, detail) => {
        toast[level === 'info' ? 'message' : level](message, {
            description: detail,
            duration: level === 'error' ? 9000 : 5000,
        });
    },
    onReconnected: () => {
        void state.refresh();
        void chat.catchUp();
    },
});

// --- Server state ------------------------------------------------------------------------

const state = useCallState({
    endpoints,
    callUuid: props.callUuid,
    onEnded: () => finish({ kind: 'ended' }),
    onRemoved: () => finish({ kind: 'removed' }),
    onCallEvent: (event) => handleCallEvent(event),
    onSync: () => void chat.catchUp(),
});

// --- Chat --------------------------------------------------------------------------------

const chat = useChat({
    endpoints,
    callUuid: props.callUuid,
    me: state.me,
    isReading: () =>
        panel.value === 'chat' && chatAtBottom.value && !document.hidden,
    onIncoming: (message) => {
        if (panel.value === 'chat') {
            return;
        }

        toast.message(message.author?.name ?? 'New message', {
            description:
                message.body?.slice(0, 140) ??
                (message.files.length > 0
                    ? `Shared ${message.files[0].filename}`
                    : ''),
            duration: 4500,
            action: {
                label: 'Open chat',
                onClick: () => (panel.value = 'chat'),
            },
        });
    },
});

watch(
    () => state.loaded.value,
    (loaded) => {
        if (loaded) {
            chat.seedReadMarker(state.me.value?.last_read_message_id ?? null);
            void chat.load();
        }
    },
);

// --- Devices -----------------------------------------------------------------------------

const devices = useDevices(() => {
    // A device went away: move to the system default rather than going silent.
    const microphone = resolveDevice(
        preferences.value.audioInputId,
        devices.audioInputs.value,
    );

    if (
        session.micEnabled.value &&
        microphone &&
        preferences.value.audioInputId &&
        microphone !== preferences.value.audioInputId
    ) {
        void session.switchDevice('audioinput', microphone).then((switched) => {
            if (switched) {
                toast.message('Your microphone was disconnected', {
                    description: 'Switched to the default microphone.',
                });
            }
        });
    }

    const camera = resolveDevice(
        preferences.value.videoInputId,
        devices.videoInputs.value,
    );

    if (
        session.cameraEnabled.value &&
        camera &&
        preferences.value.videoInputId &&
        camera !== preferences.value.videoInputId
    ) {
        void session.switchDevice('videoinput', camera);
    }
});

const micLevel = useAudioLevel(session.localMicTrack);

// --- Connection health -------------------------------------------------------------------

const connected = computed(() => session.phase.value === 'connected');
const { stats } = useConnectionStats(session.rawRoom, connected);

const online = useOnline();
/** Say so the moment the browser knows the network is gone, before the media stack does. */
const reconnecting = computed(
    () =>
        session.phase.value === 'reconnecting' ||
        (!online.value && connected.value),
);

const quality = computed(() =>
    reconnecting.value
        ? 'lost'
        : worstQuality(
              session.localQuality.value,
              qualityFromStats(stats.value),
          ),
);

let poorSince = 0;
let suggestedAt = 0;

watch(quality, (level) => {
    if (level !== 'poor') {
        poorSince = 0;

        return;
    }

    poorSince ||= Date.now();
});

/** After a stretch of poor quality, offer the audio-only fallback once in a while. */
const considerAudioOnly = () => {
    if (
        poorSince === 0 ||
        session.audioOnly.value ||
        Date.now() - poorSince < 8000 ||
        Date.now() - suggestedAt < 120_000
    ) {
        return;
    }

    suggestedAt = Date.now();

    toast.warning('Your connection is struggling', {
        description: 'Switch to audio only to keep the conversation clear.',
        duration: 15_000,
        action: {
            label: 'Audio only',
            onClick: () => void session.setAudioOnly(true),
        },
    });
};

// --- Ordering and layout -----------------------------------------------------------------

const order = ref<string[]>([]);

const ordered = computed<RoomParticipant[]>(() =>
    orderParticipants(
        session.participants.value,
        order.value,
        session.lastSpokeAt.value,
        6,
    ),
);

watch(ordered, (list) => {
    const next = list.map((p) => p.identity);

    if (next.join() !== order.value.join()) {
        order.value = next;
    }
});

watch(
    () => session.participants.value.map((p) => p.identity),
    (identities) => {
        if (
            pinnedIdentity.value &&
            !identities.includes(pinnedIdentity.value)
        ) {
            pinnedIdentity.value = null;
        }
    },
);

const remoteAudio = computed(() =>
    session.remotes.value.flatMap((participant) =>
        participant.audioTracks.map((track) => ({
            key: `${participant.identity}:${track.sid ?? track.source}`,
            track,
            // One level per person: their voice and the sound of a screen they share.
            volume: volumes.volumeOf(participant.identity),
        })),
    ),
);

// --- Picture-in-picture ------------------------------------------------------------------

const pipStageScreen = computed(() =>
    pipScreen(session.screens.value, selectedScreenId.value),
);
const pipPeople = computed(() =>
    pipParticipants(ordered.value, session.lastSpokeAt.value),
);
const pipHidden = computed(() =>
    Math.max(0, session.remotes.value.length - pipPeople.value.length),
);

const pip = usePictureInPicture({
    title: () => displayTitle.value,
    video: () => pipVideoElement(pipStageScreen.value, pipPeople.value),
    onUnavailable: (message) => toast.message(message),
});

// --- Recording ---------------------------------------------------------------------------

const recording = computed(() => state.recording.value);
const recordingActive = computed(() => recording.value?.is_active ?? false);
const needsConsent = computed(
    () =>
        recordingActive.value &&
        state.me.value !== null &&
        state.me.value.recording_consent_at === null,
);
const recordingWarning = ref<string | null>(null);

const now = useNow({ interval: 1000 });

const recordingElapsed = computed(() => {
    const started = recording.value?.started_at;

    return started && recordingActive.value
        ? formatDuration(
              (now.value.getTime() - new Date(started).getTime()) / 1000,
          )
        : null;
});

const elapsed = computed(() => {
    const started = state.call.value?.started_at;

    return started
        ? formatDuration(
              (now.value.getTime() - new Date(started).getTime()) / 1000,
          )
        : null;
});

let wasLocked = false;

watch(
    () => state.loaded.value,
    () => (wasLocked = state.settings.value?.locked ?? false),
);

const handleCallEvent = (event: CallUpdatedEvent) => {
    const isOperator = state.me.value?.can.record ?? false;

    switch (event.reason) {
        case 'recording.starting':
            recordingWarning.value = null;
            break;
        case 'recording.stopped':
            recordingWarning.value = null;
            toast.message('Recording stopped', {
                description: event.by ? `Stopped by ${event.by}.` : undefined,
            });
            break;
        case 'recording.warning':
            // A recording must never go wrong quietly: say so, and keep saying so.
            recordingWarning.value =
                event.message ?? 'The recorder reported a problem.';

            if (isOperator) {
                toast.warning('Recording problem', {
                    description: recordingWarning.value,
                    duration: 20_000,
                });
            }

            break;
        case 'recording.failed':
            recordingWarning.value = null;
            toast.error('Recording failed', {
                description:
                    event.message ??
                    'The recording could not continue. Anything captured until now is kept.',
                duration: Infinity,
            });
            break;
        case 'recording.finished':
            recordingWarning.value = null;

            if (props.historyUrl) {
                toast.success('Recording saved', {
                    description: 'It is in the meeting history.',
                    duration: 10_000,
                    action: {
                        label: 'Open',
                        onClick: () =>
                            window.open(props.historyUrl as string, '_blank'),
                    },
                });
            }

            break;
        case 'settings':
            if (event.call.settings.locked !== wasLocked) {
                wasLocked = event.call.settings.locked;

                toast.message(
                    wasLocked
                        ? 'The meeting is locked'
                        : 'The meeting is unlocked',
                    {
                        description: wasLocked
                            ? 'Nobody else can join.'
                            : 'People with the link can join again.',
                    },
                );
            }

            break;
    }
};

watch(
    () => state.me.value?.can.share_screen,
    (allowed, was) => {
        if (was && allowed === false && session.screenSharing.value) {
            void session.setScreenShare(false);
            toast.message('A host limited screen sharing to hosts', {
                description: 'Your screen is no longer shared.',
            });
        }
    },
);

const startRecording = async (mode: RecordingMode) => {
    try {
        await state.startRecording(mode);
        toast.success('Recording is starting', {
            description: 'Everyone in the meeting is being told.',
        });
    } catch (error) {
        reportError(error, 'The recording could not be started.');
    }
};

const stopRecording = async () => {
    try {
        await state.stopRecording();
    } catch (error) {
        reportError(error, 'The recording could not be stopped.');
    }
};

const consent = async () => {
    try {
        await state.consentToRecording();
    } catch (error) {
        reportError(error);
    }
};

// --- Host actions ------------------------------------------------------------------------

const act = async (action: () => Promise<unknown>, failure: string) => {
    try {
        await action();
    } catch (error) {
        reportError(error, failure);
    }
};

const muteParticipant = (participant: RoomParticipant) =>
    act(async () => {
        const muted = await state.mute(participant.meta.uuid);

        toast.message(
            muted
                ? `${participant.name} was muted`
                : `${participant.name} is already muted`,
        );
    }, 'That person could not be muted.');

const removeParticipant = (participant: RoomParticipant) =>
    act(async () => {
        await state.remove(participant.meta.uuid);
        toast.message(`${participant.name} was removed`);
    }, 'That person could not be removed.');

/** One toast per person in the waiting room, taken away again once they are answered. */
const waitingToasts = new Map<string, string | number>();

watch(
    () => state.waiting.value,
    (waiting) => {
        const present = new Set(waiting.map((person) => person.uuid));

        waitingToasts.forEach((id, uuid) => {
            if (!present.has(uuid)) {
                toast.dismiss(id);
                waitingToasts.delete(uuid);
            }
        });

        waiting.forEach((person) => {
            if (waitingToasts.has(person.uuid)) {
                return;
            }

            waitingToasts.set(
                person.uuid,
                panel.value === 'people'
                    ? ''
                    : toast.message(`${person.name} wants to join`, {
                          duration: 30_000,
                          action: {
                              label: 'Admit',
                              onClick: () =>
                                  void act(
                                      () => state.admit(person.uuid),
                                      'They could not be admitted.',
                                  ),
                          },
                      }),
            );
        });
    },
);

// --- Invitations -------------------------------------------------------------------------

const joinUrl = computed(
    () =>
        state.call.value?.join_url ??
        (typeof window !== 'undefined'
            ? window.location.href.split('?')[0]
            : ''),
);

const copyLink = async () => {
    try {
        await navigator.clipboard.writeText(joinUrl.value);
        toast.success('Meeting link copied');
    } catch {
        toast.error('The link could not be copied', {
            description: joinUrl.value,
        });
    }
};

const invitationsSent = (count: number) =>
    toast.success(
        count === 1 ? 'Invitation sent' : `${count} invitations sent`,
    );

// --- Leaving -----------------------------------------------------------------------------

const leave = () => finish({ kind: 'left' });

const endForEveryone = () =>
    act(async () => {
        await state.endForEveryone();
        finish({ kind: 'ended' });
    }, 'The meeting could not be ended.');

// --- Panels and shortcuts ----------------------------------------------------------------

const togglePanel = (name: 'chat' | 'people') => {
    panel.value = panel.value === name ? null : name;
};

const openSettings = (tab: string) => {
    settingsTab.value = tab;
    settingsOpen.value = true;
};

const toggleFullscreen = () => {
    if (document.fullscreenElement) {
        void document.exitFullscreen();
    } else {
        void document.documentElement.requestFullscreen?.().catch(() => {});
    }
};

const shortcuts = computed(() => [
    {
        keys: 'm',
        description: 'Mute or unmute your microphone',
        handler: () => void session.setMicrophone(!session.micEnabled.value),
    },
    {
        keys: 'v',
        description: 'Turn your camera on or off',
        handler: () => void session.setCamera(!session.cameraEnabled.value),
    },
    {
        keys: 's',
        description: 'Share your screen',
        handler: () => {
            if (state.me.value?.can.share_screen) {
                void session.setScreenShare(!session.screenSharing.value);
            }
        },
    },
    {
        keys: 'c',
        description: 'Open or close chat',
        handler: () => togglePanel('chat'),
    },
    {
        keys: 'p',
        description: 'Open or close people',
        handler: () => togglePanel('people'),
    },
    { keys: 'f', description: 'Full screen', handler: toggleFullscreen },
    ...(pip.support.value
        ? [
              {
                  keys: 'shift+p',
                  description: 'Picture-in-picture',
                  handler: () => void pip.toggle(),
              },
          ]
        : []),
    {
        keys: 'i',
        description: 'Copy the meeting link',
        handler: () => void copyLink(),
    },
    {
        keys: ',',
        description: 'Settings',
        handler: () => openSettings('audio'),
    },
    {
        keys: 'shift+/',
        description: 'Show keyboard shortcuts',
        handler: () => openSettings('shortcuts'),
    },
    { keys: 'shift+l', description: 'Leave the meeting', handler: leave },
]);

useKeyboard(
    () =>
        shortcuts.value.map((shortcut) => ({ ...shortcut, group: 'Meeting' })),
    {
        scope: 'Meeting',
    },
);

const shortcutList = computed(() =>
    shortcuts.value.map(({ keys, description }) => ({
        keys: keys
            .replace('shift+/', '?')
            .replace('shift+l', 'Shift L')
            .replace('shift+p', 'Shift P'),
        description,
    })),
);

// --- Lifecycle ---------------------------------------------------------------------------

let healthTimer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    void session.connect();
    healthTimer = setInterval(considerAudioOnly, 2000);
});

onBeforeUnmount(() => clearInterval(healthTimer));

// Leaving the page through the app's own navigation should not strand the participant.
const stopNavigationGuard = router.on('before', () => {
    if (!finished) {
        finished = true;
        void session.disconnect(true);
    }
});

onBeforeUnmount(stopNavigationGuard);

const peopleCount = computed(() => session.participants.value.length);
const displayTitle = computed(
    () => state.call.value?.display_title ?? props.title,
);
</script>

<template>
    <div
        class="relative flex min-h-0 flex-1 flex-col bg-surface-sunken"
        data-testid="meeting-room"
        :data-phase="session.phase.value"
    >
        <!-- Top bar ------------------------------------------------------------------- -->
        <header class="flex h-12 shrink-0 items-center gap-3 px-3 sm:px-4">
            <BrandMark :size="22" />
            <h1 class="min-w-0 truncate text-sm font-semibold tracking-tight">
                {{ displayTitle }}
            </h1>
            <span
                v-if="elapsed"
                class="hidden text-xs text-muted-foreground tabular-nums sm:inline"
                aria-label="Meeting duration"
                >{{ elapsed }}</span
            >
            <Tooltip v-if="state.settings.value?.locked">
                <TooltipTrigger as-child>
                    <span
                        class="flex items-center text-muted-foreground"
                        aria-label="Meeting locked"
                    >
                        <Lock class="size-3.5" />
                    </span>
                </TooltipTrigger>
                <TooltipContent>Locked: nobody else can join</TooltipContent>
            </Tooltip>

            <!-- Recording: impossible to miss, for everyone -->
            <span
                v-if="recordingActive"
                class="flex items-center gap-1.5 rounded-full bg-destructive px-2.5 py-1 text-[11px] font-bold tracking-wide text-white uppercase shadow-[0_2px_10px_-2px] shadow-destructive/60"
                role="status"
                data-testid="recording-indicator"
            >
                <span class="size-1.5 animate-pulse rounded-full bg-white" />
                Rec
                <span
                    v-if="recordingElapsed"
                    class="font-medium tabular-nums"
                    >{{ recordingElapsed }}</span
                >
            </span>
            <span
                v-else-if="recording?.status === 'processing'"
                class="flex items-center gap-1.5 rounded-full bg-foreground/[0.07] px-2.5 py-1 text-[11px] font-medium text-muted-foreground"
                role="status"
            >
                <Spinner class="size-3" /> Saving recording
            </span>

            <div class="ml-auto flex items-center gap-2">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium text-muted-foreground outline-none hover:bg-foreground/[0.06] hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            :aria-label="`${QUALITY_LABEL[quality]}. Open connection details`"
                            data-testid="quality-indicator"
                            :data-quality="quality"
                            @click="openSettings('connection')"
                        >
                            <QualityBars :level="quality" />
                            <span class="hidden md:inline">{{
                                QUALITY_LABEL[quality]
                            }}</span>
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>Connection details</TooltipContent>
                </Tooltip>
                <span
                    class="hidden rounded-full border bg-card px-2.5 py-1 font-mono text-[11px] text-muted-foreground sm:inline"
                    >{{ code }}</span
                >
            </div>
        </header>

        <!-- Banners ------------------------------------------------------------------- -->
        <div class="flex flex-col gap-1.5 px-3 empty:hidden sm:px-4">
            <div
                v-if="reconnecting"
                class="flex items-center justify-center gap-2 rounded-xl border border-warning/40 bg-warning/15 px-4 py-2 text-[13px] font-medium"
                role="status"
                data-testid="reconnecting"
            >
                <RefreshCw class="size-3.5 animate-spin" />
                Connection lost. Reconnecting you automatically…
            </div>
            <div
                v-if="!session.canPlayAudio.value && connected"
                class="flex items-center justify-center gap-3 rounded-xl border bg-card px-4 py-2 text-[13px]"
                role="alert"
            >
                <VolumeX class="size-4 text-destructive" />
                Your browser is blocking sound until you allow it.
                <button
                    type="button"
                    class="rounded-lg bg-brand px-2.5 py-1 text-xs font-semibold text-brand-foreground"
                    @click="session.startAudio()"
                >
                    Turn on sound
                </button>
            </div>
            <div
                v-if="recordingWarning && recordingActive"
                class="flex items-center gap-2 rounded-xl border border-warning/40 bg-warning/15 px-4 py-2 text-[13px]"
                role="alert"
                data-testid="recording-warning"
            >
                <TriangleAlert
                    class="size-4 shrink-0 text-warning-foreground dark:text-warning"
                />
                <span class="min-w-0 flex-1">
                    <span class="font-semibold">Recording problem.</span>
                    {{ recordingWarning }}
                </span>
                <button
                    type="button"
                    aria-label="Dismiss"
                    class="rounded p-0.5 text-muted-foreground hover:text-foreground"
                    @click="recordingWarning = null"
                >
                    <X class="size-3.5" />
                </button>
            </div>
        </div>

        <!-- Stage and panels ----------------------------------------------------------- -->
        <div class="relative flex min-h-0 flex-1 gap-3 px-3 pt-1 pb-2 sm:px-4">
            <div
                v-if="
                    session.phase.value === 'connecting' ||
                    session.participants.value.length === 0
                "
                class="flex flex-1 flex-col items-center justify-center gap-3 text-muted-foreground"
                role="status"
            >
                <Spinner class="size-6" />
                <p class="text-sm">
                    {{
                        session.phase.value === 'reconnecting'
                            ? 'Reconnecting…'
                            : 'Connecting you to the meeting…'
                    }}
                </p>
            </div>

            <MeetStage
                v-else
                ref="stage"
                v-model:selected-screen-id="selectedScreenId"
                v-model:pinned-identity="pinnedIdentity"
                :participants="ordered"
                :screens="session.screens.value"
                :audio-only="session.audioOnly.value"
                @stop-sharing="session.setScreenShare(false)"
            />

            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="translate-x-4 opacity-0"
                leave-active-class="transition duration-150 ease-in"
                leave-to-class="translate-x-4 opacity-0"
                mode="out-in"
            >
                <ChatPanel
                    v-if="panel === 'chat'"
                    :chat="chat"
                    :me="state.me.value"
                    @close="panel = null"
                    @reading="(value) => (chatAtBottom = value)"
                />
                <ParticipantsPanel
                    v-else-if="panel === 'people'"
                    :participants="ordered"
                    :waiting="state.waiting.value"
                    :me="state.me.value"
                    @close="panel = null"
                    @admit="
                        (uuid) =>
                            act(
                                () => state.admit(uuid),
                                'They could not be admitted.',
                            )
                    "
                    @deny="
                        (uuid) =>
                            act(
                                () => state.deny(uuid),
                                'That request could not be declined.',
                            )
                    "
                    @mute="muteParticipant"
                    @remove="removeParticipant"
                    @copy-link="copyLink"
                    @invite="inviteOpen = true"
                />
            </Transition>
        </div>

        <ControlBar
            :session="session"
            :state="state"
            :mic-level="micLevel"
            :chat-open="panel === 'chat'"
            :people-open="panel === 'people'"
            :unread="chat.unread.value"
            :audio-inputs="devices.audioInputs.value"
            :video-inputs="devices.videoInputs.value"
            :audio-outputs="devices.audioOutputs.value"
            :can-select-output="devices.canSelectOutput.value"
            :preferred-audio-input="preferences.audioInputId"
            :preferred-video-input="preferences.videoInputId"
            :preferred-audio-output="preferences.audioOutputId"
            :pip-supported="pip.support.value !== null"
            :pip-active="pip.active.value"
            @toggle-chat="togglePanel('chat')"
            @toggle-people="togglePanel('people')"
            @settings="openSettings"
            @leave="leave"
            @end="endForEveryone"
            @copy-link="copyLink"
            @invite="inviteOpen = true"
            @record="startRecording"
            @stop-recording="stopRecording"
            @fullscreen="toggleFullscreen"
            @pip="pip.toggle()"
        />

        <!-- The meeting in a floating window, while this tab is in the background -->
        <Teleport v-if="pip.target.value" :to="pip.target.value">
            <MeetPip
                :participants="pipPeople"
                :hidden="pipHidden"
                :screen="pipStageScreen"
                :mic-enabled="session.micEnabled.value"
                :mic-busy="session.micBusy.value"
                :camera-enabled="session.cameraEnabled.value"
                :audio-only="session.audioOnly.value"
                @toggle-mic="session.setMicrophone(!session.micEnabled.value)"
                @toggle-camera="session.setCamera(!session.cameraEnabled.value)"
                @leave="leave"
            />
        </Teleport>

        <!-- Everyone else's sound -->
        <TrackAudio
            v-for="audio in remoteAudio"
            :key="audio.key"
            :track="audio.track"
            :volume="audio.volume"
        />

        <SettingsDialog
            v-model:open="settingsOpen"
            v-model:tab="settingsTab"
            :session="session"
            :state="state"
            :stats="stats"
            :quality="quality"
            :mic-level="micLevel"
            :audio-inputs="devices.audioInputs.value"
            :video-inputs="devices.videoInputs.value"
            :audio-outputs="devices.audioOutputs.value"
            :can-select-output="devices.canSelectOutput.value"
            :shortcuts="shortcutList"
        />

        <InviteDialog
            v-model:open="inviteOpen"
            :join-url="joinUrl"
            :can-email="state.me.value?.can.invite ?? false"
            :send="state.invite"
            @copy="copyLink"
            @sent="invitationsSent"
        />

        <RecordingConsentDialog
            :open="needsConsent"
            :started-by="recording?.started_by ?? null"
            @stay="consent"
            @leave="leave"
        />

        <span class="sr-only" aria-live="polite"
            >{{ peopleCount }} people in the meeting</span
        >
    </div>
</template>
