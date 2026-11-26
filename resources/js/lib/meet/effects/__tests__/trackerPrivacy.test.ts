// @vitest-environment node
import tracker from '@mediapipe/tasks-vision?raw';
import { describe, expect, it } from 'vitest';

/**
 * The face tracker's package reports usage metrics to Google unless the build takes the
 * address out (`withoutMediapipeMetrics` in vite.config.ts). That only works while the
 * address below is the only one the package can reach. A new version that reports somewhere
 * else, or to a second place, has to fail here before it ships.
 */
describe('the face tracker package', () => {
    it('can reach exactly one address, the one the build removes', () => {
        const addresses = new Set(tracker.match(/https?:\/\/[^\s"'`\\)]+/g));

        expect([...addresses]).toEqual([
            'https://odml.pa.googleapis.com/v1/log',
        ]);
    });
});
