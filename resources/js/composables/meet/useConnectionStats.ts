import type { Room } from 'livekit-client';
import type { Ref } from 'vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import { bitrateKbps, lossPercent } from '@/lib/meet/quality';
import type { RtpSample } from '@/lib/meet/quality';
import type { ConnectionStats } from '@/lib/meet/types';

type StatEntry = Record<string, unknown> & { type: string; id: string };

const EMPTY: ConnectionStats = {
    audioLossPercent: null,
    jitterMs: null,
    roundTripMs: null,
    sendKbps: null,
    receiveKbps: null,
    relayed: null,
    protocol: null,
    updatedAt: 0,
};

const number = (value: unknown): number | null =>
    typeof value === 'number' && Number.isFinite(value) ? value : null;

function entries(report: RTCStatsReport | undefined): StatEntry[] {
    const list: StatEntry[] = [];

    report?.forEach((entry: StatEntry) => list.push(entry));

    return list;
}

/**
 * Live numbers about the media connection, read from WebRTC's own statistics every two
 * seconds while `active` is true. Audio is what is measured: it is the thing that must not
 * break. Sending is read from the microphone's sender, receiving from one remote voice.
 */
export function useConnectionStats(
    room: () => Room | null,
    active: Ref<boolean>,
) {
    const stats = ref<ConnectionStats>({ ...EMPTY });

    let timer: ReturnType<typeof setInterval> | undefined;
    let previousSend: RtpSample | null = null;
    let previousReceive: RtpSample | null = null;
    let previousInbound: { lost: number; received: number } | null = null;

    const sample = async (): Promise<void> => {
        const current = room();

        if (!current) {
            return;
        }

        const { Track } = await import('livekit-client');
        const next: ConnectionStats = { ...EMPTY, updatedAt: Date.now() };

        const localPublication =
            current.localParticipant.getTrackPublication(
                Track.Source.Microphone,
            ) ??
            current.localParticipant.getTrackPublication(Track.Source.Camera);

        const remoteTrack = [...current.remoteParticipants.values()]
            .map((p) => p.getTrackPublication(Track.Source.Microphone)?.track)
            .find((track) => track !== undefined);

        const [outgoing, incoming] = await Promise.all([
            localPublication?.track?.getRTCStatsReport().catch(() => undefined),
            remoteTrack?.getRTCStatsReport().catch(() => undefined),
        ]);

        let outgoingLoss: number | null = null;
        let incomingLoss: number | null = null;
        let jitter: number | null = null;

        const sent = entries(outgoing);
        const received = entries(incoming);

        // --- What the other side reports about our audio ---------------------------------
        const remoteInbound = sent.find(
            (entry) =>
                entry.type === 'remote-inbound-rtp' && entry.kind === 'audio',
        );

        if (remoteInbound) {
            const fraction = number(remoteInbound.fractionLost);

            outgoingLoss =
                fraction === null ? null : Math.round(fraction * 1000) / 10;
            jitter = number(remoteInbound.jitter);

            const rtt = number(remoteInbound.roundTripTime);

            next.roundTripMs = rtt === null ? null : Math.round(rtt * 1000);
        }

        // --- The transport: round trip, relay, throughput -------------------------------
        for (const [list, direction] of [
            [sent, 'send'],
            [received, 'receive'],
        ] as const) {
            const transport = list.find((entry) => entry.type === 'transport');
            const pair =
                list.find(
                    (entry) =>
                        entry.type === 'candidate-pair' &&
                        entry.id === transport?.selectedCandidatePairId,
                ) ??
                list.find(
                    (entry) =>
                        entry.type === 'candidate-pair' &&
                        entry.nominated === true &&
                        entry.state === 'succeeded',
                );

            if (!pair) {
                continue;
            }

            const candidate = list.find(
                (entry) =>
                    entry.type === 'local-candidate' &&
                    entry.id === pair.localCandidateId,
            );

            if (candidate && next.relayed !== true) {
                next.relayed = candidate.candidateType === 'relay';
                next.protocol =
                    typeof candidate.relayProtocol === 'string'
                        ? `TURN/${candidate.relayProtocol.toUpperCase()}`
                        : typeof candidate.protocol === 'string'
                          ? candidate.protocol.toUpperCase()
                          : null;
            }

            if (next.roundTripMs === null) {
                const rtt = number(pair.currentRoundTripTime);

                next.roundTripMs = rtt === null ? null : Math.round(rtt * 1000);
            }

            const timestamp = number(pair.timestamp) ?? Date.now();

            if (direction === 'send') {
                const now = { timestamp, bytes: number(pair.bytesSent) ?? 0 };

                next.sendKbps = bitrateKbps(previousSend, now);
                previousSend = now;
            } else {
                const now = {
                    timestamp,
                    bytes: number(pair.bytesReceived) ?? 0,
                };

                next.receiveKbps = bitrateKbps(previousReceive, now);
                previousReceive = now;
            }
        }

        // --- What we measure about the audio we receive ---------------------------------
        const inbound = received.find(
            (entry) => entry.type === 'inbound-rtp' && entry.kind === 'audio',
        );

        if (inbound) {
            const now = {
                lost: number(inbound.packetsLost) ?? 0,
                received: number(inbound.packetsReceived) ?? 0,
            };

            if (previousInbound) {
                incomingLoss = lossPercent(
                    now.lost - previousInbound.lost,
                    now.received - previousInbound.received,
                );
            }

            previousInbound = now;
            jitter = Math.max(jitter ?? 0, number(inbound.jitter) ?? 0);
        } else {
            previousInbound = null;
            previousReceive = incoming ? previousReceive : null;
        }

        next.audioLossPercent =
            outgoingLoss === null && incomingLoss === null
                ? null
                : Math.max(outgoingLoss ?? 0, incomingLoss ?? 0);
        next.jitterMs = jitter === null ? null : Math.round(jitter * 1000);

        stats.value = next;
    };

    const stop = () => {
        clearInterval(timer);
        timer = undefined;
    };

    watch(
        active,
        (isActive) => {
            stop();

            if (isActive && typeof window !== 'undefined') {
                void sample();
                timer = setInterval(() => void sample(), 2000);
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(stop);

    return { stats, sample };
}
