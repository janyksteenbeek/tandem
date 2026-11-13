import { describe, expect, it } from 'vitest';
import {
    bitrateKbps,
    lossPercent,
    qualityBars,
    qualityFromLiveKit,
    qualityFromStats,
    worstQuality,
} from '@/lib/meet/quality';

describe('qualityFromLiveKit', () => {
    it('maps the media server’s words onto the three-step indicator', () => {
        expect(qualityFromLiveKit('excellent')).toBe('good');
        expect(qualityFromLiveKit('good')).toBe('fair');
        expect(qualityFromLiveKit('poor')).toBe('poor');
        expect(qualityFromLiveKit('lost')).toBe('lost');
        expect(qualityFromLiveKit('unknown')).toBe('unknown');
        expect(qualityFromLiveKit(undefined)).toBe('unknown');
    });
});

describe('qualityFromStats', () => {
    const stats = (
        audioLossPercent: number | null,
        jitterMs: number | null,
        roundTripMs: number | null,
    ) => ({
        audioLossPercent,
        jitterMs,
        roundTripMs,
    });

    it('is unknown until something has been measured', () => {
        expect(qualityFromStats(stats(null, null, null))).toBe('unknown');
    });

    it('is good on a clean path', () => {
        expect(qualityFromStats(stats(0, 4, 35))).toBe('good');
        expect(qualityFromStats(stats(1.9, 29, 299))).toBe('good');
    });

    it('turns fair on noticeable loss, jitter or delay', () => {
        expect(qualityFromStats(stats(2, 4, 35))).toBe('fair');
        expect(qualityFromStats(stats(0, 30, 35))).toBe('fair');
        expect(qualityFromStats(stats(0, 4, 300))).toBe('fair');
    });

    it('turns poor when speech would start to break', () => {
        expect(qualityFromStats(stats(8, 4, 35))).toBe('poor');
        expect(qualityFromStats(stats(0, 60, 35))).toBe('poor');
        expect(qualityFromStats(stats(null, null, 600))).toBe('poor');
    });
});

describe('worstQuality', () => {
    it('never hides a problem reported by either source', () => {
        expect(worstQuality('good', 'poor')).toBe('poor');
        expect(worstQuality('fair', 'good')).toBe('fair');
        expect(worstQuality('unknown', 'good')).toBe('good');
        expect(worstQuality('lost', 'poor')).toBe('lost');
    });
});

describe('qualityBars', () => {
    it('lights three, two, one or no bars', () => {
        expect([
            qualityBars('good'),
            qualityBars('fair'),
            qualityBars('poor'),
            qualityBars('lost'),
        ]).toEqual([3, 2, 1, 0]);
    });
});

describe('bitrateKbps', () => {
    it('needs two samples', () => {
        expect(bitrateKbps(null, { timestamp: 1000, bytes: 500 })).toBeNull();
    });

    it('turns a byte delta over milliseconds into kilobits per second', () => {
        expect(
            bitrateKbps(
                { timestamp: 1000, bytes: 0 },
                { timestamp: 3000, bytes: 250_000 },
            ),
        ).toBe(1000);
    });

    it('does not go negative when a counter resets after a reconnect', () => {
        expect(
            bitrateKbps(
                { timestamp: 1000, bytes: 9000 },
                { timestamp: 2000, bytes: 100 },
            ),
        ).toBe(0);
    });
});

describe('lossPercent', () => {
    it('is the share of expected packets that went missing', () => {
        expect(lossPercent(5, 95)).toBe(5);
        expect(lossPercent(0, 100)).toBe(0);
        expect(lossPercent(1, 199)).toBe(0.5);
    });

    it('is unknown when nothing was expected in the interval', () => {
        expect(lossPercent(0, 0)).toBeNull();
    });
});
