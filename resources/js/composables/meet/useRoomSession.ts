import type {
    DisconnectReason,
    LocalAudioTrack,
    LocalTrack,
    LocalTrackPublication,
    LocalVideoTrack,
    Participant,
    RemoteTrackPublication,
    Room,
    Track,
} from 'livekit-client';
import type { Ref } from 'vue';
import {
    computed,
    markRaw,
    onBeforeUnmount,
    ref,
    shallowRef,
    watch,
} from 'vue';
import { api, HttpError } from '@/lib/http';
import type { CameraEffectProcessor } from '@/lib/meet/effects/processor';
import type { MeetEndpoints } from '@/lib/meet/endpoints';
import {
    audioCaptureOptions,
    cameraPublishOptions,
    microphonePublishOptions,
    roomOptions,
    screenAudioPublishOptions,
    screenCaptureLimits,
    screenShareCaptureOptions,
    screenShareEncoding,
    screenSharePublishOptions,
    videoCaptureOptions,
} from '@/lib/meet/media';
import type { MediaProblem } from '@/lib/meet/mediaErrors';
import { explainMediaError } from '@/lib/meet/mediaErrors';
import { qualityFromLiveKit } from '@/lib/meet/quality';
import type {
    ConnectionPhase,
    DevicePreferences,
    ParticipantMeta,
    QualityLevel,
    RoomParticipant,
    ScreenShare,
    VideoEffectId,
    VideoQualitySetting,
} from '@/lib/meet/types';
import type { JoinRefusal, MediaCredentials } from '@/types';

type LiveKit = typeof import('livekit-client');

/** Why the media connection ended for good (as opposed to dropping and coming back). */
export type SessionEnd =
    | { kind: 'left' }
    | { kind: 'removed' }
    | { kind: 'ended' }
    | { kind: 'duplicate' }
    | { kind: 'refused'; message: string };

type Options = {
    endpoints: MeetEndpoints;
    preferences: Ref<DevicePreferences>;
    onEnd: (end: SessionEnd) => void;
    onNotice: (
        level: 'info' | 'warning' | 'error',
        message: string,
        detail?: string,
    ) => void;
    /** Called after the media connection came back, so server state can be re-read. */
    onReconnected: () => void;
};

const RETRY_DELAYS = [1000, 2000, 4000, 8000, 10_000];

function parseMeta(participant: Participant): ParticipantMeta {
    const fallback: ParticipantMeta = {
        uuid: participant.identity.replace(/^p_/, ''),
        role: 'guest',
        guest: true,
        avatar: null,
        initials: (participant.name ?? '?').slice(0, 1).toUpperCase(),
    };

    if (!participant.metadata) {
        return fallback;
    }

    try {
        const parsed = JSON.parse(
            participant.metadata,
        ) as Partial<ParticipantMeta>;

        return {
            uuid: typeof parsed.uuid === 'string' ? parsed.uuid : fallback.uuid,
            role:
                parsed.role === 'host' || parsed.role === 'member'
                    ? parsed.role
                    : 'guest',
            guest: parsed.guest !== false,
            avatar: typeof parsed.avatar === 'string' ? parsed.avatar : null,
            initials:
                typeof parsed.initials === 'string' && parsed.initials !== ''
                    ? parsed.initials
                    : fallback.initials,
        };
    } catch {
        return fallback;
    }
}

/**
 * The media side of a meeting: the connection to the SFU, who is in the room with which
 * tracks, and this person's own microphone, camera and screen.
 *
 * Reliability rules this follows:
 *  - The SDK repairs short drops itself (ICE restart, signal reconnect). When it gives up,
 *    this keeps trying with a fresh token until the connection is back or the meeting is
 *    over. Nobody is ever asked to reload.
 *  - After any reconnect the microphone and camera are put back the way the person left them.
 *  - Nothing here ever touches audio quality. "Audio only" stops video, never sound.
 */
export function useRoomSession(options: Options) {
    const { endpoints, preferences } = options;

    const phase = ref<ConnectionPhase>('idle');
    const participants = shallowRef<RoomParticipant[]>([]);
    const lastSpokeAt = ref<Record<string, number>>({});
    const micEnabled = ref(false);
    const cameraEnabled = ref(false);
    const screenSharing = ref(false);
    const micBusy = ref(false);
    const cameraBusy = ref(false);
    const screenBusy = ref(false);
    const canPlayAudio = ref(true);
    const audioOnly = ref(false);
    const localQuality = ref<QualityLevel>('unknown');
    const micProblem = ref<MediaProblem | null>(null);
    const cameraProblem = ref<MediaProblem | null>(null);
    const canPublishScreen = ref(true);
    /** The raw microphone track, for the local level meter. */
    const localMicTrack = shallowRef<MediaStreamTrack | null>(null);

    let lk: LiveKit | null = null;
    let room: Room | null = null;
    let closed = false;
    let retryTimer: ReturnType<typeof setTimeout> | undefined;
    let retryAttempt = 0;
    let establishing = false;
    let refreshFrame = 0;
    /** The effect on the camera track that is published right now, if any. */
    let cameraEffect: CameraEffectProcessor | null = null;

    /** What the person wants, as opposed to what is currently published. */
    const wanted = {
        mic: preferences.value.micEnabled,
        camera: preferences.value.cameraEnabled,
    };

    const local = computed(
        () => participants.value.find((p) => p.isLocal) ?? null,
    );
    const remotes = computed(() =>
        participants.value.filter((p) => !p.isLocal),
    );

    const screens = computed<ScreenShare[]>(() =>
        participants.value
            .filter((p) => p.screenTrack !== null)
            .map((p) => ({
                id: `screen:${p.identity}`,
                identity: p.identity,
                name: p.name,
                isLocal: p.isLocal,
                track: p.screenTrack as Track,
            })),
    );

    // --- Snapshot ------------------------------------------------------------------------

    const snapshot = (
        participant: Participant,
        isLocal: boolean,
    ): RoomParticipant => {
        const source = (lk as LiveKit).Track.Source;
        const camera = participant.getTrackPublication(source.Camera);
        const microphone = participant.getTrackPublication(source.Microphone);
        const screen = participant.getTrackPublication(source.ScreenShare);
        const screenAudio = participant.getTrackPublication(
            source.ScreenShareAudio,
        );

        const cameraLive = !!camera?.track && !camera.isMuted;
        const paused =
            !isLocal &&
            cameraLive &&
            (camera?.track as Track | undefined)?.streamState ===
                (lk as LiveKit).Track.StreamState.Paused;
        const cameraWanted =
            isLocal ||
            (camera as RemoteTrackPublication | undefined)?.isEnabled !== false;

        const audioTracks: Track[] = [];

        if (!isLocal) {
            for (const publication of [microphone, screenAudio]) {
                if (publication?.track && publication.isSubscribed) {
                    audioTracks.push(markRaw(publication.track));
                }
            }
        }

        return {
            identity: participant.identity,
            name: participant.name || 'Guest',
            isLocal,
            meta: parseMeta(participant),
            micEnabled: !!microphone && !microphone.isMuted,
            cameraEnabled: cameraLive,
            cameraTrack:
                cameraLive && cameraWanted && camera?.track
                    ? markRaw(camera.track)
                    : null,
            cameraPublication: camera
                ? markRaw(
                      camera as RemoteTrackPublication | LocalTrackPublication,
                  )
                : null,
            cameraPaused: paused,
            screenTrack:
                screen?.track && !screen.isMuted ? markRaw(screen.track) : null,
            screenAudioEnabled: !!screenAudio && !screenAudio.isMuted,
            audioTracks,
            isSpeaking: participant.isSpeaking,
            quality: qualityFromLiveKit(participant.connectionQuality),
            joinedAt: participant.joinedAt?.getTime() ?? Date.now(),
        };
    };

    const rebuild = () => {
        refreshFrame = 0;

        if (!room || !lk) {
            participants.value = [];

            return;
        }

        const list = [snapshot(room.localParticipant, true)];

        room.remoteParticipants.forEach((participant) => {
            // The recorder joins hidden and is never delivered to browsers; this is the
            // second line of defence should that ever change.
            if (!participant.identity.startsWith('recorder_')) {
                list.push(snapshot(participant, false));
            }
        });

        participants.value = list;

        const me = room.localParticipant;
        const source = lk.Track.Source;

        micEnabled.value = me.isMicrophoneEnabled;
        cameraEnabled.value = me.isCameraEnabled;
        screenSharing.value = me.isScreenShareEnabled;
        localQuality.value = qualityFromLiveKit(me.connectionQuality);
        canPublishScreen.value =
            me.permissions?.canPublishSources === undefined ||
            me.permissions.canPublishSources.length === 0 ||
            me.permissions.canPublishSources.includes(
                // Proto enum value of SCREEN_SHARE.
                3,
            );
        localMicTrack.value =
            me.getTrackPublication(source.Microphone)?.track
                ?.mediaStreamTrack ?? null;
    };

    /** Coalesce bursts of room events into one rebuild per frame. */
    const refresh = () => {
        if (refreshFrame || typeof requestAnimationFrame === 'undefined') {
            return;
        }

        refreshFrame = requestAnimationFrame(rebuild);
    };

    // --- Connecting ----------------------------------------------------------------------

    const fetchCredentials = async (): Promise<MediaCredentials> =>
        api.post<MediaCredentials>(endpoints.credentials());

    const applyAudioOnly = () => {
        room?.remoteParticipants.forEach((participant) => {
            const camera = participant.getTrackPublication(
                (lk as LiveKit).Track.Source.Camera,
            );

            camera?.setEnabled(!audioOnly.value);
        });
    };

    const bind = (target: Room, sdk: LiveKit) => {
        const event = sdk.RoomEvent;

        const structural = [
            event.ParticipantConnected,
            event.ParticipantDisconnected,
            event.TrackPublished,
            event.TrackUnpublished,
            event.TrackSubscribed,
            event.TrackUnsubscribed,
            event.TrackMuted,
            event.TrackUnmuted,
            event.LocalTrackPublished,
            event.LocalTrackUnpublished,
            event.ParticipantMetadataChanged,
            event.ParticipantNameChanged,
            event.ConnectionQualityChanged,
            event.TrackStreamStateChanged,
            event.TrackSubscriptionStatusChanged,
            event.ParticipantPermissionsChanged,
        ] as const;

        structural.forEach((name) => target.on(name, refresh));

        target.on(event.TrackPublished, () => applyAudioOnly());

        target.on(event.ActiveSpeakersChanged, (speakers: Participant[]) => {
            if (speakers.length > 0) {
                const now = Date.now();
                const next = { ...lastSpokeAt.value };

                speakers.forEach((speaker) => (next[speaker.identity] = now));
                lastSpokeAt.value = next;
            }

            refresh();
        });

        target.on(event.AudioPlaybackStatusChanged, () => {
            canPlayAudio.value = target.canPlaybackAudio;
        });

        target.on(event.Reconnecting, () => (phase.value = 'reconnecting'));
        target.on(
            event.SignalReconnecting,
            () => (phase.value = 'reconnecting'),
        );
        target.on(event.Reconnected, () => {
            phase.value = 'connected';
            refresh();
            // The SDK republishes what was live; this covers a publish that was still in
            // flight when the connection dropped.
            void republish();
            options.onReconnected();
        });

        // A host can mute someone's microphone. Remember that as their state, so a later
        // reconnect does not quietly switch the microphone back on.
        const followMute =
            (muted: boolean) =>
            (
                publication: { source: Track.Source },
                participant: Participant,
            ) => {
                if (
                    participant === target.localParticipant &&
                    publication.source === sdk.Track.Source.Microphone
                ) {
                    wanted.mic = !muted;
                }
            };

        target.on(event.TrackMuted, followMute(true));
        target.on(event.TrackUnmuted, followMute(false));

        target.on(event.MediaDevicesError, (error: Error) => {
            options.onNotice(
                'error',
                explainMediaError(error, 'camera and microphone').title,
                explainMediaError(error, 'camera and microphone').help,
            );
        });

        target.on(
            event.LocalTrackUnpublished,
            (publication: LocalTrackPublication) => {
                // The browser's own "Stop sharing" bar ends the track; that arrives here.
                if (publication.source === sdk.Track.Source.ScreenShare) {
                    screenSharing.value = false;
                }
            },
        );

        target.on(event.Disconnected, (reason?: DisconnectReason) => {
            handleDisconnected(reason, sdk);
        });
    };

    const handleDisconnected = (
        reason: DisconnectReason | undefined,
        sdk: LiveKit,
    ) => {
        refresh();

        if (closed) {
            return;
        }

        const reasons = sdk.DisconnectReason;

        switch (reason) {
            case reasons.CLIENT_INITIATED:
                return;
            case reasons.PARTICIPANT_REMOVED:
                closed = true;
                phase.value = 'disconnected';
                options.onEnd({ kind: 'removed' });

                return;
            case reasons.ROOM_DELETED:
            case reasons.ROOM_CLOSED:
                closed = true;
                phase.value = 'disconnected';
                options.onEnd({ kind: 'ended' });

                return;
            case reasons.DUPLICATE_IDENTITY:
                closed = true;
                phase.value = 'disconnected';
                options.onEnd({ kind: 'duplicate' });

                return;
            default:
                // Network loss, server restart, anything recoverable: keep trying, on a
                // fresh room object. The one that just gave up can be left holding
                // half-finished publications that would block the microphone and camera
                // from ever being published again.
                phase.value = 'reconnecting';

                if (screenSharing.value) {
                    // Sharing needs a click in the browser's picker; it cannot be resumed.
                    screenSharing.value = false;
                    options.onNotice(
                        'warning',
                        'Screen sharing stopped',
                        'The connection was lost. Share again once you are back.',
                    );
                }

                createRoom(sdk);
                scheduleRetry();
        }
    };

    const scheduleRetry = (immediate = false) => {
        if (closed) {
            return;
        }

        clearTimeout(retryTimer);

        const delay = immediate
            ? 0
            : RETRY_DELAYS[Math.min(retryAttempt, RETRY_DELAYS.length - 1)];

        retryAttempt++;
        retryTimer = setTimeout(() => void establish(true), delay);
    };

    /**
     * Connect (or connect again) with a fresh token, then put microphone and camera back.
     * Only one attempt runs at a time, and never while the SDK is still repairing the
     * connection on its own.
     */
    const establish = async (isRetry: boolean): Promise<void> => {
        if (closed || !room || !lk || establishing) {
            return;
        }

        if (room.state !== 'disconnected') {
            // The SDK is connected or mid-reconnect; it reports back through its events.
            return;
        }

        establishing = true;

        try {
            const credentials = await fetchCredentials();

            if (closed) {
                return;
            }

            await room.connect(credentials.url, credentials.token);
        } catch (error) {
            establishing = false;

            if (closed) {
                return;
            }

            if (error instanceof HttpError && error.status === 403) {
                const refusal = error.body as Partial<JoinRefusal>;

                closed = true;
                phase.value = 'disconnected';

                options.onEnd(
                    refusal.reason === 'ended'
                        ? { kind: 'ended' }
                        : refusal.reason === 'removed'
                          ? { kind: 'removed' }
                          : { kind: 'refused', message: error.message },
                );

                return;
            }

            // The app server or the media server cannot be reached right now.
            phase.value =
                isRetry || retryAttempt > 0 ? 'reconnecting' : 'connecting';
            scheduleRetry();

            return;
        }

        establishing = false;
        retryAttempt = 0;
        phase.value = 'connected';
        canPlayAudio.value = room.canPlaybackAudio;
        rebuild();

        void api.post(endpoints.connected()).catch(() => {});

        await republish();

        applyAudioOnly();
        rebuild();

        if (isRetry) {
            options.onReconnected();
        }
    };

    /**
     * Make the room object the session runs on, retiring the previous one if there was one.
     */
    const createRoom = (sdk: LiveKit): void => {
        const previous = room;

        if (previous) {
            previous.removeAllListeners();
            void previous.disconnect(true).catch(() => {});
        }

        room = markRaw(new sdk.Room(roomOptions(sdk, preferences.value)));
        bind(room, sdk);
        establishing = false;

        if (import.meta.env.DEV) {
            // For poking at the connection from the console while developing:
            // __tandemMeet.room.simulateScenario('server-leave')
            (
                window as unknown as { __tandemMeet?: { room: Room } }
            ).__tandemMeet = { room };
        }
    };

    const connect = async (): Promise<void> => {
        if (room || typeof window === 'undefined') {
            return;
        }

        closed = false;
        phase.value = 'connecting';
        wanted.mic = preferences.value.micEnabled;
        wanted.camera = preferences.value.cameraEnabled;

        // Loaded here, never at module level: the page is rendered on the server too and
        // the SDK is only worth downloading for someone who actually joins.
        lk = await import('livekit-client');
        createRoom(lk);

        window.addEventListener('online', handleOnline);

        await establish(false);
    };

    const handleOnline = () => {
        // The network is back: do not sit out the rest of a backoff delay.
        if (phase.value === 'reconnecting' && room?.state === 'disconnected') {
            scheduleRetry(true);
        }
    };

    const disconnect = async (notifyServer = true): Promise<void> => {
        closed = true;
        clearTimeout(retryTimer);

        if (typeof window !== 'undefined') {
            window.removeEventListener('online', handleOnline);
        }

        const current = room;
        room = null;

        if (current) {
            await current.disconnect(true).catch(() => {});
            current.removeAllListeners();
        }

        void cameraEffect?.destroy();
        cameraEffect = null;

        if (notifyServer) {
            void api.post(endpoints.leave()).catch(() => {});
        }

        phase.value = 'disconnected';
        participants.value = [];
        localMicTrack.value = null;
    };

    // --- Microphone ----------------------------------------------------------------------

    /**
     * Whether an error came from opening a device (permission, missing, busy) rather than
     * from the connection. Only the first kind is the person's to fix; the second is
     * retried by the reconnect logic and must not switch their microphone or camera off.
     */
    const isDeviceError = (error: unknown): boolean => {
        const name = (error as { name?: unknown })?.name;

        return (
            typeof name === 'string' &&
            [
                'NotAllowedError',
                'PermissionDeniedError',
                'SecurityError',
                'NotFoundError',
                'DevicesNotFoundError',
                'NotReadableError',
                'TrackStartError',
                'OverconstrainedError',
                'ConstraintNotSatisfiedError',
                'AbortError',
                'TypeError',
            ].includes(name)
        );
    };

    /**
     * A publish that failed for a reason other than the device. While the connection is
     * down that is expected and silent: `republish` runs again once it is back. On a
     * healthy connection it is worth telling the person.
     */
    const publishFailed = (
        kind: 'microphone' | 'camera',
        error: unknown,
    ): void => {
        if (room?.state !== 'connected') {
            return;
        }

        options.onNotice(
            'error',
            `Your ${kind} could not be shared`,
            (error as Error)?.message ?? 'Try switching it off and on again.',
        );
    };

    const enableMicrophone = async (): Promise<void> => {
        if (!room) {
            return;
        }

        try {
            await room.localParticipant.setMicrophoneEnabled(
                true,
                audioCaptureOptions(preferences.value),
                microphonePublishOptions(),
            );
            micProblem.value = null;
        } catch (error) {
            if (!isDeviceError(error)) {
                publishFailed('microphone', error);

                return;
            }

            // A remembered device that is gone: fall back to the system default once.
            if (preferences.value.audioInputId) {
                preferences.value.audioInputId = null;

                return enableMicrophone();
            }

            micProblem.value = explainMediaError(error, 'microphone');
            wanted.mic = false;
            options.onNotice(
                'error',
                micProblem.value.title,
                micProblem.value.help,
            );
        }
    };

    /**
     * Publish whatever the person has switched on that is not published right now: after
     * connecting, and after every reconnect.
     */
    const republish = async (): Promise<void> => {
        if (!room || !lk || room.state !== 'connected') {
            return;
        }

        const sdk = lk;
        const me = room.localParticipant;

        await Promise.allSettled([
            wanted.mic && !me.getTrackPublication(sdk.Track.Source.Microphone)
                ? enableMicrophone()
                : Promise.resolve(),
            wanted.camera && !me.getTrackPublication(sdk.Track.Source.Camera)
                ? enableCamera(sdk)
                : Promise.resolve(),
        ]);

        rebuild();
    };

    const setMicrophone = async (enabled: boolean): Promise<void> => {
        if (!room || !lk || micBusy.value) {
            return;
        }

        micBusy.value = true;
        wanted.mic = enabled;
        preferences.value.micEnabled = enabled;

        try {
            if (enabled) {
                await enableMicrophone();
            } else {
                await room.localParticipant.setMicrophoneEnabled(false);
            }
        } finally {
            micBusy.value = false;
            rebuild();
        }
    };

    // --- Camera --------------------------------------------------------------------------

    const publishedCamera = (): LocalVideoTrack | null =>
        (room?.localParticipant.getTrackPublication(
            (lk as LiveKit).Track.Source.Camera,
        )?.track as LocalVideoTrack | undefined) ?? null;

    /**
     * The effect someone picked, ready to be put on a camera track. The effect code (and the
     * face tracker behind it) is only downloaded here, for someone who actually uses one.
     */
    const createCameraEffect = async (
        effect: Exclude<VideoEffectId, 'none'>,
    ): Promise<CameraEffectProcessor> => {
        const { createEffectProcessor } =
            await import('@/lib/meet/effects/processor');

        return createEffectProcessor(effect, {
            onError: () =>
                options.onNotice(
                    'warning',
                    'Your face could not be added to the effect',
                    'Others see the effect without your face. Switch it off in Settings to show your camera.',
                ),
        });
    };

    const enableCamera = async (sdk: LiveKit): Promise<void> => {
        if (!room) {
            return;
        }

        const effect = preferences.value.videoEffect;
        let processor: CameraEffectProcessor | null = null;

        // A camera that is only switched off keeps its track, and with it its effect. A
        // new track gets the effect before it is published.
        if (effect !== 'none' && !publishedCamera()) {
            try {
                processor = await createCameraEffect(effect);
            } catch {
                // Publishing the bare camera instead is not what was asked for.
                wanted.camera = false;
                options.onNotice(
                    'error',
                    'The camera effect could not be loaded',
                    'Your camera stays off. Try again, or switch the effect off in Settings.',
                );

                return;
            }
        }

        try {
            await room.localParticipant.setCameraEnabled(
                true,
                videoCaptureOptions(preferences.value, processor ?? undefined),
                cameraPublishOptions(sdk, preferences.value.videoQuality),
            );
            cameraProblem.value = null;

            if (processor) {
                void cameraEffect?.destroy();
                cameraEffect = processor;
            }
        } catch (error) {
            void processor?.destroy();

            if (!isDeviceError(error)) {
                publishFailed('camera', error);

                return;
            }

            if (preferences.value.videoInputId) {
                preferences.value.videoInputId = null;

                return enableCamera(sdk);
            }

            cameraProblem.value = explainMediaError(error, 'camera');
            wanted.camera = false;
            options.onNotice(
                'error',
                cameraProblem.value.title,
                cameraProblem.value.help,
            );
        }
    };

    const setCamera = async (enabled: boolean): Promise<void> => {
        if (!room || !lk || cameraBusy.value) {
            return;
        }

        cameraBusy.value = true;
        wanted.camera = enabled;
        preferences.value.cameraEnabled = enabled;

        try {
            if (enabled) {
                await enableCamera(lk);
            } else {
                await room.localParticipant.setCameraEnabled(false);
            }
        } finally {
            cameraBusy.value = false;
            rebuild();
        }
    };

    /**
     * Apply a new camera quality. The encoding and its simulcast layers are fixed when a
     * track is published, so the camera is republished rather than patched.
     */
    const setVideoQuality = async (
        quality: VideoQualitySetting,
    ): Promise<void> => {
        preferences.value.videoQuality = quality;

        if (!room || !lk || !room.localParticipant.isCameraEnabled) {
            return;
        }

        const publication = room.localParticipant.getTrackPublication(
            lk.Track.Source.Camera,
        );

        cameraBusy.value = true;

        try {
            if (publication?.track) {
                await room.localParticipant.unpublishTrack(
                    publication.track as LocalTrack,
                    true,
                );
            }

            await enableCamera(lk);
        } finally {
            cameraBusy.value = false;
            rebuild();
        }
    };

    /**
     * Change the camera effect. On a published camera the switch happens on the running
     * track, so nothing is republished; with the camera off it applies the next time the
     * camera is switched on.
     */
    const setVideoEffect = async (effect: VideoEffectId): Promise<void> => {
        if (cameraBusy.value || preferences.value.videoEffect === effect) {
            return;
        }

        preferences.value.videoEffect = effect;

        const track = publishedCamera();

        if (!track) {
            return;
        }

        cameraBusy.value = true;

        try {
            if (effect === 'none') {
                await track.stopProcessor();
                cameraEffect = null;
            } else {
                const processor = await createCameraEffect(effect);

                await track.setProcessor(processor);
                cameraEffect = processor;
            }
        } catch {
            // The SDK takes the previous effect off before it starts the next one, so
            // after a failure the camera is going out as it is. Say so, and let the
            // setting say so too.
            preferences.value.videoEffect = 'none';
            await track.stopProcessor().catch(() => {});
            cameraEffect = null;
            options.onNotice(
                'error',
                'The camera effect could not be started',
                'Your camera is shown without an effect.',
            );
        } finally {
            cameraBusy.value = false;
            rebuild();
        }
    };

    // --- Screen share --------------------------------------------------------------------

    const stopScreenShare = async (): Promise<void> => {
        if (!room || !lk) {
            return;
        }

        const source = lk.Track.Source;

        for (const kind of [source.ScreenShare, source.ScreenShareAudio]) {
            const publication = room.localParticipant.getTrackPublication(kind);

            if (publication?.track) {
                await room.localParticipant
                    .unpublishTrack(publication.track as LocalTrack, true)
                    .catch(() => {});
            }
        }

        rebuild();
    };

    const startScreenShare = async (): Promise<void> => {
        if (!room || !lk) {
            return;
        }

        const motion = preferences.value.screenShareMotion;
        let tracks: LocalTrack[];

        try {
            tracks = await room.localParticipant.createScreenTracks(
                screenShareCaptureOptions(
                    motion,
                    screenCaptureLimits(
                        window.screen.width,
                        window.screen.height,
                        window.devicePixelRatio,
                    ),
                ),
            );
        } catch (error) {
            // Closing the browser's picker is a choice, not an error worth a message.
            const name = (error as { name?: string })?.name;
            const cancelled =
                name === 'NotAllowedError' &&
                /dismissed|denied by user|cancel/i.test(
                    String((error as Error)?.message ?? ''),
                );

            if (!cancelled && name !== 'AbortError') {
                const problem = explainMediaError(error, 'screen');

                // Chrome reports a dismissed picker and a blocked permission the same
                // way; say something only when the message hints at a real block.
                if (name !== 'NotAllowedError') {
                    options.onNotice('error', problem.title, problem.help);
                }
            }

            return;
        }

        for (const track of tracks) {
            try {
                if (track.kind === lk.Track.Kind.Video) {
                    const settings = track.mediaStreamTrack.getSettings();

                    await room.localParticipant.publishTrack(
                        track,
                        screenSharePublishOptions(
                            settings.width ?? 1920,
                            settings.height ?? 1080,
                            motion,
                        ),
                    );
                } else {
                    await room.localParticipant.publishTrack(
                        track,
                        screenAudioPublishOptions(),
                    );
                }
            } catch (error) {
                track.stop();
                options.onNotice(
                    'error',
                    'Your screen could not be shared',
                    (error as Error)?.message ?? undefined,
                );
            }
        }

        rebuild();
    };

    const setScreenShare = async (enabled: boolean): Promise<void> => {
        if (screenBusy.value) {
            return;
        }

        screenBusy.value = true;

        try {
            await (enabled ? startScreenShare() : stopScreenShare());
        } finally {
            screenBusy.value = false;
        }
    };

    /**
     * Switch a running share between "sharp text" and "smooth motion" without restarting
     * it: only the encoder's limits change (frame rate, bitrate, what to give up first).
     * The capture itself is left alone.
     */
    const setScreenShareMotion = async (motion: boolean): Promise<void> => {
        preferences.value.screenShareMotion = motion;

        if (!room || !lk) {
            return;
        }

        const track = room.localParticipant.getTrackPublication(
            lk.Track.Source.ScreenShare,
        )?.track as LocalVideoTrack | undefined;

        if (!track) {
            return;
        }

        const media = track.mediaStreamTrack;
        const settings = media.getSettings();
        const encoding = screenShareEncoding(
            settings.width ?? 1920,
            settings.height ?? 1080,
            motion,
        );

        try {
            // With VP9 the SDK pins the hint to "motion" (Chrome's screen-content path
            // caps VP9 at 5 fps); sharpness then comes from keeping the resolution. Only
            // the VP8 fallback takes the hint from us.
            if (track.codec !== 'vp9' && track.codec !== 'av1') {
                media.contentHint = motion ? 'motion' : 'detail';
            }

            const sender = track.sender;

            if (sender) {
                const parameters = sender.getParameters();

                parameters.degradationPreference = motion
                    ? 'balanced'
                    : 'maintain-resolution';
                parameters.encodings.forEach((layer) => {
                    layer.maxFramerate = encoding.maxFramerate;
                    layer.maxBitrate = encoding.maxBitrate;
                });

                await sender.setParameters(parameters);
            }
        } catch {
            // Not every browser lets these change mid-stream; the next share uses them.
        }
    };

    // --- Devices -------------------------------------------------------------------------

    const switchDevice = async (
        kind: 'audioinput' | 'videoinput' | 'audiooutput',
        deviceId: string,
    ): Promise<boolean> => {
        if (kind === 'audioinput') {
            preferences.value.audioInputId = deviceId;
        } else if (kind === 'videoinput') {
            preferences.value.videoInputId = deviceId;
        } else {
            preferences.value.audioOutputId = deviceId;
        }

        if (!room) {
            return false;
        }

        try {
            const switched = await room.switchActiveDevice(kind, deviceId);

            rebuild();

            return switched;
        } catch (error) {
            const problem = explainMediaError(
                error,
                kind === 'videoinput' ? 'camera' : 'microphone',
            );

            options.onNotice('error', problem.title, problem.help);

            return false;
        }
    };

    const activeDevice = (kind: MediaDeviceKind): string | null =>
        room?.getActiveDevice(kind) ?? null;

    /** Browser-native noise suppression is a capture constraint: restart the mic with it. */
    const setNoiseSuppression = async (enabled: boolean): Promise<void> => {
        preferences.value.noiseSuppression = enabled;

        if (!room || !lk) {
            return;
        }

        const track = room.localParticipant.getTrackPublication(
            lk.Track.Source.Microphone,
        )?.track as LocalAudioTrack | undefined;

        if (track) {
            await track
                .restartTrack(audioCaptureOptions(preferences.value))
                .catch(() => {});
            rebuild();
        }
    };

    // --- Audio-first fallbacks ---------------------------------------------------------

    /**
     * Audio only: stop receiving everyone's camera and switch off your own. Shared screens
     * and all sound stay. This is the explicit version of what the server already does on
     * its own when a link gets short.
     */
    const setAudioOnly = async (enabled: boolean): Promise<void> => {
        audioOnly.value = enabled;
        applyAudioOnly();

        if (enabled && room?.localParticipant.isCameraEnabled) {
            await setCamera(false);
        }

        rebuild();
    };

    const startAudio = async (): Promise<void> => {
        await room?.startAudio().catch(() => {});
        canPlayAudio.value = room?.canPlaybackAudio ?? true;
    };

    const rawRoom = (): Room | null => room;

    watch(audioOnly, applyAudioOnly);

    onBeforeUnmount(() => {
        if (refreshFrame) {
            cancelAnimationFrame(refreshFrame);
        }

        void disconnect(false);
    });

    return {
        phase,
        participants,
        local,
        remotes,
        screens,
        lastSpokeAt,
        micEnabled,
        cameraEnabled,
        screenSharing,
        micBusy,
        cameraBusy,
        screenBusy,
        micProblem,
        cameraProblem,
        canPlayAudio,
        canPublishScreen,
        audioOnly,
        localQuality,
        localMicTrack,
        connect,
        disconnect,
        setMicrophone,
        setCamera,
        setScreenShare,
        setScreenShareMotion,
        setVideoQuality,
        setVideoEffect,
        setNoiseSuppression,
        setAudioOnly,
        switchDevice,
        activeDevice,
        startAudio,
        rawRoom,
    };
}

export type RoomSession = ReturnType<typeof useRoomSession>;
