import type { Ref } from 'vue';
import { onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import type { EffectPipeline } from '@/lib/meet/effects/pipeline';
import { cameraProfile } from '@/lib/meet/media';
import type { MediaProblem } from '@/lib/meet/mediaErrors';
import { explainMediaError } from '@/lib/meet/mediaErrors';
import type { DevicePreferences } from '@/lib/meet/types';

/**
 * The camera and microphone on the pre-join screen. Plain getUserMedia, so the media SDK is
 * not loaded before someone actually joins. Tracks are released when joining; the room then
 * opens the same devices itself.
 *
 * With a camera effect chosen, what is shown is the effect and never the camera as it is:
 * the preview is what the others are going to see.
 */
export function usePreview(preferences: Ref<DevicePreferences>) {
    const videoTrack = shallowRef<MediaStreamTrack | null>(null);
    const audioTrack = shallowRef<MediaStreamTrack | null>(null);
    const cameraProblem = ref<MediaProblem | null>(null);
    const microphoneProblem = ref<MediaProblem | null>(null);
    const starting = ref(false);
    /** What to show: the camera, or the camera with the chosen effect on it. */
    const shownVideoTrack = shallowRef<MediaStreamTrack | null>(null);
    const effectStarting = ref(false);
    /** Why the chosen effect is not (fully) working, in words for the person. */
    const effectProblem = ref<string | null>(null);
    let disposed = false;
    let effect: EffectPipeline | null = null;
    let effectRun = 0;
    let effectGaveUp = false;

    /**
     * Put the chosen effect on the camera, or take it off. Runs whenever the camera track
     * or the choice changes; a run that was overtaken by a newer one cleans up after itself.
     */
    const syncEffect = async (): Promise<void> => {
        const run = ++effectRun;
        const camera = videoTrack.value;
        const chosen = preferences.value.videoEffect;

        effect?.destroy();
        effect = null;

        // Giving up on an effect switches it off, which lands here again: that run is the
        // one that has to leave the explanation standing.
        if (!effectGaveUp) {
            effectProblem.value = null;
        }

        effectGaveUp = false;

        if (!camera || chosen === 'none' || disposed) {
            effectStarting.value = false;
            shownVideoTrack.value = disposed ? null : camera;

            return;
        }

        // Nothing rather than the bare camera while the effect is getting ready.
        shownVideoTrack.value = null;
        effectStarting.value = true;

        try {
            const { createEffectPipeline } =
                await import('@/lib/meet/effects/pipeline');
            const pipeline = await createEffectPipeline(chosen, camera, {
                onError: () => {
                    if (run === effectRun) {
                        effectProblem.value =
                            'Your face could not be added to this effect. Others would see it without your face.';
                    }
                },
            });

            if (run !== effectRun || disposed) {
                pipeline.destroy();

                return;
            }

            effect = pipeline;
            shownVideoTrack.value = pipeline.output;
        } catch {
            if (run !== effectRun || disposed) {
                return;
            }

            // The effect cannot run here at all: go back to the plain camera, visibly.
            effectGaveUp = true;
            preferences.value.videoEffect = 'none';
            effectProblem.value =
                'This effect could not be loaded, so your camera is shown as it is.';
        } finally {
            if (run === effectRun) {
                effectStarting.value = false;
            }
        }
    };

    watch(
        [videoTrack, () => preferences.value.videoEffect],
        () => void syncEffect(),
    );

    const available = () =>
        typeof navigator !== 'undefined' &&
        !!navigator.mediaDevices?.getUserMedia;

    const audioConstraints = (withDevice = true): MediaTrackConstraints => ({
        deviceId:
            withDevice && preferences.value.audioInputId
                ? { exact: preferences.value.audioInputId }
                : undefined,
        echoCancellation: true,
        autoGainControl: true,
        noiseSuppression: preferences.value.noiseSuppression,
    });

    const videoConstraints = (withDevice = true): MediaTrackConstraints => {
        const profile = cameraProfile(preferences.value.videoQuality);

        return {
            deviceId:
                withDevice && preferences.value.videoInputId
                    ? { exact: preferences.value.videoInputId }
                    : undefined,
            width: { ideal: profile.width },
            height: { ideal: profile.height },
            frameRate: { ideal: profile.frameRate },
        };
    };

    /**
     * Open one device. A remembered device that has since been unplugged is retried with
     * the system default before giving up.
     */
    const open = async (
        kind: 'audio' | 'video',
    ): Promise<MediaStreamTrack | null> => {
        const problem = kind === 'audio' ? microphoneProblem : cameraProblem;
        const label = kind === 'audio' ? 'microphone' : 'camera';
        const constraints = (withDevice: boolean): MediaStreamConstraints =>
            kind === 'audio'
                ? { audio: audioConstraints(withDevice) }
                : { video: videoConstraints(withDevice) };

        if (!available()) {
            problem.value = explainMediaError(new TypeError(), label);

            return null;
        }

        try {
            const stream = await navigator.mediaDevices
                .getUserMedia(constraints(true))
                .catch((error: unknown) => {
                    const name = (error as { name?: string })?.name;

                    if (
                        name === 'OverconstrainedError' ||
                        name === 'NotFoundError'
                    ) {
                        return navigator.mediaDevices.getUserMedia(
                            constraints(false),
                        );
                    }

                    throw error;
                });

            // Joined (or left the page) while the device was still opening: release it
            // right away, or the camera would stay on with nothing showing it.
            if (disposed) {
                stream.getTracks().forEach((track) => track.stop());

                return null;
            }

            problem.value = null;

            const [track] =
                kind === 'audio'
                    ? stream.getAudioTracks()
                    : stream.getVideoTracks();
            const deviceId = track?.getSettings().deviceId ?? null;

            // Remember what actually opened, so the room starts on the same device.
            if (deviceId) {
                if (kind === 'audio') {
                    preferences.value.audioInputId = deviceId;
                } else {
                    preferences.value.videoInputId = deviceId;
                }
            }

            return track ?? null;
        } catch (error) {
            problem.value = explainMediaError(error, label);

            return null;
        }
    };

    const close = (track: Ref<MediaStreamTrack | null>) => {
        track.value?.stop();
        track.value = null;
    };

    const setMicrophone = async (enabled: boolean): Promise<void> => {
        preferences.value.micEnabled = enabled;

        if (!enabled) {
            // Keep the track for the level meter but stop it from carrying sound.
            if (audioTrack.value) {
                audioTrack.value.enabled = false;
            }

            return;
        }

        if (audioTrack.value) {
            audioTrack.value.enabled = true;

            return;
        }

        audioTrack.value = await open('audio');
    };

    const setCamera = async (enabled: boolean): Promise<void> => {
        preferences.value.cameraEnabled = enabled;

        if (!enabled) {
            close(videoTrack);

            return;
        }

        if (!videoTrack.value) {
            videoTrack.value = await open('video');
        }
    };

    const start = async (): Promise<void> => {
        if (starting.value) {
            return;
        }

        starting.value = true;

        // The microphone is opened even when it starts muted: the permission prompt then
        // appears here rather than in the middle of the meeting, and device names show up.
        audioTrack.value ??= await open('audio');

        if (audioTrack.value) {
            audioTrack.value.enabled = preferences.value.micEnabled;
        }

        if (preferences.value.cameraEnabled && !videoTrack.value) {
            videoTrack.value = await open('video');
        }

        starting.value = false;
    };

    const switchMicrophone = async (deviceId: string): Promise<void> => {
        preferences.value.audioInputId = deviceId;
        close(audioTrack);
        audioTrack.value = await open('audio');

        if (audioTrack.value) {
            audioTrack.value.enabled = preferences.value.micEnabled;
        }
    };

    const switchCamera = async (deviceId: string): Promise<void> => {
        preferences.value.videoInputId = deviceId;
        close(videoTrack);

        if (preferences.value.cameraEnabled) {
            videoTrack.value = await open('video');
        }
    };

    const restartMicrophone = async (): Promise<void> => {
        close(audioTrack);
        audioTrack.value = await open('audio');

        if (audioTrack.value) {
            audioTrack.value.enabled = preferences.value.micEnabled;
        }
    };

    const stop = () => {
        disposed = true;
        effectRun++;
        effect?.destroy();
        effect = null;
        effectStarting.value = false;
        shownVideoTrack.value = null;
        close(videoTrack);
        close(audioTrack);
    };

    onBeforeUnmount(stop);

    return {
        videoTrack,
        shownVideoTrack,
        effectStarting,
        effectProblem,
        audioTrack,
        cameraProblem,
        microphoneProblem,
        starting,
        start,
        stop,
        setMicrophone,
        setCamera,
        switchMicrophone,
        switchCamera,
        restartMicrophone,
    };
}
