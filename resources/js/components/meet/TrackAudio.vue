<script setup lang="ts">
import type { RemoteAudioTrack, Track } from 'livekit-client';
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Plays one remote audio track. The element is invisible; the room routes it to the
 * selected speaker.
 */
const props = withDefaults(
    defineProps<{
        track: Track;
        /** How loud this listener wants it, from 0 (silent) to 1 (as sent). */
        volume?: number;
    }>(),
    { volume: 1 },
);

const audio = ref<HTMLAudioElement | null>(null);

/**
 * The level goes through the track so the SDK keeps it across re-attaches. Silence is also
 * set as `muted`, which works on iOS where a page cannot change an element's volume.
 */
const applyVolume = () => {
    (props.track as Partial<RemoteAudioTrack>).setVolume?.(props.volume);

    if (audio.value) {
        audio.value.muted = props.volume <= 0;
    }
};

watch(
    [() => props.track, audio],
    ([track, element], previous) => {
        const [previousTrack, previousElement] = previous ?? [null, null];

        if (previousTrack && previousElement && previousTrack !== track) {
            previousTrack.detach(previousElement);
        }

        if (track && element) {
            track.attach(element);
            // Attaching resets `muted` and skips a remembered level of zero.
            applyVolume();
        }
    },
    { immediate: true, flush: 'post' },
);

watch(() => props.volume, applyVolume);

onBeforeUnmount(() => {
    if (audio.value) {
        props.track.detach(audio.value);
    }
});
</script>

<template>
    <audio ref="audio" autoplay class="hidden" />
</template>
