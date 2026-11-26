// @vitest-environment node
import { describe, expect, it } from 'vitest';

/**
 * The meeting page is rendered by the Inertia SSR server as well. Nothing in these modules
 * may touch the browser (or pull in the media SDK) just by being imported.
 */
describe('meeting room modules on the server', () => {
    it('import without a window, document or navigator', async () => {
        expect(typeof window).toBe('undefined');

        const modules = await Promise.all([
            import('@/lib/meet/media'),
            import('@/lib/meet/preferences'),
            import('@/lib/meet/mediaErrors'),
            import('@/lib/meet/messages'),
            import('@/lib/meet/quality'),
            import('@/lib/meet/layout'),
            import('@/lib/meet/linkify'),
            import('@/lib/meet/format'),
            import('@/lib/meet/upload'),
            import('@/lib/meet/volumes'),
            import('@/lib/meet/pip'),
            import('@/composables/meet/useAudioLevel'),
            import('@/composables/meet/useDevices'),
            import('@/composables/meet/usePreview'),
            import('@/composables/meet/useMeetPreferences'),
            import('@/composables/meet/useParticipantVolumes'),
            import('@/composables/meet/usePictureInPicture'),
        ]);

        expect(modules).toHaveLength(17);
    });

    it('reads preferences as defaults when there is no localStorage', async () => {
        const { loadPreferences, DEFAULT_PREFERENCES, savePreferences } =
            await import('@/lib/meet/preferences');

        expect(loadPreferences()).toEqual(DEFAULT_PREFERENCES);
        expect(() => savePreferences(DEFAULT_PREFERENCES)).not.toThrow();
    });

    it('keeps everyone at normal volume when there is no sessionStorage', async () => {
        const { loadVolumes, saveVolumes, canAdjustVolume } =
            await import('@/lib/meet/volumes');

        expect(loadVolumes('call')).toEqual({});
        expect(() => saveVolumes('call', { p_a: 0.5 })).not.toThrow();
        expect(canAdjustVolume()).toBe(true);
    });

    it('offers no picture-in-picture without a browser', async () => {
        const { pipSupport, documentPictureInPicture } =
            await import('@/lib/meet/pip');

        expect(pipSupport()).toBeNull();
        expect(documentPictureInPicture()).toBeNull();
    });

    it('explains a missing media API instead of throwing', async () => {
        const { explainMediaError } = await import('@/lib/meet/mediaErrors');

        expect(
            explainMediaError(new TypeError('no mediaDevices'), 'microphone')
                .kind,
        ).toBe('unsupported');
    });
});
