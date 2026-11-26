import type { FaceFrame } from './geometry';
import {
    coverPlacement,
    cutouts,
    faceFrame,
    facePlacement,
    steadyFrame,
} from './geometry';
import type { FaceFeatures, FaceLayout, Size } from './types';

/**
 * Draws a picture with someone's eyes and mouth on it: the picture fills the frame, and the
 * three parts are cut out of the camera picture with soft edges and placed where a face
 * would be.
 *
 * The camera picture is only ever drawn through the cut-outs. Without a face there is just
 * the picture.
 */

/** Something to draw from, with the size it really has (a video element does not say). */
export type Source = { image: CanvasImageSource } & Size;

/**
 * The cut-outs stay as they are for this long after the face was last found, then fade.
 * That bridges a camera slower than the frame rate and a face that drops out of tracking
 * for a moment (a hand passing, a quick turn) without the parts blinking off and on.
 */
const HOLD_MS = 150;
const FADE_MS = 200;
/** How quickly the parts appear once a face is found. */
const APPEAR_MS = 180;

export type FacePicture = {
    /**
     * Paint one frame. `features` is what the tracker found in this camera frame: null when
     * it found no face, undefined when it did not look (the camera delivered nothing new).
     */
    draw(
        context: CanvasRenderingContext2D,
        camera: Source | null,
        features: FaceFeatures | null | undefined,
        now: number,
    ): void;
};

export function createFacePicture(
    picture: Source,
    layout: FaceLayout,
): FacePicture {
    let backdrop: HTMLCanvasElement | null = null;
    let feather: CanvasGradient | null = null;
    let featherOwner: CanvasRenderingContext2D | null = null;

    let features: FaceFeatures | null = null;
    let frame: FaceFrame | null = null;
    let measuredAt = 0;
    let seenAt = -Infinity;
    let appearedAt = 0;

    /** The picture cropped to the canvas, kept so each frame is one plain copy. */
    const backdropFor = (canvas: Size): HTMLCanvasElement => {
        if (
            backdrop &&
            backdrop.width === canvas.width &&
            backdrop.height === canvas.height
        ) {
            return backdrop;
        }

        backdrop = document.createElement('canvas');
        backdrop.width = canvas.width;
        backdrop.height = canvas.height;

        const cover = coverPlacement(picture, canvas);
        const context = backdrop.getContext('2d');

        if (context) {
            context.imageSmoothingQuality = 'high';
            context.drawImage(
                picture.image,
                cover.x,
                cover.y,
                picture.width * cover.scale,
                picture.height * cover.scale,
            );
        }

        return backdrop;
    };

    /** Solid in the middle, fading to nothing at the rim of a circle of radius one. */
    const featherFor = (context: CanvasRenderingContext2D): CanvasGradient => {
        if (feather && featherOwner === context) {
            return feather;
        }

        feather = context.createRadialGradient(0, 0, 0, 0, 0, 1);
        feather.addColorStop(0, 'rgba(0, 0, 0, 1)');
        feather.addColorStop(0.55, 'rgba(0, 0, 0, 1)');
        feather.addColorStop(0.7, 'rgba(0, 0, 0, 0.8)');
        feather.addColorStop(0.85, 'rgba(0, 0, 0, 0.32)');
        feather.addColorStop(1, 'rgba(0, 0, 0, 0)');
        featherOwner = context;

        return feather;
    };

    const remember = (found: FaceFeatures | null, now: number) => {
        const measured = found ? faceFrame(found) : null;

        if (!found || !measured) {
            return;
        }

        if (now - seenAt > HOLD_MS + FADE_MS) {
            // A new appearance: nothing to smooth from, and it fades in.
            frame = null;
            appearedAt = now;
        }

        frame = steadyFrame(frame, measured, now - measuredAt);
        features = found;
        measuredAt = now;
        seenAt = now;
    };

    const draw: FacePicture['draw'] = (context, camera, found, now) => {
        const canvas = context.canvas;

        if (found !== undefined) {
            remember(found, now);
        }

        context.setTransform(1, 0, 0, 1, 0, 0);
        context.globalAlpha = 1;
        context.globalCompositeOperation = 'source-over';
        context.clearRect(0, 0, canvas.width, canvas.height);

        const fading = Math.max(0, now - seenAt - HOLD_MS);
        const visibility =
            Math.min(1, (now - appearedAt) / APPEAR_MS) *
            Math.max(0, 1 - fading / FADE_MS);

        if (camera && features && frame && visibility > 0) {
            const placement = facePlacement(
                frame,
                camera,
                picture,
                coverPlacement(picture, canvas),
                layout,
            );

            // 1. The shapes of the cut-outs, limited to where the camera picture will
            //    land so no shape is left without camera pixels behind it.
            context.save();
            context.beginPath();
            context.rect(
                placement.x,
                placement.y,
                camera.width * placement.scale,
                camera.height * placement.scale,
            );
            context.clip();
            context.globalAlpha = visibility;
            context.fillStyle = featherFor(context);

            for (const ellipse of cutouts(features, frame, placement)) {
                context.save();
                context.translate(ellipse.x, ellipse.y);
                context.rotate(ellipse.angle);
                context.scale(ellipse.rx, ellipse.ry);
                context.fillRect(-1, -1, 2, 2);
                context.restore();
            }

            context.restore();

            // 2. The camera picture, kept only inside those shapes.
            context.globalCompositeOperation = 'source-in';
            context.setTransform(
                placement.scale,
                0,
                0,
                placement.scale,
                placement.x,
                placement.y,
            );
            context.drawImage(camera.image, 0, 0, camera.width, camera.height);
            context.setTransform(1, 0, 0, 1, 0, 0);
        }

        // 3. The picture behind everything.
        context.globalCompositeOperation = 'destination-over';
        context.drawImage(backdropFor(canvas), 0, 0);
        context.globalCompositeOperation = 'source-over';
    };

    return { draw };
}
