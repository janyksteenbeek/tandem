import type { Ref } from 'vue';
import { onBeforeUnmount, ref, shallowRef } from 'vue';
import { cameraProfile } from '@/lib/meet/media';
import type { MediaProblem } from '@/lib/meet/mediaErrors';
import { explainMediaError } from '@/lib/meet/mediaErrors';
import type { DevicePreferences } from '@/lib/meet/types';

/**
 * The camera and microphone on the pre-join screen. Plain getUserMedia, so the media SDK is
 * not loaded before someone actually joins. Tracks are released when joining; the room then
 * opens the same devices itself.
 */
export function usePreview(preferences: Ref<DevicePreferences>) {
    const videoTrack = shallowRef<MediaStreamTrack | null>(null);
    const audioTrack = shallowRef<MediaStreamTrack | null>(null);
    const cameraProblem = ref<MediaProblem | null>(null);
    const microphoneProblem = ref<MediaProblem | null>(null);
    const starting = ref(false);
    let disposed = false;

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
        close(videoTrack);
        close(audioTrack);
    };

    onBeforeUnmount(stop);

    return {
        videoTrack,
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
