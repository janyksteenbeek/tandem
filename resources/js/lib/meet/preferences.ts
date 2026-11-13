import type { DeviceOption, DevicePreferences } from './types';

const STORAGE_KEY = 'tandem:meet:preferences';

export const DEFAULT_PREFERENCES: DevicePreferences = {
    audioInputId: null,
    videoInputId: null,
    audioOutputId: null,
    micEnabled: true,
    cameraEnabled: true,
    noiseSuppression: true,
    videoQuality: 'auto',
    screenShareMotion: false,
    displayName: '',
};

const storage = (): Storage | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        return window.localStorage;
    } catch {
        return null;
    }
};

/** Read what was remembered, ignoring anything that is not the shape we expect. */
export function parsePreferences(raw: string | null): DevicePreferences {
    if (!raw) {
        return { ...DEFAULT_PREFERENCES };
    }

    try {
        const saved = JSON.parse(raw) as Record<string, unknown>;
        const text = (value: unknown): string | null =>
            typeof value === 'string' && value !== '' ? value : null;
        const flag = (value: unknown, fallback: boolean): boolean =>
            typeof value === 'boolean' ? value : fallback;

        return {
            audioInputId: text(saved.audioInputId),
            videoInputId: text(saved.videoInputId),
            audioOutputId: text(saved.audioOutputId),
            micEnabled: flag(saved.micEnabled, DEFAULT_PREFERENCES.micEnabled),
            cameraEnabled: flag(
                saved.cameraEnabled,
                DEFAULT_PREFERENCES.cameraEnabled,
            ),
            noiseSuppression: flag(
                saved.noiseSuppression,
                DEFAULT_PREFERENCES.noiseSuppression,
            ),
            videoQuality:
                saved.videoQuality === 'standard' ||
                saved.videoQuality === 'high'
                    ? saved.videoQuality
                    : 'auto',
            screenShareMotion: flag(saved.screenShareMotion, false),
            displayName: (text(saved.displayName) ?? '').slice(0, 80),
        };
    } catch {
        return { ...DEFAULT_PREFERENCES };
    }
}

export function loadPreferences(): DevicePreferences {
    return parsePreferences(storage()?.getItem(STORAGE_KEY) ?? null);
}

export function savePreferences(preferences: DevicePreferences): void {
    try {
        storage()?.setItem(STORAGE_KEY, JSON.stringify(preferences));
    } catch {
        // Private mode or a full disk: preferences simply are not remembered.
    }
}

/**
 * The device to use: the remembered one while it is still plugged in, otherwise the
 * system default (which browsers list first), otherwise nothing.
 */
export function resolveDevice(
    preferredId: string | null,
    devices: DeviceOption[],
): string | null {
    if (devices.length === 0) {
        return null;
    }

    if (preferredId && devices.some((device) => device.id === preferredId)) {
        return preferredId;
    }

    return (
        devices.find((device) => device.id === 'default')?.id ?? devices[0].id
    );
}

/**
 * Turn the browser's device list into options for one kind, with readable fallbacks for
 * devices whose label is hidden until permission is granted.
 */
export function deviceOptions(
    devices: Pick<MediaDeviceInfo, 'deviceId' | 'kind' | 'label'>[],
    kind: MediaDeviceKind,
): DeviceOption[] {
    const names: Record<MediaDeviceKind, string> = {
        audioinput: 'Microphone',
        videoinput: 'Camera',
        audiooutput: 'Speaker',
    };

    const seen = new Set<string>();

    return devices
        .filter((device) => device.kind === kind && device.deviceId !== '')
        .filter((device) => {
            if (seen.has(device.deviceId)) {
                return false;
            }

            seen.add(device.deviceId);

            return true;
        })
        .map((device, index) => ({
            id: device.deviceId,
            label: device.label || `${names[kind]} ${index + 1}`,
        }));
}
