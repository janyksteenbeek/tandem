import type { VideoEffectId } from '@/lib/meet/types';
import eggThumbnail from './assets/egg-thumb.webp';
import eggPicture from './assets/egg.webp';
import type { ActiveEffectId, EffectDefinition } from './types';

/**
 * The camera effects someone can pick. An effect replaces the camera picture before it
 * leaves the browser, so other people (and a recording) only ever get the result.
 */
export const VIDEO_EFFECTS: EffectDefinition[] = [
    {
        id: 'egg',
        label: 'Egg',
        picture: eggPicture,
        thumbnail: eggThumbnail,
        // The scrambled egg fills the middle of the plate; the face sits on it with room
        // below for a mouth that opens wide.
        layout: { eyes: { x: 0.51, y: 0.39 }, eyeDistance: 0.2, sway: 0.03 },
    },
];

export function isVideoEffect(value: unknown): value is VideoEffectId {
    return (
        value === 'none' || VIDEO_EFFECTS.some((effect) => effect.id === value)
    );
}

export function effectDefinition(id: ActiveEffectId): EffectDefinition {
    const effect = VIDEO_EFFECTS.find((candidate) => candidate.id === id);

    if (!effect) {
        throw new Error(`Unknown video effect: ${id}`);
    }

    return effect;
}
