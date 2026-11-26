// @vitest-environment node
import { describe, expect, it } from 'vitest';
import {
    audioCaptureOptions,
    cameraProfile,
    microphonePublishOptions,
    screenCaptureLimits,
    screenShareCaptureOptions,
    screenShareEncoding,
    screenSharePublishOptions,
    videoCaptureOptions,
    VOICE_BITRATE,
} from '@/lib/meet/media';

describe('microphone', () => {
    it('always uses the browser’s echo cancellation and gain control', () => {
        expect(
            audioCaptureOptions({
                audioInputId: 'usb',
                noiseSuppression: false,
            }),
        ).toEqual({
            deviceId: 'usb',
            echoCancellation: true,
            autoGainControl: true,
            noiseSuppression: false,
        });
    });

    it('publishes fullband speech without silence gaps and with redundancy', () => {
        expect(microphonePublishOptions()).toEqual({
            audioPreset: { maxBitrate: VOICE_BITRATE },
            dtx: false,
            red: true,
        });
        expect(VOICE_BITRATE).toBeGreaterThanOrEqual(32_000);
    });
});

describe('cameraProfile', () => {
    it('defaults to 720p and offers 1080p only under High', () => {
        expect(cameraProfile('auto')).toMatchObject({
            width: 1280,
            height: 720,
        });
        expect(cameraProfile('high')).toMatchObject({
            width: 1920,
            height: 1080,
        });
        expect(cameraProfile('standard').encoding.maxBitrate).toBeLessThan(
            cameraProfile('auto').encoding.maxBitrate as number,
        );
    });

    it('always has smaller simulcast layers to fall back to', () => {
        for (const quality of ['auto', 'standard', 'high'] as const) {
            expect(cameraProfile(quality).layers.length).toBe(2);
        }
    });
});

describe('videoCaptureOptions', () => {
    const preferences = { videoInputId: 'cam', videoQuality: 'auto' } as const;

    it('opens the chosen camera at the profile of the quality setting', () => {
        expect(videoCaptureOptions(preferences)).toEqual({
            deviceId: 'cam',
            resolution: { width: 1280, height: 720, frameRate: 30 },
        });
    });

    it('carries a camera effect, so it is on the track before anything is sent', () => {
        const effect = {
            name: 'effect',
            init: () => Promise.resolve(),
            restart: () => Promise.resolve(),
            destroy: () => Promise.resolve(),
        };

        expect(videoCaptureOptions(preferences, effect).processor).toBe(effect);
    });
});

describe('screen share', () => {
    it('favours sharpness for text: 15 fps, resolution kept under pressure', () => {
        expect(screenShareEncoding(1920, 1080, false)).toMatchObject({
            maxFramerate: 15,
            maxBitrate: 2_500_000,
        });
        expect(screenSharePublishOptions(1920, 1080, false)).toMatchObject({
            videoCodec: 'vp9',
            scalabilityMode: 'L1T3',
            simulcast: false,
            degradationPreference: 'maintain-resolution',
            backupCodec: { codec: 'vp8' },
        });
    });

    it('gives larger screens more bitrate instead of scaling them down', () => {
        const hd = screenShareEncoding(1920, 1080, false).maxBitrate as number;
        const qhd = screenShareEncoding(2560, 1440, false).maxBitrate as number;
        const uhd = screenShareEncoding(3840, 2160, false).maxBitrate as number;

        expect(qhd).toBeGreaterThan(hd);
        expect(uhd).toBeGreaterThan(qhd);
    });

    it('doubles the frame rate for motion and pays for it in bitrate', () => {
        const motion = screenShareEncoding(1920, 1080, true);

        expect(motion.maxFramerate).toBe(30);
        expect(motion.maxBitrate).toBeGreaterThan(2_500_000);
        expect(screenShareCaptureOptions(true).contentHint).toBe('motion');
        expect(screenShareCaptureOptions(false).contentHint).toBe('detail');
    });

    it('tells the SDK not to cap capture at 1080p and passes maxima instead', () => {
        const options = screenShareCaptureOptions(false, {
            width: 2880,
            height: 1800,
        });

        expect(options.resolution).toMatchObject({ width: 0, height: 0 });
        expect(options.video).toEqual({
            width: { max: 2880 },
            height: { max: 1800 },
            frameRate: { max: 30 },
        });
    });
});

describe('screenCaptureLimits', () => {
    it('is the display’s physical resolution', () => {
        expect(screenCaptureLimits(1440, 900, 2)).toEqual({
            width: 2880,
            height: 1800,
        });
        expect(screenCaptureLimits(2560, 1440, 1)).toEqual({
            width: 2560,
            height: 1440,
        });
    });

    it('never goes below Full HD or above 4K', () => {
        expect(screenCaptureLimits(1280, 720, 1)).toEqual({
            width: 1920,
            height: 1080,
        });
        expect(screenCaptureLimits(5120, 2880, 2)).toEqual({
            width: 3840,
            height: 2160,
        });
    });

    it('works for a portrait display', () => {
        expect(screenCaptureLimits(1440, 2560, 1)).toEqual({
            width: 2560,
            height: 1440,
        });
    });
});
