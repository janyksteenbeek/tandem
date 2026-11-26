import type { FaceFeatures, Point, Size } from './types';

/**
 * Which points of the face mesh outline the parts an effect cuts out. The numbers are
 * positions in the 478-point mesh the face tracker returns; they are the same for everyone.
 */

/** The person's right eye, which is on the left of an unmirrored camera picture. */
const PICTURE_LEFT_EYE = [
    33, 7, 163, 144, 145, 153, 154, 155, 133, 173, 157, 158, 159, 160, 161, 246,
];

const PICTURE_RIGHT_EYE = [
    362, 382, 381, 380, 374, 373, 390, 249, 263, 466, 388, 387, 386, 385, 384,
    398,
];

/** The outer edge of the lips. */
const MOUTH = [
    61, 146, 91, 181, 84, 17, 314, 405, 321, 375, 291, 409, 270, 269, 267, 0,
    37, 39, 40, 185,
];

/** The mesh without the iris points still has this many; fewer is not a face mesh. */
const MESH_POINTS = 468;

/**
 * Pick the eyes and the mouth out of a face mesh. Mesh points are fractions of the camera
 * picture; the result is in camera pixels.
 */
export function featuresFromLandmarks(
    landmarks: readonly Point[] | null | undefined,
    video: Size,
): FaceFeatures | null {
    if (!landmarks || landmarks.length < MESH_POINTS) {
        return null;
    }

    const outline = (indices: number[]): Point[] =>
        indices.map((index) => ({
            x: landmarks[index].x * video.width,
            y: landmarks[index].y * video.height,
        }));

    return {
        leftEye: outline(PICTURE_LEFT_EYE),
        rightEye: outline(PICTURE_RIGHT_EYE),
        mouth: outline(MOUTH),
    };
}
