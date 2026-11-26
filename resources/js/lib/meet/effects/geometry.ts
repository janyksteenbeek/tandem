import type { FaceFeatures, FaceLayout, Point, Size } from './types';

/**
 * The arithmetic of putting someone's eyes and mouth on a picture. No browser API in here:
 * everything is numbers in and numbers out, so it can be tested without a camera.
 */

/** Where a face is in the camera picture and how large. */
export type FaceFrame = {
    /** Halfway between the eyes, in camera pixels. */
    origin: Point;
    /** Head tilt: the angle of the line through both eyes, in radians. */
    angle: number;
    /** The distance between the eyes in camera pixels, corrected for a turned head. */
    size: number;
};

export type Ellipse = {
    x: number;
    y: number;
    rx: number;
    ry: number;
    angle: number;
};

/** A uniform scale and a shift: `canvas = source * scale + (x, y)`. */
export type Placement = { scale: number; x: number; y: number };

/**
 * On a face seen straight on, the eye line sits a little further from the mouth than the
 * eyes are apart. Turning the head shortens the eye distance and nodding shortens the
 * distance to the mouth, so the larger of the two (in eye-distance units) is the face's size
 * whichever way it is turned.
 */
const EYE_DISTANCE_PER_MOUTH_DISTANCE = 0.95;

/**
 * How much room each cut-out gets around its outline. The outer part of every cut-out fades
 * into the picture, so the padding has to leave the outline itself in the solid middle. The
 * minimum aspect keeps a cut-out from collapsing to a slit during a blink or with the lips
 * pressed together.
 */
type Padding = { x: number; y: number; minAspect: number };

const EYE_PADDING: Padding = { x: 1.7, y: 2.8, minAspect: 0.62 };
const MOUTH_PADDING: Padding = { x: 1.5, y: 2, minAspect: 0.5 };

export function centroid(points: readonly Point[]): Point {
    let x = 0;
    let y = 0;

    for (const point of points) {
        x += point.x;
        y += point.y;
    }

    const count = Math.max(1, points.length);

    return { x: x / count, y: y / count };
}

/** Null for a face too small (or too degenerate) to cut anything out of. */
export function faceFrame(features: FaceFeatures): FaceFrame | null {
    const left = centroid(features.leftEye);
    const right = centroid(features.rightEye);
    const mouth = centroid(features.mouth);
    const origin = { x: (left.x + right.x) / 2, y: (left.y + right.y) / 2 };

    const size = Math.max(
        Math.hypot(right.x - left.x, right.y - left.y),
        Math.hypot(mouth.x - origin.x, mouth.y - origin.y) *
            EYE_DISTANCE_PER_MOUTH_DISTANCE,
    );

    if (!Number.isFinite(size) || size < 4) {
        return null;
    }

    return {
        origin,
        angle: Math.atan2(right.y - left.y, right.x - left.x),
        size,
    };
}

/**
 * Take the jitter out of the measured size and tilt. The position is left alone: it is what
 * pins the face to the picture, and smoothing it would make the face lag behind the head.
 */
export function steadyFrame(
    previous: FaceFrame | null,
    next: FaceFrame,
    elapsedMs: number,
    settleMs = 80,
): FaceFrame {
    if (!previous || elapsedMs <= 0) {
        return next;
    }

    const weight = 1 - Math.exp(-elapsedMs / settleMs);
    // The short way round, so a tilt near half a turn does not spin the face.
    const turn = Math.atan2(
        Math.sin(next.angle - previous.angle),
        Math.cos(next.angle - previous.angle),
    );

    return {
        origin: next.origin,
        angle: previous.angle + turn * weight,
        size: previous.size + (next.size - previous.size) * weight,
    };
}

/** How a picture is scaled and shifted to fill a canvas completely, cropping the overflow. */
export function coverPlacement(picture: Size, canvas: Size): Placement {
    const scale = Math.max(
        canvas.width / Math.max(1, picture.width),
        canvas.height / Math.max(1, picture.height),
    );

    return {
        scale,
        x: (canvas.width - picture.width * scale) / 2,
        y: (canvas.height - picture.height * scale) / 2,
    };
}

/**
 * How camera pixels map onto the canvas so the face lands where the layout wants it: the
 * point between the eyes on the layout's point, the eyes the layout's distance apart.
 */
export function facePlacement(
    frame: FaceFrame,
    video: Size,
    picture: Size,
    cover: Placement,
    layout: FaceLayout,
): Placement {
    const scale =
        (layout.eyeDistance * picture.width * cover.scale) / frame.size;

    // -1 at one edge of the camera picture, +1 at the other.
    const offCentre = (value: number, extent: number) =>
        Math.max(-1, Math.min(1, (value / Math.max(1, extent)) * 2 - 1));

    const target = {
        x:
            (layout.eyes.x +
                offCentre(frame.origin.x, video.width) * layout.sway) *
                picture.width *
                cover.scale +
            cover.x,
        y:
            (layout.eyes.y +
                offCentre(frame.origin.y, video.height) * layout.sway) *
                picture.height *
                cover.scale +
            cover.y,
    };

    return {
        scale,
        x: target.x - frame.origin.x * scale,
        y: target.y - frame.origin.y * scale,
    };
}

/** The ellipse around one outline, lying along the tilt of the head, in camera pixels. */
function paddedEllipse(
    outline: readonly Point[],
    angle: number,
    padding: Padding,
): Ellipse {
    const middle = centroid(outline);
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);
    let minAlong = Infinity;
    let maxAlong = -Infinity;
    let minAcross = Infinity;
    let maxAcross = -Infinity;

    for (const point of outline) {
        const dx = point.x - middle.x;
        const dy = point.y - middle.y;
        const along = dx * cos + dy * sin;
        const across = -dx * sin + dy * cos;

        minAlong = Math.min(minAlong, along);
        maxAlong = Math.max(maxAlong, along);
        minAcross = Math.min(minAcross, across);
        maxAcross = Math.max(maxAcross, across);
    }

    const along = (minAlong + maxAlong) / 2;
    const across = (minAcross + maxAcross) / 2;
    const rx = ((maxAlong - minAlong) / 2) * padding.x;

    return {
        x: middle.x + along * cos - across * sin,
        y: middle.y + along * sin + across * cos,
        rx,
        ry: Math.max(
            ((maxAcross - minAcross) / 2) * padding.y,
            rx * padding.minAspect,
        ),
        angle,
    };
}

/** The three cut-outs (two eyes, one mouth) where they land on the canvas. */
export function cutouts(
    features: FaceFeatures,
    frame: FaceFrame,
    placement: Placement,
): Ellipse[] {
    return [
        paddedEllipse(features.leftEye, frame.angle, EYE_PADDING),
        paddedEllipse(features.rightEye, frame.angle, EYE_PADDING),
        paddedEllipse(features.mouth, frame.angle, MOUTH_PADDING),
    ].map((ellipse) => ({
        x: ellipse.x * placement.scale + placement.x,
        y: ellipse.y * placement.scale + placement.y,
        rx: ellipse.rx * placement.scale,
        ry: ellipse.ry * placement.scale,
        angle: ellipse.angle,
    }));
}
