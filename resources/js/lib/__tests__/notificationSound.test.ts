import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    NOTIFICATION_SOUND_URL,
    playNotificationSound,
    resetNotificationSound,
} from '@/lib/notificationSound';

const play = vi.fn();
const created: string[] = [];

class FakeAudio {
    currentTime = 5;

    constructor(src: string) {
        created.push(src);
    }

    play = play;
}

describe('playNotificationSound', () => {
    beforeEach(() => {
        vi.stubGlobal('Audio', FakeAudio);
        play.mockReset().mockResolvedValue(undefined);
        created.length = 0;
        resetNotificationSound();
    });

    afterEach(() => vi.unstubAllGlobals());

    it('plays the chime once per burst and reuses one element', () => {
        expect(playNotificationSound(1000)).toBe(true);
        expect(playNotificationSound(1500)).toBe(false);
        expect(playNotificationSound(2600)).toBe(true);

        expect(play).toHaveBeenCalledTimes(2);
        expect(created).toEqual([NOTIFICATION_SOUND_URL]);
    });

    it('swallows a refused play when the browser blocks autoplay', async () => {
        play.mockRejectedValue(new DOMException('blocked', 'NotAllowedError'));

        expect(playNotificationSound(1000)).toBe(true);
        await expect(Promise.resolve()).resolves.toBeUndefined();
    });
});
