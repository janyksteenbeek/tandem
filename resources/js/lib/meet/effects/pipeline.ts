import { effectDefinition } from './catalog';
import { createFacePicture } from './facePicture';
import type { FaceTracker } from './faceTracker';
import { startTicker } from './ticker';
import type { ActiveEffectId, EffectHooks, FaceFeatures } from './types';

/**
 * A camera effect from end to end: a camera track goes in, a track with the effect comes
 * out. The same pipeline feeds the preview before joining and the camera in the meeting.
 *
 * It never forwards the camera picture as it is. Until the face tracker is ready, when it
 * finds no face, and when it cannot start at all, the output is the effect's picture alone.
 */
export type EffectPipeline = {
    /** The track to show and to send. It stays the same for the life of the pipeline. */
    readonly output: MediaStreamTrack;
    /** Continue with another camera track (a different camera, or the same one reopened). */
    setSource(track: MediaStreamTrack): void;
    /** Stop everything this made. The camera track belongs to the caller and is left open. */
    destroy(): void;
};

async function loadPicture(url: string): Promise<HTMLImageElement> {
    const image = new Image();

    // Asked for as a cross-origin resource so the canvas stays readable when assets are
    // served from another host; for the usual same-origin asset this changes nothing.
    image.crossOrigin = 'anonymous';
    image.decoding = 'async';
    image.src = url;
    await image.decode();

    return image;
}

export async function createEffectPipeline(
    effect: ActiveEffectId,
    source: MediaStreamTrack,
    hooks: EffectHooks = {},
): Promise<EffectPipeline> {
    const definition = effectDefinition(effect);
    const image = await loadPicture(definition.picture);
    const picture = createFacePicture(
        { image, width: image.naturalWidth, height: image.naturalHeight },
        definition.layout,
    );

    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');

    if (!context || typeof canvas.captureStream !== 'function') {
        throw new Error('This browser cannot draw camera effects.');
    }

    const video = document.createElement('video');

    video.muted = true;
    video.playsInline = true;
    video.autoplay = true;

    let camera = source;
    let tracker: FaceTracker | null = null;
    let destroyed = false;
    let failed = false;
    let lastCameraTime = -1;

    const fail = (error: unknown) => {
        if (!failed && !destroyed) {
            failed = true;
            hooks.onError?.(error);
        }
    };

    const watch = (track: MediaStreamTrack) => {
        camera = track;
        lastCameraTime = -1;
        video.srcObject = new MediaStream([track]);
        void video.play().catch(() => {});
    };

    /** The output has the shape of the camera picture, so tiles are laid out as before. */
    const fit = () => {
        const settings = camera.getSettings();
        const width = video.videoWidth || settings.width || 1280;
        const height = video.videoHeight || settings.height || 720;

        if (canvas.width !== width || canvas.height !== height) {
            canvas.width = width;
            canvas.height = height;
        }
    };

    const paint = () => {
        // A camera that was switched off: there is nothing to show until it is reopened.
        if (destroyed || camera.readyState === 'ended') {
            return;
        }

        fit();

        const now = performance.now();
        const live = video.readyState >= 2 && video.videoWidth > 0;
        let found: FaceFeatures | null | undefined;

        // Only look for the face when the camera delivered a new frame.
        if (live && tracker && video.currentTime !== lastCameraTime) {
            lastCameraTime = video.currentTime;

            try {
                found = tracker.find(video, now);
            } catch (error) {
                found = null;
                fail(error);
            }
        }

        context.imageSmoothingQuality = 'high';
        picture.draw(
            context,
            live
                ? {
                      image: video,
                      width: video.videoWidth,
                      height: video.videoHeight,
                  }
                : null,
            found,
            now,
        );
    };

    watch(source);
    // One frame before anything is captured, so the output never starts out blank.
    paint();

    const frameRate = Math.min(
        30,
        Math.max(15, Math.round(source.getSettings().frameRate ?? 30)),
    );
    const [output] = canvas.captureStream(frameRate).getVideoTracks();
    const stopTicker = startTicker(frameRate, paint);

    // The tracker is the slow part (a model of a few megabytes); the picture is already
    // going out while it loads.
    void import('./faceTracker')
        .then(({ createFaceTracker }) => createFaceTracker())
        .then((ready) => {
            if (destroyed) {
                ready.close();
            } else {
                tracker = ready;
            }
        })
        .catch(fail);

    return {
        output,
        setSource(track) {
            if (!destroyed && track !== camera) {
                watch(track);
            }
        },
        destroy() {
            if (destroyed) {
                return;
            }

            destroyed = true;
            stopTicker();
            output.stop();
            tracker?.close();
            tracker = null;
            video.pause();
            video.srcObject = null;
        },
    };
}
