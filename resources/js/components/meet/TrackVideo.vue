<script setup lang="ts">
import type { Track } from 'livekit-client';
import { onBeforeUnmount, ref, watch } from 'vue';
import { cn } from '@/lib/utils';

/**
 * A <video> bound to a media track: one from the room (attached through the SDK, which is
 * what lets the server send only the size that is actually shown) or a raw browser track
 * (the pre-join preview).
 */
const props = withDefaults(
    defineProps<{
        track: Track | MediaStreamTrack | null;
        mirror?: boolean;
        fit?: 'cover' | 'contain';
        label?: string;
        class?: string;
    }>(),
    { mirror: false, fit: 'cover', label: 'Video', class: '' },
);

const video = ref<HTMLVideoElement | null>(null);

type Bound = Track | MediaStreamTrack;

const isRoomTrack = (track: Bound): track is Track => 'attach' in track;

const release = (track: Bound | null, element: HTMLVideoElement | null) => {
    if (!track || !element) {
        return;
    }

    if (isRoomTrack(track)) {
        track.detach(element);
    } else {
        element.srcObject = null;
    }
};

watch(
    [() => props.track, video],
    ([track, element], previous) => {
        const [previousTrack, previousElement] = previous ?? [null, null];

        if (previousTrack !== track || previousElement !== element) {
            release(previousTrack ?? null, previousElement ?? null);
        }

        if (!track || !element) {
            return;
        }

        if (isRoomTrack(track)) {
            track.attach(element);
        } else {
            element.srcObject = new MediaStream([track]);
            void element.play().catch(() => {});
        }
    },
    { immediate: true, flush: 'post' },
);

onBeforeUnmount(() => release(props.track, video.value));
</script>

<template>
    <video
        ref="video"
        autoplay
        playsinline
        muted
        :aria-label="label"
        :class="
            cn(
                'size-full bg-black',
                fit === 'cover' ? 'object-cover' : 'object-contain',
                mirror && '-scale-x-100',
                props.class,
            )
        "
    />
</template>
