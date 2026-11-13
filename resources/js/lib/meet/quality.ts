import type { ConnectionStats, QualityLevel } from './types';

/**
 * Turning the numbers WebRTC reports into the three words people can act on.
 */

/** LiveKit's connection quality strings, without importing the SDK. */
export function qualityFromLiveKit(
    value: string | null | undefined,
): QualityLevel {
    switch (value) {
        case 'excellent':
            return 'good';
        case 'good':
            return 'fair';
        case 'poor':
            return 'poor';
        case 'lost':
            return 'lost';
        default:
            return 'unknown';
    }
}

/**
 * Health of the audio path from measured stats. Thresholds follow what is audible in Opus
 * with redundancy: a few percent of loss is concealed, beyond that speech starts to break.
 */
export function qualityFromStats(
    stats: Pick<
        ConnectionStats,
        'audioLossPercent' | 'jitterMs' | 'roundTripMs'
    >,
): QualityLevel {
    const {
        audioLossPercent: loss,
        jitterMs: jitter,
        roundTripMs: rtt,
    } = stats;

    if (loss === null && jitter === null && rtt === null) {
        return 'unknown';
    }

    if ((loss ?? 0) >= 8 || (jitter ?? 0) >= 60 || (rtt ?? 0) >= 600) {
        return 'poor';
    }

    if ((loss ?? 0) >= 2 || (jitter ?? 0) >= 30 || (rtt ?? 0) >= 300) {
        return 'fair';
    }

    return 'good';
}

/** The worse of two readings, so a problem on either side is never hidden. */
export function worstQuality(a: QualityLevel, b: QualityLevel): QualityLevel {
    const rank: Record<QualityLevel, number> = {
        unknown: 0,
        good: 1,
        fair: 2,
        poor: 3,
        lost: 4,
    };

    return rank[a] >= rank[b] ? a : b;
}

export const QUALITY_LABEL: Record<QualityLevel, string> = {
    good: 'Good connection',
    fair: 'Fair connection',
    poor: 'Poor connection',
    lost: 'Connection lost',
    unknown: 'Checking connection',
};

/** How many of the three bars are lit. */
export function qualityBars(level: QualityLevel): number {
    return { good: 3, fair: 2, poor: 1, lost: 0, unknown: 0 }[level];
}

export type RtpSample = {
    timestamp: number;
    bytes: number;
};

/** Kilobits per second between two byte counters, or null when it cannot be known yet. */
export function bitrateKbps(
    previous: RtpSample | null,
    current: RtpSample,
): number | null {
    if (!previous || current.timestamp <= previous.timestamp) {
        return null;
    }

    const bits = Math.max(0, current.bytes - previous.bytes) * 8;

    return Math.round(bits / (current.timestamp - previous.timestamp));
}

/** Loss over an interval as a percentage of the packets that should have arrived. */
export function lossPercent(
    lostDelta: number,
    receivedDelta: number,
): number | null {
    const expected = lostDelta + receivedDelta;

    if (expected <= 0) {
        return null;
    }

    return Math.round((Math.max(0, lostDelta) / expected) * 1000) / 10;
}
