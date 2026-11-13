import { describe, expect, it } from 'vitest';
import {
    MasterClock,
    activeTracks,
    elementTime,
    gridColumns,
    mountedTracks,
    muteIntervals,
    participantNames,
    screenSegments,
    speakerAt,
    stageLayout,
    syncAction,
    timelineDuration,
    timelineMarkers,
} from '@/lib/calls/playback';
import type { RecordingEvent, RecordingTrack } from '@/types';

const track = (
    file: string,
    source: RecordingTrack['source'],
    identity: string,
    start: number,
    duration: number,
): RecordingTrack => ({
    file,
    kind:
        source === 'microphone' || source === 'screen_share_audio'
            ? 'audio'
            : 'video',
    source,
    codec: source === 'microphone' ? 'opus' : 'vp9',
    participant: { identity, name: identity === 'p_a' ? 'Ada' : 'Bo' },
    start_ms: start,
    duration_ms: duration,
    width: 0,
    height: 0,
    size_bytes: 0,
    url: `/media/${file}`,
});

const tracks = [
    track('mic-a', 'microphone', 'p_a', 0, 60_000),
    track('cam-a', 'camera', 'p_a', 1_000, 59_000),
    track('mic-b', 'microphone', 'p_b', 10_000, 40_000),
    track('screen-a', 'screen_share', 'p_a', 20_000, 20_000),
    track('screen-b', 'screen_share', 'p_b', 30_000, 15_000),
];

describe('which tracks play when', () => {
    it('treats a track as active from its start up to, not including, its end', () => {
        expect(activeTracks(tracks, 0).map((t) => t.file)).toEqual(['mic-a']);
        expect(activeTracks(tracks, 9_999).map((t) => t.file)).toEqual([
            'mic-a',
            'cam-a',
        ]);
        expect(activeTracks(tracks, 50_000).map((t) => t.file)).toEqual([
            'mic-a',
            'cam-a',
        ]);
        expect(activeTracks(tracks, 60_000)).toEqual([]);
    });

    it('mounts tracks a few seconds early so they are ready when the clock arrives', () => {
        expect(mountedTracks(tracks, 2_500, 8_000).map((t) => t.file)).toEqual([
            'mic-a',
            'cam-a',
            'mic-b',
        ]);
        expect(mountedTracks(tracks, 1_999, 8_000).map((t) => t.file)).toEqual([
            'mic-a',
            'cam-a',
        ]);
    });

    it('falls back to the last track end when the manifest has no duration', () => {
        expect(timelineDuration(0, tracks)).toBe(60_000);
        expect(timelineDuration(90_000, tracks)).toBe(90_000);
        expect(timelineDuration(0, [])).toBe(0);
    });
});

describe('where an element should be', () => {
    it('maps master time into the file, clamped to the file', () => {
        expect(elementTime(tracks[2], 10_000)).toBe(0);
        expect(elementTime(tracks[2], 25_500)).toBe(15.5);
        expect(elementTime(tracks[2], 5_000)).toBe(0);
        expect(elementTime(tracks[2], 99_000)).toBe(40);
    });

    it('leaves an element alone inside the tolerance', () => {
        expect(syncAction(10.02, 10)).toEqual({ type: 'rate', rate: 1 });
        expect(syncAction(9.97, 10, 1.5)).toEqual({ type: 'rate', rate: 1.5 });
    });

    it('nudges the playback rate for small drift, towards the master', () => {
        const ahead = syncAction(10.12, 10);
        const behind = syncAction(9.88, 10);

        expect(ahead).toEqual({ type: 'rate', rate: 0.94 });
        expect(behind).toEqual({ type: 'rate', rate: 1.06 });
        expect(syncAction(10.06, 10)).toEqual({ type: 'rate', rate: 0.97 });
        expect(syncAction(10.12, 10, 2)).toEqual({ type: 'rate', rate: 1.88 });
    });

    it('seeks when the drift is too large to close smoothly', () => {
        expect(syncAction(11, 10)).toEqual({ type: 'seek', to: 10 });
        expect(syncAction(3, 10)).toEqual({ type: 'seek', to: 10 });
    });
});

describe('master clock', () => {
    it('advances with the wall clock while playing and holds while paused', () => {
        const clock = new MasterClock(60_000);

        expect(clock.timeAt(500)).toBe(0);
        clock.play(1_000);
        expect(clock.timeAt(3_500)).toBe(2_500);
        clock.pause(3_500);
        expect(clock.timeAt(9_000)).toBe(2_500);
        clock.play(10_000);
        expect(clock.timeAt(11_000)).toBe(3_500);
    });

    it('applies the playback rate from the moment it changes', () => {
        const clock = new MasterClock(60_000);

        clock.play(0);
        clock.setRate(2, 1_000);
        expect(clock.timeAt(2_000)).toBe(3_000);
    });

    it('seeks while playing or paused and never leaves the timeline', () => {
        const clock = new MasterClock(60_000);

        clock.seek(30_000, 0);
        expect(clock.timeAt(5_000)).toBe(30_000);
        clock.play(5_000);
        clock.seek(59_000, 6_000);
        expect(clock.timeAt(6_500)).toBe(59_500);
        expect(clock.timeAt(20_000)).toBe(60_000);
        expect(clock.ended(20_000)).toBe(true);
        clock.seek(-5, 20_000);
        expect(clock.timeAt(20_000)).toBe(0);
    });
});

describe('stage layout', () => {
    const events: RecordingEvent[] = [
        { at_ms: 0, type: 'participant_joined', participant: 'p_a' },
        { at_ms: 10_000, type: 'participant_joined', participant: 'p_b' },
        { at_ms: 12_000, type: 'active_speaker', participant: 'p_b' },
        {
            at_ms: 15_000,
            type: 'track_muted',
            participant: 'p_a',
            source: 'camera',
        },
        {
            at_ms: 18_000,
            type: 'track_unmuted',
            participant: 'p_a',
            source: 'camera',
        },
        { at_ms: 40_000, type: 'active_speaker', participant: 'p_a' },
        { at_ms: 50_000, type: 'participant_left', participant: 'p_b' },
    ];

    it('shows a grid of people while nobody shares a screen', () => {
        const layout = stageLayout(tracks, events, 13_000, 60_000);

        expect(layout.mode).toBe('grid');
        expect(layout.screen).toBeNull();
        expect(
            layout.tiles.map((t) => [
                t.name,
                t.camera?.file ?? null,
                t.speaking,
            ]),
        ).toEqual([
            ['Ada', 'cam-a', false],
            ['Bo', null, true],
        ]);
    });

    it('hides a camera while it is muted and falls back to the audio view', () => {
        const layout = stageLayout(tracks, events, 16_000, 60_000);

        expect(layout.mode).toBe('audio');
        expect(layout.tiles[0].camera).toBeNull();
    });

    it('puts the most recent screen share on stage, or the one the viewer picked', () => {
        expect(stageLayout(tracks, events, 25_000, 60_000).screen?.file).toBe(
            'screen-a',
        );

        const both = stageLayout(tracks, events, 35_000, 60_000);

        expect(both.mode).toBe('screen');
        expect(both.screen?.file).toBe('screen-b');
        expect(both.screens.map((s) => s.file)).toEqual([
            'screen-b',
            'screen-a',
        ]);
        expect(
            stageLayout(tracks, events, 35_000, 60_000, 'screen-a').screen
                ?.file,
        ).toBe('screen-a');
        // A preference for a share that has ended is ignored.
        expect(
            stageLayout(tracks, events, 42_000, 60_000, 'screen-a').screen
                ?.file,
        ).toBe('screen-b');
    });

    it('is empty when no track covers the moment', () => {
        expect(stageLayout([tracks[2]], events, 2_000, 60_000).mode).toBe(
            'empty',
        );
    });

    it('knows who was speaking and when a track was muted', () => {
        expect(speakerAt(events, 5_000)).toBeNull();
        expect(speakerAt(events, 12_000)).toBe('p_b');
        expect(speakerAt(events, 45_000)).toBe('p_a');
        expect(muteIntervals(events, 'p_a', 'camera', 60_000)).toEqual([
            { from: 15_000, to: 18_000 },
        ]);
        expect(
            muteIntervals(
                [
                    {
                        at_ms: 5_000,
                        type: 'track_muted',
                        participant: 'p_a',
                        source: 'microphone',
                    },
                ],
                'p_a',
                'microphone',
                60_000,
            ),
        ).toEqual([{ from: 5_000, to: 60_000 }]);
    });

    it('lays tiles out in the grid that keeps them largest', () => {
        expect(gridColumns(1)).toBe(1);
        expect(gridColumns(2)).toBe(2);
        expect(gridColumns(4)).toBe(2);
        expect(gridColumns(5)).toBe(3);
        expect(gridColumns(2, 9 / 16)).toBe(1);
    });
});

describe('timeline decorations', () => {
    it('merges overlapping screen shares into one band', () => {
        expect(screenSegments(tracks, 60_000)).toEqual([
            {
                kind: 'screen',
                from: 20_000 / 60_000,
                to: 45_000 / 60_000,
                label: 'Ada and Bo shared a screen',
            },
        ]);
        expect(screenSegments(tracks, 0)).toEqual([]);
    });

    it('keeps separate shares separate', () => {
        const separate = [
            track('s1', 'screen_share', 'p_a', 0, 10_000),
            track('s2', 'screen_share', 'p_a', 30_000, 10_000),
        ];

        expect(
            screenSegments(separate, 60_000).map((s) => [s.from, s.to]),
        ).toEqual([
            [0, 10_000 / 60_000],
            [0.5, 40_000 / 60_000],
        ]);
    });

    it('marks joins, leaves and recorder restarts with names', () => {
        const markers = timelineMarkers(
            [
                { at_ms: 30_000, type: 'participant_left', participant: 'p_b' },
                { at_ms: 0, type: 'participant_joined', participant: 'p_a' },
                { at_ms: 45_000, type: 'recorder_restarted' },
                { at_ms: 10_000, type: 'active_speaker', participant: 'p_a' },
                {
                    at_ms: 20_000,
                    type: 'participant_joined',
                    participant: 'p_x',
                },
            ],
            participantNames(tracks),
            60_000,
        );

        expect(markers.map((m) => [m.at, m.label])).toEqual([
            [0, 'Ada joined'],
            [20_000 / 60_000, 'Someone joined'],
            [0.5, 'Bo left'],
            [0.75, 'Recording was interrupted here'],
        ]);
    });
});
