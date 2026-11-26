import type { Track, TrackProcessor } from 'livekit-client';
import type { EffectPipeline } from './pipeline';
import { createEffectPipeline } from './pipeline';
import type { ActiveEffectId, EffectHooks } from './types';

export type CameraEffectProcessor = TrackProcessor<Track.Kind.Video>;

/**
 * A camera effect in the shape the media SDK plugs into a camera track. The SDK hands over
 * the camera, sends `processedTrack` in its place, calls `restart` whenever the camera
 * behind the track changes (another device, or switched off and on) and `destroy` when the
 * track or the effect goes away.
 */
export function createEffectProcessor(
    effect: ActiveEffectId,
    hooks: EffectHooks = {},
): CameraEffectProcessor {
    let pipeline: EffectPipeline | null = null;

    const processor: CameraEffectProcessor = {
        name: `tandem-effect-${effect}`,
        processedTrack: undefined,
        async init({ track }) {
            pipeline?.destroy();
            pipeline = await createEffectPipeline(effect, track, hooks);
            processor.processedTrack = pipeline.output;
        },
        async restart(options) {
            if (pipeline) {
                pipeline.setSource(options.track);
            } else {
                await processor.init(options);
            }
        },
        destroy() {
            pipeline?.destroy();
            pipeline = null;
            processor.processedTrack = undefined;

            return Promise.resolve();
        },
    };

    return processor;
}
