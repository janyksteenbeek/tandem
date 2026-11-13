import { describe, expect, it } from 'vitest';
import {
    DEFAULT_PREFERENCES,
    deviceOptions,
    parsePreferences,
    resolveDevice,
} from '@/lib/meet/preferences';

describe('resolveDevice', () => {
    const devices = [
        { id: 'default', label: 'Default' },
        { id: 'usb', label: 'USB microphone' },
    ];

    it('keeps the remembered device while it is plugged in', () => {
        expect(resolveDevice('usb', devices)).toBe('usb');
    });

    it('falls back to the system default when the remembered device is gone', () => {
        expect(resolveDevice('unplugged', devices)).toBe('default');
        expect(resolveDevice(null, devices)).toBe('default');
    });

    it('falls back to the first device when there is no entry called default', () => {
        expect(
            resolveDevice('unplugged', [{ id: 'cam-1', label: 'Camera' }]),
        ).toBe('cam-1');
    });

    it('has nothing to offer without devices', () => {
        expect(resolveDevice('usb', [])).toBeNull();
    });
});

describe('deviceOptions', () => {
    it('filters by kind, drops duplicates and unnamed placeholder entries', () => {
        const options = deviceOptions(
            [
                { deviceId: 'a', kind: 'audioinput', label: 'Built-in' },
                { deviceId: 'a', kind: 'audioinput', label: 'Built-in' },
                { deviceId: '', kind: 'audioinput', label: '' },
                { deviceId: 'c', kind: 'videoinput', label: 'FaceTime' },
            ],
            'audioinput',
        );

        expect(options).toEqual([{ id: 'a', label: 'Built-in' }]);
    });

    it('names devices whose label is hidden until permission is granted', () => {
        const options = deviceOptions(
            [
                { deviceId: 'x', kind: 'videoinput', label: '' },
                { deviceId: 'y', kind: 'videoinput', label: '' },
            ],
            'videoinput',
        );

        expect(options.map((option) => option.label)).toEqual([
            'Camera 1',
            'Camera 2',
        ]);
    });
});

describe('parsePreferences', () => {
    it('returns the defaults for nothing, garbage or the wrong shape', () => {
        expect(parsePreferences(null)).toEqual(DEFAULT_PREFERENCES);
        expect(parsePreferences('{not json')).toEqual(DEFAULT_PREFERENCES);
        expect(parsePreferences('"a string"')).toEqual(DEFAULT_PREFERENCES);
    });

    it('keeps valid values and replaces invalid ones field by field', () => {
        const parsed = parsePreferences(
            JSON.stringify({
                audioInputId: 'usb',
                videoInputId: 42,
                micEnabled: false,
                cameraEnabled: 'yes',
                videoQuality: 'ultra',
                displayName: 'x'.repeat(200),
            }),
        );

        expect(parsed).toMatchObject({
            audioInputId: 'usb',
            videoInputId: null,
            micEnabled: false,
            cameraEnabled: true,
            videoQuality: 'auto',
        });
        expect(parsed.displayName).toHaveLength(80);
    });
});
