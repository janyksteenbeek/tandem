import type {
    RecordingEvent,
    RecordingTrack,
    RecordingTrackSource,
} from '@/types';

/**
 * The maths behind the recording player. A recording is a set of per-track files, each with
 * its own start offset on one shared timeline; everything here answers "at master time t,
 * what should be on screen and where should each media element be?" without touching the DOM.
 */

/** How early a track's element is created and starts loading before it is needed. */
export const PRELOAD_MS = 8_000;

/** Drift an element may have before it is corrected at all. */
export const DRIFT_TOLERANCE_S = 0.04;

/** Beyond this an element is re-seeked; below it a playback-rate nudge closes the gap. */
export const DRIFT_SEEK_S = 0.3;

/** The most a nudge may speed up or slow down an element: inaudible, yet closes 300 ms in 5 s. */
export const MAX_NUDGE = 0.06;

export function trackEnd(track: RecordingTrack): number {
    return track.start_ms + track.duration_ms;
}

/** The timeline length: the manifest's duration, or the last track's end when that is missing. */
export function timelineDuration(
    durationMs: number,
    tracks: RecordingTrack[],
): number {
    return Math.max(durationMs, ...tracks.map(trackEnd), 0);
}

export function isActiveAt(track: RecordingTrack, timeMs: number): boolean {
    return timeMs >= track.start_ms && timeMs < trackEnd(track);
}

export function activeTracks(
    tracks: RecordingTrack[],
    timeMs: number,
): RecordingTrack[] {
    return tracks.filter((track) => isActiveAt(track, timeMs));
}

/**
 * Tracks whose media element should exist at t: the active ones plus those starting within the
 * preload window, so their first frames are ready when the clock reaches them.
 */
export function mountedTracks(
    tracks: RecordingTrack[],
    timeMs: number,
    preloadMs: number = PRELOAD_MS,
): RecordingTrack[] {
    return tracks.filter(
        (track) =>
            timeMs >= track.start_ms - preloadMs && timeMs < trackEnd(track),
    );
}

/** Where a track's own element should be (seconds into its file) at master time t. */
export function elementTime(track: RecordingTrack, timeMs: number): number {
    const local = (timeMs - track.start_ms) / 1000;

    return Math.min(Math.max(local, 0), track.duration_ms / 1000);
}

export type SyncAction =
    | { type: 'hold' }
    | { type: 'rate'; rate: number }
    | { type: 'seek'; to: number };

/**
 * What to do with an element that is `actual` seconds into its file when it should be at
 * `target`. Tiny errors are left alone, small ones are closed gradually by playing slightly
 * faster or slower (no audible jump), large ones by seeking.
 */
export function syncAction(
    actual: number,
    target: number,
    baseRate: number = 1,
): SyncAction {
    const drift = actual - target;
    const magnitude = Math.abs(drift);

    if (magnitude > DRIFT_SEEK_S) {
        return { type: 'seek', to: target };
    }

    if (magnitude <= DRIFT_TOLERANCE_S) {
        return { type: 'rate', rate: baseRate };
    }

    // Proportional: aim to close the gap in about two seconds, capped so it stays inaudible.
    const nudge = Math.min(MAX_NUDGE, magnitude / 2);

    return {
        type: 'rate',
        rate: round(baseRate * (drift > 0 ? 1 - nudge : 1 + nudge)),
    };
}

function round(value: number): number {
    return Math.round(value * 1000) / 1000;
}

export type MuteInterval = { from: number; to: number };

/**
 * When a participant's track of one source was muted, from the manifest's mute/unmute events.
 * A mute that is never undone lasts to the end of the recording.
 */
export function muteIntervals(
    events: RecordingEvent[],
    identity: string,
    source: RecordingTrackSource,
    durationMs: number,
): MuteInterval[] {
    const intervals: MuteInterval[] = [];
    let mutedAt: number | null = null;

    [...events]
        .filter(
            (event) =>
                event.participant === identity &&
                event.source === source &&
                (event.type === 'track_muted' ||
                    event.type === 'track_unmuted'),
        )
        .sort((a, b) => a.at_ms - b.at_ms)
        .forEach((event) => {
            if (event.type === 'track_muted' && mutedAt === null) {
                mutedAt = event.at_ms;
            } else if (event.type === 'track_unmuted' && mutedAt !== null) {
                intervals.push({ from: mutedAt, to: event.at_ms });
                mutedAt = null;
            }
        });

    if (mutedAt !== null) {
        intervals.push({ from: mutedAt, to: durationMs });
    }

    return intervals;
}

export function isMutedAt(intervals: MuteInterval[], timeMs: number): boolean {
    return intervals.some(
        (interval) => timeMs >= interval.from && timeMs < interval.to,
    );
}

/** Who the recorder marked as the dominant speaker at t, if anyone. */
export function speakerAt(
    events: RecordingEvent[],
    timeMs: number,
): string | null {
    let speaker: string | null = null;
    let latest = -1;

    for (const event of events) {
        if (
            event.type === 'active_speaker' &&
            event.at_ms <= timeMs &&
            event.at_ms >= latest
        ) {
            latest = event.at_ms;
            speaker = event.participant ?? null;
        }
    }

    return speaker;
}

export type StageTile = {
    identity: string;
    name: string;
    /** The camera track to show, when the participant's camera is on at this moment. */
    camera: RecordingTrack | null;
    hasAudio: boolean;
    speaking: boolean;
};

export type StageLayout = {
    mode: 'screen' | 'grid' | 'audio' | 'empty';
    /** The shared screen on the big stage (mode `screen`). */
    screen: RecordingTrack | null;
    /** Every screen being shared right now, so the viewer can switch between them. */
    screens: RecordingTrack[];
    /** One tile per person present: the strip next to a screen, or the grid without one. */
    tiles: StageTile[];
};

/**
 * What the player shows at t. A shared screen always takes the stage (the latest one to start,
 * unless the viewer picked another that is still live); everyone present gets a tile, with
 * their camera when it is on and their initials when it is not.
 */
export function stageLayout(
    tracks: RecordingTrack[],
    events: RecordingEvent[],
    timeMs: number,
    durationMs: number,
    preferredScreen: string | null = null,
): StageLayout {
    const active = activeTracks(tracks, timeMs);
    const visible = (track: RecordingTrack) =>
        !isMutedAt(
            muteIntervals(
                events,
                track.participant.identity,
                track.source,
                durationMs,
            ),
            timeMs,
        );

    const screens = active
        .filter((track) => track.source === 'screen_share')
        .sort((a, b) => b.start_ms - a.start_ms);
    const screen =
        screens.find((track) => track.file === preferredScreen) ??
        screens[0] ??
        null;

    const speaker = speakerAt(events, timeMs);
    const tiles = new Map<string, StageTile>();

    for (const track of active) {
        if (track.source === 'screen_share') {
            continue;
        }

        const identity = track.participant.identity;
        const tile = tiles.get(identity) ?? {
            identity,
            name: track.participant.name,
            camera: null,
            hasAudio: false,
            speaking: speaker !== null && speaker === identity,
        };

        if (track.source === 'camera' && visible(track)) {
            tile.camera = track;
        }

        if (track.kind === 'audio') {
            tile.hasAudio = true;
        }

        tiles.set(identity, tile);
    }

    const people = [...tiles.values()].sort((a, b) =>
        a.name.localeCompare(b.name),
    );

    const mode = screen
        ? 'screen'
        : people.some((tile) => tile.camera)
          ? 'grid'
          : people.length > 0
            ? 'audio'
            : 'empty';

    return { mode, screen, screens, tiles: people };
}

/** Columns for a grid of n tiles in a box of the given aspect ratio (width / height). */
export function gridColumns(count: number, aspect: number = 16 / 9): number {
    if (count <= 1) {
        return 1;
    }

    let best = 1;
    let bestWidth = 0;

    for (let columns = 1; columns <= count; columns++) {
        const rows = Math.ceil(count / columns);
        // With the box height as 1 and its width as `aspect`, a 16:9 tile is as wide as its
        // column allows or as its row height allows, whichever is smaller. Biggest tile wins.
        const width = Math.min(aspect / columns, (1 / rows) * (16 / 9));

        // On a tie the wider arrangement wins: it reads better than a tall stack.
        if (width >= bestWidth - 1e-9) {
            bestWidth = width;
            best = columns;
        }
    }

    return best;
}

export type TimelineSegment = {
    kind: 'screen' | 'recording-gap';
    /** Fractions of the timeline, 0..1. */
    from: number;
    to: number;
    label: string;
};

/**
 * Stretches of the timeline where a screen was shared (overlapping shares merged), as
 * fractions of the whole, for the bands drawn on the seek bar.
 */
export function screenSegments(
    tracks: RecordingTrack[],
    durationMs: number,
): TimelineSegment[] {
    if (durationMs <= 0) {
        return [];
    }

    const shares = tracks
        .filter((track) => track.source === 'screen_share')
        .map((track) => ({
            from: track.start_ms,
            to: trackEnd(track),
            names: [track.participant.name],
        }))
        .sort((a, b) => a.from - b.from);

    const merged: typeof shares = [];

    for (const share of shares) {
        const last = merged[merged.length - 1];

        if (last && share.from <= last.to) {
            last.to = Math.max(last.to, share.to);
            last.names = [...new Set([...last.names, ...share.names])];
        } else {
            merged.push({ ...share });
        }
    }

    return merged.map((share) => ({
        kind: 'screen',
        from: clamp01(share.from / durationMs),
        to: clamp01(share.to / durationMs),
        label: `${share.names.join(' and ')} shared a screen`,
    }));
}

export type TimelineMarker = {
    at: number;
    type: 'joined' | 'left' | 'restarted';
    label: string;
};

/** Join, leave and recorder-restart markers, positioned as fractions of the timeline. */
export function timelineMarkers(
    events: RecordingEvent[],
    names: Record<string, string>,
    durationMs: number,
): TimelineMarker[] {
    if (durationMs <= 0) {
        return [];
    }

    const markers: TimelineMarker[] = [];

    for (const event of events) {
        const who = names[event.participant ?? ''] ?? 'Someone';

        if (event.type === 'participant_joined') {
            markers.push({
                at: clamp01(event.at_ms / durationMs),
                type: 'joined',
                label: `${who} joined`,
            });
        } else if (event.type === 'participant_left') {
            markers.push({
                at: clamp01(event.at_ms / durationMs),
                type: 'left',
                label: `${who} left`,
            });
        } else if (event.type === 'recorder_restarted') {
            markers.push({
                at: clamp01(event.at_ms / durationMs),
                type: 'restarted',
                label: 'Recording was interrupted here',
            });
        }
    }

    return markers.sort((a, b) => a.at - b.at);
}

/** Display names by media identity, from the tracks themselves. */
export function participantNames(
    tracks: RecordingTrack[],
): Record<string, string> {
    const names: Record<string, string> = {};

    for (const track of tracks) {
        names[track.participant.identity] ??= track.participant.name;
    }

    return names;
}

function clamp01(value: number): number {
    return Math.min(1, Math.max(0, value));
}

/**
 * A wall-clock driven master clock. Pure: every method takes "now" so it can be tested, and
 * the player feeds it performance.now().
 */
export class MasterClock {
    private baseMs = 0;
    private startedAt: number | null = null;

    constructor(
        private durationMs: number,
        private rate: number = 1,
    ) {}

    get playing(): boolean {
        return this.startedAt !== null;
    }

    get playbackRate(): number {
        return this.rate;
    }

    timeAt(now: number): number {
        const elapsed =
            this.startedAt === null ? 0 : (now - this.startedAt) * this.rate;

        return Math.min(this.durationMs, Math.max(0, this.baseMs + elapsed));
    }

    play(now: number): void {
        if (this.startedAt === null) {
            this.startedAt = now;
        }
    }

    /** Freeze at the current time: used for pause and while a track is buffering. */
    pause(now: number): void {
        this.baseMs = this.timeAt(now);
        this.startedAt = null;
    }

    seek(timeMs: number, now: number): void {
        this.baseMs = Math.min(this.durationMs, Math.max(0, timeMs));

        if (this.startedAt !== null) {
            this.startedAt = now;
        }
    }

    setRate(rate: number, now: number): void {
        this.baseMs = this.timeAt(now);

        if (this.startedAt !== null) {
            this.startedAt = now;
        }

        this.rate = rate;
    }

    setDuration(durationMs: number): void {
        this.durationMs = durationMs;
    }

    ended(now: number): boolean {
        return this.timeAt(now) >= this.durationMs;
    }
}
