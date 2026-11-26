import type { FaceLandmarker } from '@mediapipe/tasks-vision';
import { featuresFromLandmarks } from './landmarks';
import type { FaceFeatures } from './types';

/**
 * Finds the face in a camera frame, on this device. The tracker is MediaPipe's face
 * landmarker (Apache-2.0) running as WebAssembly in the page; its runtime and its model are
 * files of this application, served by Tandem like any other asset. No frame and no
 * measurement leaves the browser.
 *
 * Everything is loaded on first use: nobody who never picks an effect downloads any of it.
 */
export type FaceTracker = {
    /** The eyes and mouth in this frame of the video, or null when there is no face. */
    find(video: HTMLVideoElement, timestampMs: number): FaceFeatures | null;
    close(): void;
};

export async function createFaceTracker(): Promise<FaceTracker> {
    const [vision, loader, binary, model] = await Promise.all([
        import('@mediapipe/tasks-vision'),
        import('@mediapipe/tasks-vision/wasm/vision_wasm_internal.js?url'),
        import('@mediapipe/tasks-vision/wasm/vision_wasm_internal.wasm?url'),
        import('./assets/face_landmarker.task?url'),
    ]);

    const runtime = {
        wasmLoaderPath: loader.default,
        wasmBinaryPath: binary.default,
    };

    const create = (delegate: 'GPU' | 'CPU'): Promise<FaceLandmarker> =>
        vision.FaceLandmarker.createFromOptions(runtime, {
            baseOptions: { modelAssetPath: model.default, delegate },
            runningMode: 'VIDEO',
            numFaces: 1,
        });

    // The graphics card where there is one; otherwise the processor, which is slower but
    // fast enough for one face.
    const landmarker = await create('GPU').catch(() => create('CPU'));

    return {
        find(video, timestampMs) {
            const result = landmarker.detectForVideo(video, timestampMs);

            return featuresFromLandmarks(result.faceLandmarks[0], {
                width: video.videoWidth,
                height: video.videoHeight,
            });
        },
        close() {
            landmarker.close();
        },
    };
}
