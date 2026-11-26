import type { VideoEffectId } from '@/lib/meet/types';

export type ActiveEffectId = Exclude<VideoEffectId, 'none'>;

export type Point = { x: number; y: number };

export type Size = { width: number; height: number };

/**
 * The outlines an effect cuts out of the camera picture, in camera pixels. Left and right
 * are sides of the picture as the camera sees it, not of the person.
 */
export type FaceFeatures = {
    leftEye: Point[];
    rightEye: Point[];
    mouth: Point[];
};

/**
 * Where a face sits on an effect's picture, in fractions of that picture, so the face stays
 * on its subject however the picture is cropped to the camera's shape.
 */
export type FaceLayout = {
    /** The point halfway between the eyes. */
    eyes: Point;
    /** Distance between the eyes, as a fraction of the picture's width. */
    eyeDistance: number;
    /**
     * How far the face drifts when the head reaches the edge of the camera picture, as a
     * fraction of the picture's size. A little of it keeps the face from looking pasted on.
     */
    sway: number;
};

export type EffectDefinition = {
    id: ActiveEffectId;
    label: string;
    picture: string;
    thumbnail: string;
    layout: FaceLayout;
};

export type EffectHooks = {
    /**
     * Face tracking could not start. The effect keeps showing its picture without a face;
     * the camera picture is never shown instead.
     */
    onError?: (error: unknown) => void;
};
