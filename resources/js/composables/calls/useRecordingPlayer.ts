import type { MaybeRefOrGetter } from 'vue';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    shallowRef,
    toValue,
    watch,
} from 'vue';
import {
    MasterClock,
    elementTime,
    isActiveAt,
    mountedTracks,
    stageLayout,
    syncAction,
    timelineDuration,
} from '@/lib/calls/playback';
import type { RecordingPlayback, RecordingTrack } from '@/types';

/** How often every element is compared against the master clock. */
const SYNC_INTERVAL_MS = 100;

/** A track that cannot produce data for this long is given up on instead of freezing playback. */
const STALL_GIVE_UP_MS = 12_000;

/** Element readyState from which playback can continue (HAVE_FUTURE_DATA). */
const READY = 3;

export const PLAYBACK_RATES = [0.75, 1, 1.25, 1.5, 2] as const;

/**
 * Plays a recording made of separate per-track files as one meeting. A wall-clock master
 * drives the timeline; every track owns a media element that is only mounted around the time
 * it is needed, started and stopped as the clock enters and leaves its range, and kept on the
 * master by seeking (large error) or a slight playback-rate nudge (small error). While any
 * active element is still loading or seeking, the clock holds so nothing runs ahead.
 */
export function useRecordingPlayer(
    source: MaybeRefOrGetter<RecordingPlayback>,
) {
    const recording = computed(() => toValue(source));
    const tracks = computed(() => recording.value.tracks);
    const durationMs = computed(() =>
        timelineDuration(recording.value.duration_ms, tracks.value),
    );

    const clock = new MasterClock(durationMs.value);
    const elements = new Map<string, HTMLMediaElement>();
    const waitingSince = new Map<string, number>();

    /** Master time for display, refreshed every animation frame. */
    const timeMs = ref(0);
    /** Master time for layout decisions, refreshed with every sync pass. */
    const layoutTimeMs = ref(0);
    const playing = ref(false);
    const buffering = ref(false);
    const ended = ref(false);
    const rate = ref(1);
    const volume = ref(1);
    const muted = ref(false);
    const preferredScreen = ref<string | null>(null);
    const failed = reactive(new Set<string>());
    const worstDriftMs = shallowRef(0);

    const mounted = computed(() =>
        mountedTracks(tracks.value, layoutTimeMs.value),
    );
    const layout = computed(() =>
        stageLayout(
            tracks.value,
            recording.value.events,
            layoutTimeMs.value,
            durationMs.value,
            preferredScreen.value,
        ),
    );

    let syncTimer: ReturnType<typeof setInterval> | undefined;
    let frame = 0;

    const now = () => performance.now();

    const applyVolume = (element: HTMLMediaElement) => {
        element.volume = volume.value;
        element.muted = muted.value || element instanceof HTMLVideoElement;
    };

    /** Called by the component for every media element it renders (and with null on removal). */
    const register = (
        track: RecordingTrack,
        element: HTMLMediaElement | null,
    ) => {
        if (!element) {
            elements.delete(track.file);
            waitingSince.delete(track.file);

            return;
        }

        if (elements.get(track.file) === element) {
            return;
        }

        elements.set(track.file, element);
        applyVolume(element);
        element.addEventListener(
            'error',
            () => {
                failed.add(track.file);
            },
            { once: true },
        );
    };

    const isSettled = (element: HTMLMediaElement, target: number) =>
        element.ended ||
        (Number.isFinite(element.duration) &&
            target >= element.duration - 0.25);

    /**
     * One pass: start, stop and correct every element, and decide whether the clock may run.
     */
    const sync = () => {
        const at = now();
        const time = clock.timeAt(at);
        let waiting = false;
        let worst = 0;

        for (const track of tracks.value) {
            const element = elements.get(track.file);

            if (!element || failed.has(track.file)) {
                continue;
            }

            if (!isActiveAt(track, time)) {
                if (!element.paused) {
                    element.pause();
                }

                waitingSince.delete(track.file);

                continue;
            }

            const target = elementTime(track, time);
            const settled = isSettled(element, target);
            const notReady =
                !settled && (element.seeking || element.readyState < READY);

            if (notReady && playing.value) {
                const since = waitingSince.get(track.file) ?? at;

                waitingSince.set(track.file, since);

                if (at - since > STALL_GIVE_UP_MS) {
                    failed.add(track.file);
                    element.pause();

                    continue;
                }

                waiting = true;
            } else {
                waitingSince.delete(track.file);
            }

            if (playing.value && !buffering.value && !notReady) {
                if (element.paused && !settled) {
                    if (Math.abs(element.currentTime - target) > 0.05) {
                        element.currentTime = target;
                    }

                    element.playbackRate = rate.value;
                    void element.play().catch((error: unknown) => {
                        // Only a refused autoplay (no user gesture yet) stops the player. An
                        // AbortError just means a later pass paused this element again while
                        // its play request was still pending, which is normal around seeks.
                        if (
                            error instanceof DOMException &&
                            error.name === 'NotAllowedError' &&
                            playing.value
                        ) {
                            pause();
                        }
                    });
                } else if (!settled) {
                    const action = syncAction(
                        element.currentTime,
                        target,
                        rate.value,
                    );

                    worst = Math.max(
                        worst,
                        Math.abs(element.currentTime - target) * 1000,
                    );

                    if (action.type === 'seek') {
                        element.currentTime = action.to;
                    } else if (
                        action.type === 'rate' &&
                        element.playbackRate !== action.rate
                    ) {
                        element.playbackRate = action.rate;
                    }
                }
            } else {
                // Paused by the viewer, still loading, or ready while another track is loading:
                // hold still at the frame the master clock is on. A paused element keeps
                // fetching (preload is "auto"), and holding it means it can never run ahead of
                // the clock and need another seek, which would only make it "not ready" again.
                if (!element.paused) {
                    element.pause();
                }

                if (
                    !element.seeking &&
                    !settled &&
                    Math.abs(element.currentTime - target) > 0.05
                ) {
                    element.currentTime = target;
                }
            }
        }

        if (playing.value) {
            if (waiting && !buffering.value) {
                buffering.value = true;
                clock.pause(at);
            } else if (!waiting && buffering.value) {
                buffering.value = false;
                clock.play(at);

                // Start the elements that were holding, in this same pass.
                sync();

                return;
            }
        }

        worstDriftMs.value = worst;
        layoutTimeMs.value = time;

        if (playing.value && clock.ended(at)) {
            pause();
            ended.value = true;
            timeMs.value = durationMs.value;
        }
    };

    const tick = () => {
        timeMs.value = clock.timeAt(now());

        if (playing.value) {
            frame = requestAnimationFrame(tick);
        }
    };

    const play = () => {
        if (playing.value) {
            return;
        }

        if (clock.timeAt(now()) >= durationMs.value) {
            clock.seek(0, now());
        }

        ended.value = false;
        playing.value = true;
        buffering.value = false;
        clock.play(now());
        sync();
        frame = requestAnimationFrame(tick);
    };

    function pause() {
        if (!playing.value) {
            return;
        }

        playing.value = false;
        buffering.value = false;
        clock.pause(now());
        cancelAnimationFrame(frame);
        elements.forEach((element) => element.pause());
        timeMs.value = clock.timeAt(now());
    }

    const toggle = () => (playing.value ? pause() : play());

    const seek = (target: number) => {
        const at = now();

        clock.seek(target, at);
        ended.value = false;
        timeMs.value = clock.timeAt(at);
        layoutTimeMs.value = timeMs.value;
        waitingSince.clear();
        sync();
    };

    const seekBy = (deltaMs: number) => seek(clock.timeAt(now()) + deltaMs);

    const setRate = (value: number) => {
        rate.value = value;
        clock.setRate(value, now());
        sync();
    };

    watch([volume, muted], () => elements.forEach(applyVolume));

    watch(durationMs, (value) => clock.setDuration(value));

    // A different recording in the same component: start over.
    watch(
        () => recording.value.uuid,
        () => {
            pause();
            failed.clear();
            preferredScreen.value = null;
            seek(0);
        },
    );

    onMounted(() => {
        syncTimer = setInterval(sync, SYNC_INTERVAL_MS);
    });

    onBeforeUnmount(() => {
        clearInterval(syncTimer);
        cancelAnimationFrame(frame);
        elements.forEach((element) => element.pause());
        elements.clear();
    });

    return {
        tracks,
        durationMs,
        timeMs,
        layoutTimeMs,
        playing,
        buffering,
        ended,
        rate,
        volume,
        muted,
        mounted,
        layout,
        failed,
        preferredScreen,
        worstDriftMs,
        register,
        play,
        pause,
        toggle,
        seek,
        seekBy,
        setRate,
    };
}
