import type {
    AudioCaptureOptions,
    RoomOptions,
    ScreenShareCaptureOptions,
    Track,
    TrackProcessor,
    TrackPublishOptions,
    VideoCaptureOptions,
    VideoEncoding,
} from 'livekit-client';
import type { DevicePreferences, VideoQualitySetting } from './types';

type LiveKit = typeof import('livekit-client');

/**
 * Every media decision of the meeting room in one place.
 *
 * The order of priorities is audio, reliability, screen sharing, camera. So: the voice gets
 * a fixed, generous bitrate that nothing ever lowers; the camera is published in layers the
 * server can drop one by one; a shared screen keeps its resolution and gives up frame rate
 * first.
 */

/**
 * 48 kbit/s mono Opus is fullband and transparent for speech. The SDK's "speech" preset
 * (24 kbit/s) is audibly band-limited next to it, and the "high quality music" presets
 * (96-128 kbit/s) spend bandwidth on nothing a voice contains. With redundancy (RED) the
 * wire rate roughly doubles, which is still a fraction of one camera layer.
 */
export const VOICE_BITRATE = 48_000;

/** Tab or system audio shared with a screen is often music or video: stereo, more bits. */
export const SCREEN_AUDIO_BITRATE = 96_000;

export function audioCaptureOptions(
    preferences: Pick<DevicePreferences, 'audioInputId' | 'noiseSuppression'>,
): AudioCaptureOptions {
    return {
        deviceId: preferences.audioInputId ?? undefined,
        // All three are the browser's own processing: no extra CPU on the server and no
        // third-party model in the path.
        echoCancellation: true,
        autoGainControl: true,
        noiseSuppression: preferences.noiseSuppression,
    };
}

export function microphonePublishOptions(): TrackPublishOptions {
    return {
        audioPreset: { maxBitrate: VOICE_BITRATE },
        // Discontinuous transmission saves a little bandwidth in silences but leaves gaps
        // in the recorded track; the recorder wants a continuous stream.
        dtx: false,
        // Redundant audio: each packet also carries the previous one, so isolated packet
        // loss is repaired without anyone hearing it.
        red: true,
    };
}

type CameraProfile = {
    width: number;
    height: number;
    frameRate: number;
    encoding: VideoEncoding;
    /** Names of the SDK presets used for the lower simulcast layers. */
    layers: ('h180' | 'h360' | 'h720')[];
};

/**
 * Auto and Standard send 720p and 540p; High asks for 1080p where the camera has it. Each
 * comes with two smaller simulcast layers, so the server can give a thumbnail-sized tile (or
 * a struggling connection) a small stream without anyone re-encoding anything.
 */
export function cameraProfile(quality: VideoQualitySetting): CameraProfile {
    switch (quality) {
        case 'high':
            return {
                width: 1920,
                height: 1080,
                frameRate: 30,
                encoding: { maxBitrate: 3_000_000, maxFramerate: 30 },
                layers: ['h360', 'h720'],
            };
        case 'standard':
            return {
                width: 960,
                height: 540,
                frameRate: 25,
                encoding: { maxBitrate: 800_000, maxFramerate: 25 },
                layers: ['h180', 'h360'],
            };
        default:
            return {
                width: 1280,
                height: 720,
                frameRate: 30,
                encoding: { maxBitrate: 1_700_000, maxFramerate: 30 },
                layers: ['h180', 'h360'],
            };
    }
}

/**
 * A camera effect is handed over with the capture options, so the SDK puts it on the track
 * before the track is published: the camera picture as it is never goes out, not even for
 * the first frames.
 */
export function videoCaptureOptions(
    preferences: Pick<DevicePreferences, 'videoInputId' | 'videoQuality'>,
    effect?: TrackProcessor<Track.Kind.Video>,
): VideoCaptureOptions {
    const profile = cameraProfile(preferences.videoQuality);

    return {
        deviceId: preferences.videoInputId ?? undefined,
        resolution: {
            width: profile.width,
            height: profile.height,
            frameRate: profile.frameRate,
        },
        ...(effect ? { processor: effect } : {}),
    };
}

export function cameraPublishOptions(
    lk: LiveKit,
    quality: VideoQualitySetting,
): TrackPublishOptions {
    const profile = cameraProfile(quality);

    return {
        // VP8: every browser encodes and decodes it, usually in hardware, and its simulcast
        // layers cost the server nothing but forwarding.
        videoCodec: 'vp8',
        simulcast: true,
        videoEncoding: profile.encoding,
        videoSimulcastLayers: profile.layers.map(
            (name) => lk.VideoPresets[name],
        ),
        // When the uplink is short the camera gives up resolution and frame rate together;
        // the voice is never part of that trade.
        degradationPreference: 'balanced',
    };
}

/**
 * Bitrate and frame rate for a shared screen of the given size. Text and code are static
 * most of the time, so 15 frames per second at a bitrate that keeps small glyphs sharp
 * beats 30 blurry ones; "motion" doubles the frame rate and pays for it.
 */
export function screenShareEncoding(
    width: number,
    height: number,
    motion: boolean,
): VideoEncoding {
    const pixels = Math.max(1, width * height);
    const base =
        pixels <= 1920 * 1080
            ? 2_500_000
            : pixels <= 2560 * 1440
              ? 4_000_000
              : 6_000_000;

    return {
        maxBitrate: Math.round(base * (motion ? 1.6 : 1)),
        maxFramerate: motion ? 30 : 15,
        priority: 'medium',
    };
}

export type CaptureLimits = { width: number; height: number };

/**
 * The largest picture worth capturing from this display. Browsers render a captured tab at
 * whatever maximum they are given, so asking for 4K on a 1080p laptop would spend the
 * encoder on pixels that carry nothing. The limit is therefore the display's own physical
 * resolution, never below Full HD and never above 4K: a 4K monitor is shared at 4K, a
 * Retina laptop at its native 2880 wide, and nothing is ever scaled down to get there.
 */
export function screenCaptureLimits(
    screenWidth: number,
    screenHeight: number,
    devicePixelRatio: number,
): CaptureLimits {
    const ratio = devicePixelRatio > 0 ? devicePixelRatio : 1;
    const long = Math.round(Math.max(screenWidth, screenHeight) * ratio);
    const short = Math.round(Math.min(screenWidth, screenHeight) * ratio);

    return {
        width: Math.min(3840, Math.max(1920, long)),
        height: Math.min(2160, Math.max(1080, short)),
    };
}

export function screenShareCaptureOptions(
    motion: boolean,
    limits: CaptureLimits = { width: 3840, height: 2160 },
): ScreenShareCaptureOptions {
    // Always capture at up to 30 frames per second. How many of them are sent is decided
    // by the encoder (15 for text, 30 for motion), which lets the mode be switched during
    // a share without touching the capture.
    const frameRate = 30;

    return {
        // Offer tab/system audio wherever the browser's picker supports it.
        audio: {
            echoCancellation: false,
            noiseSuppression: false,
            autoGainControl: false,
        },
        systemAudio: 'include',
        selfBrowserSurface: 'include',
        surfaceSwitching: 'include',
        suppressLocalAudioPlayback: false,
        contentHint: motion ? 'motion' : 'detail',
        // Zero means "do not let the SDK cap this at 1080p". The limits below are maxima,
        // so a screen is captured at its own resolution and never scaled down.
        resolution: { width: 0, height: 0, frameRate },
        video: {
            width: { max: limits.width },
            height: { max: limits.height },
            frameRate: { max: frameRate },
        } as unknown as ScreenShareCaptureOptions['video'],
    };
}

export function screenSharePublishOptions(
    width: number,
    height: number,
    motion: boolean,
): TrackPublishOptions {
    const encoding = screenShareEncoding(width, height, motion);

    return {
        // VP9 keeps small text legible at a much lower bitrate than VP8. One caveat the SDK
        // handles for us: Chrome's dedicated screen-content path limits VP9 to 5 frames
        // per second, so for VP9 the SDK sets the content hint to "motion" and sharpness
        // is protected by `maintain-resolution` below instead. Browsers that publish VP8
        // (Firefox) keep the "detail" hint from the capture options.
        videoCodec: 'vp9',
        // One spatial layer, three temporal layers. There is deliberately no downscaled
        // layer: a viewer on a weak connection gets fewer frames of the same sharp picture
        // instead of a blurry one, which is the right trade for code and documents.
        scalabilityMode: 'L1T3',
        simulcast: false,
        screenShareEncoding: encoding,
        videoEncoding: encoding,
        // Browsers that cannot decode VP9 receive a VP8 copy, published only on demand.
        backupCodec: { codec: 'vp8', encoding },
        degradationPreference: motion ? 'balanced' : 'maintain-resolution',
    };
}

export function screenAudioPublishOptions(): TrackPublishOptions {
    return {
        audioPreset: { maxBitrate: SCREEN_AUDIO_BITRATE },
        dtx: false,
        red: false,
    };
}

export function roomOptions(
    lk: LiveKit,
    preferences: DevicePreferences,
): RoomOptions {
    return {
        // Only ask the server for video that is actually on screen, at the size it is
        // shown; hidden tiles cost nothing. Screen shares follow the display's pixel
        // density so they stay sharp on high-DPI monitors.
        adaptiveStream: { pixelDensity: 'screen' },
        // Stop encoding simulcast layers nobody is watching.
        dynacast: true,
        disconnectOnPageLeave: true,
        stopLocalTrackOnUnpublish: true,
        audioCaptureDefaults: audioCaptureOptions(preferences),
        videoCaptureDefaults: videoCaptureOptions(preferences),
        audioOutput: preferences.audioOutputId
            ? { deviceId: preferences.audioOutputId }
            : undefined,
        publishDefaults: {
            ...cameraPublishOptions(lk, preferences.videoQuality),
            ...microphonePublishOptions(),
            // Keep the microphone open while muted so unmuting is instant and the device
            // does not have to be re-acquired.
            stopMicTrackOnMute: false,
        },
    };
}
