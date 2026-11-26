// @vitest-environment node
import { describe, expect, it } from 'vitest';
import {
    centroid,
    coverPlacement,
    cutouts,
    faceFrame,
    facePlacement,
    steadyFrame,
} from '@/lib/meet/effects/geometry';
import { featuresFromLandmarks } from '@/lib/meet/effects/landmarks';
import type {
    FaceFeatures,
    FaceLayout,
    Point,
} from '@/lib/meet/effects/types';

/** An outline as a box around a centre: enough to stand in for an eye or a mouth. */
const box = (cx: number, cy: number, halfW: number, halfH: number): Point[] => [
    { x: cx - halfW, y: cy },
    { x: cx, y: cy - halfH },
    { x: cx + halfW, y: cy },
    { x: cx, y: cy + halfH },
];

/** A face looking straight at a 1280x720 camera: eyes 100 px apart, mouth 105 px below. */
const face = (dx = 0, dy = 0): FaceFeatures => ({
    leftEye: box(590 + dx, 300 + dy, 22, 9),
    rightEye: box(690 + dx, 300 + dy, 22, 9),
    mouth: box(640 + dx, 405 + dy, 40, 14),
});

const camera = { width: 1280, height: 720 };
const picture = { width: 1800, height: 1800 };
const layout: FaceLayout = {
    eyes: { x: 0.5, y: 0.4 },
    eyeDistance: 0.2,
    sway: 0.03,
};

describe('faceFrame', () => {
    it('measures a face by the point between the eyes, their distance and their tilt', () => {
        const frame = faceFrame(face());

        expect(frame?.origin).toEqual({ x: 640, y: 300 });
        expect(frame?.size).toBeCloseTo(100);
        expect(frame?.angle).toBeCloseTo(0);
    });

    it('keeps its size when the head turns and the eyes move closer together', () => {
        const turned: FaceFeatures = {
            leftEye: box(610, 300, 16, 9),
            rightEye: box(670, 300, 16, 9),
            mouth: box(640, 405, 34, 14),
        };

        // 60 px between the eyes, but the mouth is as far away as ever.
        expect(faceFrame(turned)?.size).toBeCloseTo(105 * 0.95);
    });

    it('reads the tilt of the head from the line through the eyes', () => {
        const tilted: FaceFeatures = {
            leftEye: box(600, 250, 20, 8),
            rightEye: box(680, 330, 20, 8),
            mouth: box(590, 380, 30, 12),
        };

        expect(faceFrame(tilted)?.angle).toBeCloseTo(Math.PI / 4);
    });

    it('gives up on a face too small to cut anything out of', () => {
        const speck: FaceFeatures = {
            leftEye: box(100, 100, 0.5, 0.2),
            rightEye: box(102, 100, 0.5, 0.2),
            mouth: box(101, 102, 1, 0.3),
        };

        expect(faceFrame(speck)).toBeNull();
    });
});

describe('steadyFrame', () => {
    const before = { origin: { x: 0, y: 0 }, angle: 0, size: 100 };
    const after = { origin: { x: 50, y: 10 }, angle: 0.2, size: 120 };

    it('takes the first measurement as it is', () => {
        expect(steadyFrame(null, after, 33)).toEqual(after);
    });

    it('follows the position at once and eases size and tilt', () => {
        const steady = steadyFrame(before, after, 33);

        expect(steady.origin).toEqual(after.origin);
        expect(steady.size).toBeGreaterThan(100);
        expect(steady.size).toBeLessThan(120);
        expect(steady.angle).toBeGreaterThan(0);
        expect(steady.angle).toBeLessThan(0.2);
    });

    it('has caught up after a long gap', () => {
        const steady = steadyFrame(before, after, 2000);

        expect(steady.size).toBeCloseTo(120);
        expect(steady.angle).toBeCloseTo(0.2);
    });

    it('turns the short way round', () => {
        const steady = steadyFrame(
            { ...before, angle: 3.1 },
            { ...after, angle: -3.1 },
            33,
        );

        // From 3.1 towards -3.1 through pi, not back through zero.
        expect(steady.angle).toBeGreaterThan(3.1);
    });
});

describe('coverPlacement', () => {
    it('fills a wide canvas with a square picture and crops top and bottom evenly', () => {
        const cover = coverPlacement(picture, camera);

        expect(cover.scale).toBeCloseTo(1280 / 1800);
        expect(cover.x).toBeCloseTo(0);
        expect(cover.y).toBeCloseTo((720 - 1280) / 2);
    });

    it('fills a tall canvas and crops the sides', () => {
        const cover = coverPlacement(picture, { width: 720, height: 1280 });

        expect(cover.scale).toBeCloseTo(1280 / 1800);
        expect(cover.x).toBeCloseTo((720 - 1280) / 2);
        expect(cover.y).toBeCloseTo(0);
    });
});

describe('facePlacement', () => {
    const cover = coverPlacement(picture, camera);
    const place = (features: FaceFeatures) => {
        const frame = faceFrame(features);

        if (!frame) {
            throw new Error('expected a face');
        }

        return { frame, placement: facePlacement(frame, camera, picture, cover, layout) };
    };
    const onCanvas = (
        point: Point,
        placement: { scale: number; x: number; y: number },
    ) => ({
        x: point.x * placement.scale + placement.x,
        y: point.y * placement.scale + placement.y,
    });

    it('puts the point between the eyes where the layout wants it', () => {
        // Eyes in the very middle of the camera picture: a centred head does not sway.
        const { frame, placement } = place(face(0, 60));
        const eyes = onCanvas(frame.origin, placement);

        // Exactly the layout's point on the picture as it is cropped to the canvas.
        expect(eyes.x).toBeCloseTo(0.5 * 1800 * cover.scale + cover.x);
        expect(eyes.y).toBeCloseTo(0.4 * 1800 * cover.scale + cover.y);
    });

    it('scales the face so the eyes are the layout’s distance apart', () => {
        const { placement } = place(face());

        // 100 camera pixels become a fifth of the picture's width as shown.
        expect(100 * placement.scale).toBeCloseTo(0.2 * 1800 * cover.scale);
    });

    it('shows the face the same size however close someone sits', () => {
        const near: FaceFeatures = {
            leftEye: box(540, 300, 44, 18),
            rightEye: box(740, 300, 44, 18),
            mouth: box(640, 510, 80, 28),
        };
        const far = place(face());
        const close = place(near);

        expect(200 * close.placement.scale).toBeCloseTo(
            100 * far.placement.scale,
        );
    });

    it('lets the face drift a little with the head, and no further than the sway', () => {
        const centred = place(face());
        const right = place(face(400, 0));
        const beyond = place(face(5000, 0));
        const x = (placed: ReturnType<typeof place>) =>
            onCanvas(placed.frame.origin, placed.placement).x;
        const limit = 0.03 * 1800 * cover.scale;

        expect(x(right)).toBeGreaterThan(x(centred));
        expect(x(right) - x(centred)).toBeLessThan(limit);
        expect(x(beyond) - x(centred)).toBeCloseTo(limit);
    });
});

describe('cutouts', () => {
    const frame = { origin: { x: 640, y: 300 }, angle: 0, size: 100 };
    const identity = { scale: 1, x: 0, y: 0 };

    it('makes two eyes and a mouth, each centred on its outline', () => {
        const [left, right, mouth] = cutouts(face(), frame, identity);

        expect(left).toMatchObject({ x: 590, y: 300 });
        expect(right).toMatchObject({ x: 690, y: 300 });
        expect(mouth).toMatchObject({ x: 640, y: 405 });
    });

    it('leaves room around each outline for the soft edge', () => {
        const [left, , mouth] = cutouts(face(), frame, identity);

        expect(left.rx).toBeGreaterThan(22);
        expect(left.ry).toBeGreaterThan(9);
        expect(mouth.rx).toBeGreaterThan(40);
        expect(mouth.ry).toBeGreaterThan(14);
    });

    it('keeps the eyes from touching each other and the mouth', () => {
        const [left, right, mouth] = cutouts(face(), frame, identity);

        expect(left.x + left.rx).toBeLessThan(right.x - right.rx);
        expect(left.y + left.ry).toBeLessThan(mouth.y - mouth.ry);
    });

    it('does not collapse when an eye closes', () => {
        const blinking: FaceFeatures = {
            ...face(),
            leftEye: box(590, 300, 22, 0.5),
        };
        const [left] = cutouts(blinking, frame, identity);

        expect(left.ry).toBeGreaterThan(left.rx * 0.5);
    });

    it('grows with an opening mouth', () => {
        const open: FaceFeatures = { ...face(), mouth: box(640, 420, 36, 34) };
        const [, , closedMouth] = cutouts(face(), frame, identity);
        const [, , openMouth] = cutouts(open, frame, identity);

        expect(openMouth.ry).toBeGreaterThan(closedMouth.ry * 1.5);
    });

    it('moves and scales with the placement', () => {
        const [left] = cutouts(face(), frame, identity);
        const [moved] = cutouts(face(), frame, { scale: 2, x: 10, y: -20 });

        expect(moved.x).toBeCloseTo(left.x * 2 + 10);
        expect(moved.y).toBeCloseTo(left.y * 2 - 20);
        expect(moved.rx).toBeCloseTo(left.rx * 2);
        expect(moved.ry).toBeCloseTo(left.ry * 2);
    });

    it('lies along a tilted head', () => {
        const angle = Math.PI / 6;
        const rotate = (point: Point): Point => ({
            x: 640 + (point.x - 640) * Math.cos(angle) - (point.y - 300) * Math.sin(angle),
            y: 300 + (point.x - 640) * Math.sin(angle) + (point.y - 300) * Math.cos(angle),
        });
        const upright = face();
        const tilted: FaceFeatures = {
            leftEye: upright.leftEye.map(rotate),
            rightEye: upright.rightEye.map(rotate),
            mouth: upright.mouth.map(rotate),
        };
        const tiltedFrame = faceFrame(tilted);

        if (!tiltedFrame) {
            throw new Error('expected a face');
        }

        const [straight] = cutouts(upright, frame, identity);
        const [leaning] = cutouts(tilted, tiltedFrame, identity);

        expect(leaning.angle).toBeCloseTo(angle);
        // The same eye, the same cut-out, only turned.
        expect(leaning.rx).toBeCloseTo(straight.rx);
        expect(leaning.ry).toBeCloseTo(straight.ry);
    });
});

describe('featuresFromLandmarks', () => {
    it('turns mesh points into camera pixels', () => {
        const mesh = Array.from({ length: 478 }, (_, index) => ({
            x: index / 1000,
            y: 0.5,
        }));
        const features = featuresFromLandmarks(mesh, camera);

        // Point 33 is the outer corner of the eye on the left of the picture.
        expect(features?.leftEye[0]).toEqual({ x: 0.033 * 1280, y: 360 });
        expect(features?.rightEye[0].x).toBeCloseTo(0.362 * 1280);
        expect(features?.mouth[0].x).toBeCloseTo(0.061 * 1280);
        expect(features?.leftEye).toHaveLength(16);
        expect(features?.rightEye).toHaveLength(16);
        expect(features?.mouth).toHaveLength(20);
    });

    it('has nothing to offer without a face', () => {
        expect(featuresFromLandmarks(undefined, camera)).toBeNull();
        expect(featuresFromLandmarks([], camera)).toBeNull();
        expect(featuresFromLandmarks([{ x: 0.5, y: 0.5 }], camera)).toBeNull();
    });
});

describe('centroid', () => {
    it('is the average of the points, and the origin for none', () => {
        expect(centroid(box(10, 20, 4, 2))).toEqual({ x: 10, y: 20 });
        expect(centroid([])).toEqual({ x: 0, y: 0 });
    });
});
