// @vitest-environment node
import { describe, expect, it } from 'vitest';

/**
 * The pre-join screen and the settings are rendered on the server too, and both list the
 * effects. Importing the effect modules may not touch the browser, and may not pull in the
 * face tracker: that is only ever loaded by someone who switches an effect on.
 */
describe('camera effect modules on the server', () => {
    it('import without a window, document or navigator', async () => {
        expect(typeof window).toBe('undefined');

        const modules = await Promise.all([
            import('@/lib/meet/effects/catalog'),
            import('@/lib/meet/effects/geometry'),
            import('@/lib/meet/effects/landmarks'),
            import('@/lib/meet/effects/facePicture'),
            import('@/lib/meet/effects/ticker'),
            import('@/lib/meet/effects/pipeline'),
            import('@/lib/meet/effects/processor'),
        ]);

        expect(modules).toHaveLength(7);
    });

    it('lists every effect with a picture, and knows which choices exist', async () => {
        const { VIDEO_EFFECTS, effectDefinition, isVideoEffect } =
            await import('@/lib/meet/effects/catalog');

        expect(VIDEO_EFFECTS.map((effect) => effect.id)).toEqual(['egg']);
        expect(effectDefinition('egg').picture).toBeTruthy();
        expect(effectDefinition('egg').thumbnail).toBeTruthy();
        expect(isVideoEffect('none')).toBe(true);
        expect(isVideoEffect('egg')).toBe(true);
        expect(isVideoEffect('bacon')).toBe(false);
    });
});
