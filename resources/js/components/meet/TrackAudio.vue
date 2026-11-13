<script setup lang="ts">
import type { Track } from 'livekit-client';
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Plays one remote audio track. The element is invisible; the room routes it to the
 * selected speaker.
 */
const props = defineProps<{ track: Track }>();

const audio = ref<HTMLAudioElement | null>(null);

watch(
    [() => props.track, audio],
    ([track, element], previous) => {
        const [previousTrack, previousElement] = previous ?? [null, null];

        if (previousTrack && previousElement && previousTrack !== track) {
            previousTrack.detach(previousElement);
        }

        if (track && element) {
            track.attach(element);
        }
    },
    { immediate: true, flush: 'post' },
);

onBeforeUnmount(() => {
    if (audio.value) {
        props.track.detach(audio.value);
    }
});
</script>

<template>
    <audio ref="audio" autoplay class="hidden" />
</template>
