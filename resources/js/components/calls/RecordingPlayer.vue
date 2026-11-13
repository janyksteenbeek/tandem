<script setup lang="ts">
import { useElementSize, useEventListener, useFullscreen } from '@vueuse/core';
import {
    AudioLines,
    Maximize,
    Minimize,
    MonitorUp,
    Pause,
    Play,
    RotateCcw,
    Volume1,
    Volume2,
    VolumeX,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    PLAYBACK_RATES,
    useRecordingPlayer,
} from '@/composables/calls/useRecordingPlayer';
import { formatClock, plural } from '@/lib/calls/format';
import {
    participantNames,
    screenSegments,
    timelineMarkers,
} from '@/lib/calls/playback';
import { rectStyle, stageRects } from '@/lib/calls/stage';
import { isTypingTarget } from '@/lib/keyboard';
import { avatarStyle, initialsOf } from '@/lib/mail';
import { cn } from '@/lib/utils';
import type { RecordingPlayback, RecordingTrack } from '@/types';

/**
 * Plays a recording back as the meeting it was. Every participant's microphone, camera and
 * screen is a separate file; this lines them up on one timeline, shows whatever was being
 * shared at each moment (a shared screen always gets the stage, at the full size the player
 * has), and keeps them in step while playing, seeking and buffering.
 *
 * Everything inside is plain elements with native titles rather than portalled tooltips, so
 * it keeps working when the player itself goes fullscreen.
 */
const props = defineProps<{ recording: RecordingPlayback }>();

const player = useRecordingPlayer(() => props.recording);
const {
    durationMs,
    timeMs,
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
} = player;

const root = ref<HTMLElement | null>(null);
const stage = ref<HTMLElement | null>(null);
const bar = ref<HTMLElement | null>(null);
const { width: stageWidth, height: stageHeight } = useElementSize(stage);
const {
    isFullscreen,
    toggle: toggleFullscreen,
    isSupported: canFullscreen,
} = useFullscreen(root);

const started = ref(false);

const audioTracks = computed(() =>
    mounted.value.filter((track) => track.kind === 'audio'),
);
const videoTracks = computed(() =>
    mounted.value.filter((track) => track.kind === 'video'),
);
const rects = computed(() =>
    stageRects(layout.value, stageWidth.value, stageHeight.value),
);

/** Where a video element goes right now: the stage, a tile, a thumbnail, or out of sight. */
const videoStyle = (track: RecordingTrack) => {
    if (failed.has(track.file)) {
        return rectStyle(null);
    }

    if (track.source === 'screen_share') {
        return rectStyle(
            layout.value.screen?.file === track.file
                ? rects.value.screen
                : rects.value.thumbnails[track.file],
        );
    }

    const tile = layout.value.tiles.find(
        (item) => item.camera?.file === track.file,
    );

    return rectStyle(tile ? rects.value.tiles[tile.identity] : null);
};

const isStage = (track: RecordingTrack) =>
    layout.value.screen?.file === track.file;

const otherScreens = computed(() =>
    layout.value.screens.filter(
        (screen) => screen.file !== layout.value.screen?.file,
    ),
);

const segments = computed(() =>
    screenSegments(props.recording.tracks, durationMs.value),
);
const markers = computed(() =>
    timelineMarkers(
        props.recording.events,
        participantNames(props.recording.tracks),
        durationMs.value,
    ),
);
const progress = computed(() =>
    durationMs.value > 0 ? Math.min(1, timeMs.value / durationMs.value) : 0,
);
const audioOnly = computed(() =>
    props.recording.tracks.every((track) => track.kind === 'audio'),
);

const play = () => {
    started.value = true;
    player.play();
};

const toggle = () => {
    started.value = true;
    player.toggle();
};

// --- Timeline -------------------------------------------------------------------------

const hover = ref<number | null>(null);
const scrubbing = ref(false);
let lastScrub = 0;

const fractionAt = (event: PointerEvent): number => {
    const box = bar.value?.getBoundingClientRect();

    if (!box || box.width === 0) {
        return 0;
    }

    return Math.min(1, Math.max(0, (event.clientX - box.left) / box.width));
};

const onPointerDown = (event: PointerEvent) => {
    scrubbing.value = true;
    started.value = true;
    bar.value?.setPointerCapture(event.pointerId);
    player.seek(fractionAt(event) * durationMs.value);
};

const onPointerMove = (event: PointerEvent) => {
    hover.value = fractionAt(event);

    // While dragging, seek at most ten times a second: enough to follow the pointer without
    // making every media element start a new seek on each pixel.
    if (scrubbing.value && performance.now() - lastScrub > 100) {
        lastScrub = performance.now();
        player.seek(hover.value * durationMs.value);
    }
};

const onPointerUp = (event: PointerEvent) => {
    if (scrubbing.value) {
        scrubbing.value = false;
        player.seek(fractionAt(event) * durationMs.value);
    }
};

// --- Sound and speed ------------------------------------------------------------------

const cycleRate = () => {
    const index = PLAYBACK_RATES.indexOf(
        rate.value as (typeof PLAYBACK_RATES)[number],
    );

    player.setRate(PLAYBACK_RATES[(index + 1) % PLAYBACK_RATES.length]);
};

const setVolume = (value: number) => {
    volume.value = Math.min(1, Math.max(0, value));
    muted.value = volume.value === 0;
};

const volumeIcon = computed(() =>
    muted.value || volume.value === 0
        ? VolumeX
        : volume.value < 0.5
          ? Volume1
          : Volume2,
);

// --- Keyboard -------------------------------------------------------------------------

useEventListener(
    typeof window !== 'undefined' ? window : null,
    'keydown',
    (event: KeyboardEvent) => {
        if (
            event.defaultPrevented ||
            event.metaKey ||
            event.ctrlKey ||
            event.altKey ||
            isTypingTarget(event.target) ||
            document.querySelector('[role="dialog"], [role="menu"]')
        ) {
            return;
        }

        const target = event.target as HTMLElement | null;
        const onControl =
            target?.closest('button, a, [role="slider"]') !== null &&
            target?.closest('button, a, [role="slider"]') !== undefined;

        switch (event.key) {
            case ' ':
            case 'k':
                // Space on a focused button already activates that button.
                if (event.key === ' ' && onControl) {
                    return;
                }

                toggle();
                break;
            case 'ArrowLeft':
                player.seekBy(event.shiftKey ? -30_000 : -5_000);
                break;
            case 'ArrowRight':
                player.seekBy(event.shiftKey ? 30_000 : 5_000);
                break;
            case 'j':
                player.seekBy(-10_000);
                break;
            case 'l':
                player.seekBy(10_000);
                break;
            case 'ArrowUp':
                setVolume(volume.value + 0.1);
                break;
            case 'ArrowDown':
                setVolume(volume.value - 0.1);
                break;
            case 'm':
                muted.value = !muted.value;
                break;
            case 'f':
                if (canFullscreen.value) {
                    void toggleFullscreen();
                }

                break;
            case 'Home':
                player.seek(0);
                break;
            case 'End':
                player.seek(durationMs.value);
                break;
            default:
                return;
        }

        event.preventDefault();
    },
);

const controlClass =
    'flex size-8 shrink-0 items-center justify-center rounded-md text-white/85 transition-colors hover:bg-white/10 hover:text-white focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:outline-none';

defineExpose({ player });
</script>

<template>
    <div
        ref="root"
        class="recording-player flex flex-col overflow-hidden rounded-xl bg-[#0e0e13] text-white"
        :class="isFullscreen && 'rounded-none'"
        :data-master-ms="Math.round(timeMs)"
        :data-playing="playing"
        :data-buffering="buffering"
        :data-mode="layout.mode"
    >
        <!-- Stage -->
        <div
            ref="stage"
            class="relative min-h-0 w-full overflow-hidden"
            :class="
                isFullscreen
                    ? 'flex-1'
                    : audioOnly
                      ? 'h-56 sm:h-64'
                      : 'aspect-[4/3] max-h-[72vh] sm:aspect-video'
            "
            @click="toggle"
            @dblclick="canFullscreen && toggleFullscreen()"
        >
            <!-- Sound: one element per microphone or shared-audio track, never visible. -->
            <audio
                v-for="track in audioTracks"
                :key="track.file"
                :ref="
                    (element) =>
                        player.register(
                            track,
                            element as HTMLAudioElement | null,
                        )
                "
                :src="track.url"
                preload="auto"
                :data-track="track.file"
                :data-start-ms="track.start_ms"
            />

            <!-- People: a tile each, with initials underneath whatever their camera shows. -->
            <div
                v-for="tile in layout.tiles"
                v-show="rects.tiles[tile.identity]"
                :key="`tile-${tile.identity}`"
                class="absolute overflow-hidden rounded-lg bg-white/[0.06] transition-[left,top,width,height] duration-200 motion-reduce:transition-none"
                :style="rectStyle(rects.tiles[tile.identity])"
            >
                <div
                    v-if="!tile.camera"
                    class="flex size-full items-center justify-center"
                >
                    <span
                        class="flex aspect-square h-[46%] max-h-24 items-center justify-center rounded-full text-[clamp(11px,2.2cqw,28px)] font-semibold"
                        :style="avatarStyle(tile.name)"
                        >{{ initialsOf(tile.name) }}</span
                    >
                </div>
            </div>

            <!-- Pictures: every mounted camera and screen, placed wherever the layout wants it. -->
            <video
                v-for="track in videoTracks"
                :key="track.file"
                :ref="
                    (element) =>
                        player.register(
                            track,
                            element as HTMLVideoElement | null,
                        )
                "
                :src="track.url"
                preload="auto"
                muted
                playsinline
                disablepictureinpicture
                class="absolute transition-[left,top,width,height] duration-200 motion-reduce:transition-none"
                :class="
                    track.source === 'screen_share'
                        ? isStage(track)
                            ? 'object-contain'
                            : 'rounded-lg bg-black object-contain'
                        : 'rounded-lg bg-black object-cover'
                "
                :style="videoStyle(track)"
                :data-track="track.file"
                :data-start-ms="track.start_ms"
            />

            <!-- Names and the speaking ring sit above the pictures. -->
            <div
                v-for="tile in layout.tiles"
                v-show="rects.tiles[tile.identity]"
                :key="`label-${tile.identity}`"
                class="pointer-events-none absolute rounded-lg transition-[left,top,width,height,box-shadow] duration-200 motion-reduce:transition-none"
                :class="
                    tile.speaking &&
                    'shadow-[inset_0_0_0_2px_var(--color-emerald-400)]'
                "
                :style="rectStyle(rects.tiles[tile.identity])"
                data-test="player-tile"
                :data-speaking="tile.speaking"
            >
                <span
                    class="absolute bottom-1 left-1 flex max-w-[calc(100%-0.5rem)] items-center gap-1 rounded bg-black/55 px-1.5 py-0.5 text-[11px] leading-tight backdrop-blur-sm"
                >
                    <AudioLines
                        v-if="tile.speaking"
                        class="size-3 shrink-0 text-emerald-300"
                    />
                    <span class="truncate">{{ tile.name }}</span>
                </span>
            </div>

            <!-- Other screens being shared at this moment: click to bring one to the stage. -->
            <button
                v-for="screen in otherScreens"
                :key="`thumb-${screen.file}`"
                type="button"
                class="absolute rounded-lg ring-1 ring-white/20 transition-[left,top,width,height] duration-200 hover:ring-2 hover:ring-white/70 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                :style="rectStyle(rects.thumbnails[screen.file])"
                :title="`Show ${screen.participant.name}'s screen`"
                @click.stop="preferredScreen = screen.file"
            >
                <span
                    class="absolute bottom-1 left-1 flex items-center gap-1 rounded bg-black/55 px-1.5 py-0.5 text-[11px] leading-tight backdrop-blur-sm"
                    ><MonitorUp class="size-3" />
                    {{ screen.participant.name }}</span
                >
            </button>

            <span
                v-if="layout.mode === 'screen' && layout.screen"
                class="pointer-events-none absolute top-2 left-2 hidden items-center gap-1.5 rounded-md bg-black/55 px-2 py-1 text-[11.5px] backdrop-blur-sm sm:flex"
            >
                <MonitorUp class="size-3.5" />
                {{ layout.screen.participant.name }}'s screen
            </span>

            <!-- Voices only: the people in the room, the one speaking lit up. -->
            <div
                v-if="layout.mode === 'audio'"
                class="absolute inset-0 flex flex-wrap items-center justify-center gap-x-8 gap-y-5 p-6"
            >
                <div
                    v-for="tile in layout.tiles"
                    :key="tile.identity"
                    class="flex w-24 flex-col items-center gap-2 text-center"
                    data-test="player-voice"
                    :data-speaking="tile.speaking"
                >
                    <span
                        class="flex size-16 items-center justify-center rounded-full text-lg font-semibold transition-shadow duration-200"
                        :class="
                            tile.speaking &&
                            'shadow-[0_0_0_3px_#0e0e13,0_0_0_5px_var(--color-emerald-400)]'
                        "
                        :style="avatarStyle(tile.name)"
                        >{{ initialsOf(tile.name) }}</span
                    >
                    <span class="w-full truncate text-[12px] text-white/80">{{
                        tile.name
                    }}</span>
                </div>
            </div>

            <p
                v-if="layout.mode === 'empty' && started"
                class="absolute inset-0 flex items-center justify-center px-6 text-center text-[13px] text-white/55"
            >
                Nobody was speaking or sharing at this point in the recording.
            </p>

            <!-- Start, buffering, finished. -->
            <div
                v-if="!started || ended"
                class="absolute inset-0 flex items-center justify-center bg-black/35"
            >
                <button
                    type="button"
                    class="flex size-16 items-center justify-center rounded-full bg-white text-[#0e0e13] shadow-lg transition-transform hover:scale-105 focus-visible:ring-4 focus-visible:ring-white/50 focus-visible:outline-none active:scale-95"
                    :aria-label="ended ? 'Play again' : 'Play recording'"
                    @click.stop="play"
                >
                    <RotateCcw v-if="ended" class="size-6" />
                    <Play v-else class="ml-1 size-7 fill-current" />
                </button>
            </div>
            <div
                v-else-if="buffering"
                class="pointer-events-none absolute inset-0 flex items-center justify-center"
                role="status"
                aria-label="Loading"
            >
                <span
                    class="size-10 animate-spin rounded-full border-[3px] border-white/25 border-t-white"
                />
            </div>
        </div>

        <!-- Controls -->
        <div class="shrink-0 bg-black/40 px-3 pt-2.5 pb-2" @click.stop>
            <div
                ref="bar"
                role="slider"
                tabindex="0"
                aria-label="Seek"
                :aria-valuemin="0"
                :aria-valuemax="Math.round(durationMs / 1000)"
                :aria-valuenow="Math.round(timeMs / 1000)"
                :aria-valuetext="`${formatClock(timeMs)} of ${formatClock(durationMs)}`"
                class="group/bar relative h-5 cursor-pointer touch-none rounded focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:outline-none"
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @pointercancel="scrubbing = false"
                @pointerleave="hover = null"
            >
                <div
                    class="absolute inset-x-0 top-1/2 h-1 -translate-y-1/2 rounded-full bg-white/20 transition-[height] group-hover/bar:h-1.5"
                >
                    <!-- When a screen was being shared. -->
                    <div
                        v-for="(segment, index) in segments"
                        :key="index"
                        class="absolute inset-y-0 rounded-full bg-sky-300/45"
                        :style="{
                            left: `${segment.from * 100}%`,
                            width: `${(segment.to - segment.from) * 100}%`,
                        }"
                        :title="segment.label"
                        data-test="timeline-segment"
                    />
                    <div
                        class="absolute inset-y-0 left-0 rounded-full bg-white"
                        :style="{ width: `${progress * 100}%` }"
                    />
                </div>
                <!-- Joins, leaves, interruptions. -->
                <span
                    v-for="(marker, index) in markers"
                    :key="index"
                    class="absolute top-1/2 h-2.5 w-0.5 -translate-x-1/2 -translate-y-1/2 rounded-full"
                    :class="
                        marker.type === 'restarted'
                            ? 'bg-amber-300'
                            : marker.type === 'joined'
                              ? 'bg-emerald-300/80'
                              : 'bg-white/45'
                    "
                    :style="{ left: `${marker.at * 100}%` }"
                    :title="marker.label"
                    data-test="timeline-marker"
                />
                <span
                    class="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white opacity-0 shadow transition-opacity group-hover/bar:opacity-100"
                    :class="scrubbing && 'opacity-100'"
                    :style="{ left: `${progress * 100}%` }"
                />
                <span
                    v-if="hover !== null"
                    class="pointer-events-none absolute -top-6 -translate-x-1/2 rounded bg-black/80 px-1.5 py-0.5 text-[11px] tabular-nums"
                    :style="{ left: `${hover * 100}%` }"
                    >{{ formatClock(hover * durationMs) }}</span
                >
            </div>

            <div class="mt-0.5 flex items-center gap-1">
                <button
                    type="button"
                    :class="controlClass"
                    :aria-label="playing ? 'Pause' : 'Play'"
                    :title="playing ? 'Pause (space)' : 'Play (space)'"
                    data-test="player-toggle"
                    @click="toggle"
                >
                    <Pause v-if="playing" class="size-4.5 fill-current" />
                    <Play v-else class="size-4.5 fill-current" />
                </button>
                <span
                    class="px-1.5 text-[12px] whitespace-nowrap text-white/80 tabular-nums"
                    aria-hidden="true"
                >
                    {{ formatClock(timeMs) }}
                    <span class="text-white/40">/</span>
                    {{ formatClock(durationMs) }}
                </span>

                <span
                    v-if="failed.size > 0"
                    class="ml-1 hidden truncate text-[11.5px] text-amber-300 sm:inline"
                    role="status"
                    >{{ plural(failed.size, 'track') }} could not be
                    loaded</span
                >

                <span class="flex-1" />

                <div class="group/vol flex items-center">
                    <button
                        type="button"
                        :class="controlClass"
                        :aria-label="muted ? 'Unmute' : 'Mute'"
                        :aria-pressed="muted"
                        :title="muted ? 'Unmute (m)' : 'Mute (m)'"
                        @click="muted = !muted"
                    >
                        <component :is="volumeIcon" class="size-4.5" />
                    </button>
                    <input
                        type="range"
                        min="0"
                        max="1"
                        step="0.05"
                        :value="muted ? 0 : volume"
                        aria-label="Volume"
                        class="hidden h-1 w-0 cursor-pointer appearance-none rounded-full bg-white/25 accent-white opacity-0 transition-all group-focus-within/vol:mx-1.5 group-focus-within/vol:w-20 group-focus-within/vol:opacity-100 group-hover/vol:mx-1.5 group-hover/vol:w-20 group-hover/vol:opacity-100 sm:block"
                        @input="
                            setVolume(
                                Number(
                                    ($event.target as HTMLInputElement).value,
                                ),
                            )
                        "
                    />
                </div>
                <button
                    type="button"
                    :class="
                        cn(
                            controlClass,
                            'w-auto px-2 text-[12px] font-medium tabular-nums',
                        )
                    "
                    :aria-label="`Playback speed ${rate}×. Click to change.`"
                    title="Playback speed"
                    data-test="player-rate"
                    @click="cycleRate"
                >
                    {{ rate }}×
                </button>
                <button
                    v-if="canFullscreen"
                    type="button"
                    :class="controlClass"
                    :aria-label="
                        isFullscreen ? 'Exit full screen' : 'Full screen'
                    "
                    :title="
                        isFullscreen
                            ? 'Exit full screen (f)'
                            : 'Full screen (f)'
                    "
                    @click="toggleFullscreen()"
                >
                    <Minimize v-if="isFullscreen" class="size-4.5" />
                    <Maximize v-else class="size-4.5" />
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.recording-player {
    container-type: inline-size;
}

.recording-player:fullscreen {
    width: 100vw;
    height: 100vh;
}

input[type='range']::-webkit-slider-thumb {
    appearance: none;
    width: 12px;
    height: 12px;
    border-radius: 999px;
    background: white;
}

input[type='range']::-moz-range-thumb {
    width: 12px;
    height: 12px;
    border: 0;
    border-radius: 999px;
    background: white;
}
</style>
