<script setup lang="ts">
import { MicOff, Pin, PinOff, SignalLow } from '@lucide/vue';
import { computed } from 'vue';
import QualityBars from '@/components/meet/QualityBars.vue';
import TrackVideo from '@/components/meet/TrackVideo.vue';
import UserAvatar from '@/components/shared/UserAvatar.vue';
import type { RoomParticipant } from '@/lib/meet/types';
import { cn } from '@/lib/utils';

/**
 * One person: their camera, or their avatar when it is off. Shows who is speaking, who is
 * muted and whose connection is struggling.
 */
const props = withDefaults(
    defineProps<{
        participant: RoomParticipant;
        size?: 'sm' | 'md' | 'lg';
        pinned?: boolean;
        pinnable?: boolean;
        /** Cameras are switched off for this viewer to save data. */
        audioOnly?: boolean;
        class?: string;
    }>(),
    { size: 'md', pinned: false, pinnable: false, audioOnly: false, class: '' },
);

const emit = defineEmits<{ pin: [] }>();

const showVideo = computed(
    () =>
        props.participant.cameraTrack !== null &&
        !props.participant.cameraPaused,
);
const label = computed(() =>
    props.participant.isLocal
        ? `${props.participant.name} (you)`
        : props.participant.name,
);
/** Only flag a connection on the tile when it is bad enough to explain choppy audio. */
const troubled = computed(() =>
    ['poor', 'lost'].includes(props.participant.quality),
);

const avatarSize = {
    sm: 'size-10 text-sm',
    md: 'size-16 text-xl',
    lg: 'size-24 text-3xl',
};
</script>

<template>
    <div
        :class="
            cn(
                'group/tile relative isolate overflow-hidden rounded-2xl bg-[oklch(0.93_0.006_285)] transition-[box-shadow] duration-200 dark:bg-[oklch(0.235_0.01_275)]',
                participant.isSpeaking && participant.micEnabled
                    ? 'shadow-[0_0_0_2.5px_var(--brand),0_0_28px_-6px_var(--brand)]'
                    : 'shadow-[0_0_0_1px_oklch(0_0_0/0.06)] dark:shadow-[0_0_0_1px_oklch(1_0_0/0.07)]',
                props.class,
            )
        "
        :data-identity="participant.identity"
        :data-speaking="participant.isSpeaking && participant.micEnabled"
        data-testid="participant-tile"
    >
        <TrackVideo
            v-if="showVideo"
            :track="participant.cameraTrack"
            :mirror="participant.isLocal"
            :label="`${label}'s camera`"
        />

        <div
            v-else
            class="absolute inset-0 flex flex-col items-center justify-center gap-2"
        >
            <span class="relative">
                <span
                    v-if="participant.isSpeaking && participant.micEnabled"
                    class="absolute -inset-2 animate-ping rounded-full bg-brand/25 [animation-duration:1.4s]"
                />
                <UserAvatar
                    :name="participant.name"
                    :src="participant.meta.avatar"
                    :initials="participant.meta.initials"
                    :class="cn('relative', avatarSize[size])"
                />
            </span>
            <span
                v-if="participant.cameraPaused && size !== 'sm'"
                class="flex items-center gap-1.5 rounded-full bg-foreground/[0.07] px-2.5 py-1 text-[11px] font-medium text-muted-foreground"
            >
                <SignalLow class="size-3" /> Video paused to keep audio clear
            </span>
            <span
                v-else-if="
                    audioOnly &&
                    participant.cameraEnabled &&
                    !participant.isLocal &&
                    size !== 'sm'
                "
                class="rounded-full bg-foreground/[0.07] px-2.5 py-1 text-[11px] font-medium text-muted-foreground"
            >
                Audio only
            </span>
        </div>

        <!-- Name -->
        <div
            :class="
                cn(
                    'absolute bottom-2 left-2 flex max-w-[calc(100%-1rem)] items-center gap-1.5 rounded-lg px-2 py-1 font-medium backdrop-blur-md',
                    size === 'sm' ? 'text-[11px]' : 'text-xs',
                    showVideo
                        ? 'bg-black/45 text-white'
                        : 'bg-background/70 text-foreground',
                )
            "
        >
            <MicOff
                v-if="!participant.micEnabled"
                class="size-3 shrink-0 text-destructive"
                aria-label="Muted"
            />
            <span
                v-else-if="participant.isSpeaking"
                class="flex h-3 items-center gap-[2px]"
                aria-label="Speaking"
            >
                <span
                    class="h-1.5 w-[2.5px] animate-pulse rounded-full bg-brand"
                />
                <span
                    class="h-3 w-[2.5px] animate-pulse rounded-full bg-brand [animation-delay:120ms]"
                />
                <span
                    class="h-2 w-[2.5px] animate-pulse rounded-full bg-brand [animation-delay:240ms]"
                />
            </span>
            <span class="truncate">{{ label }}</span>
        </div>

        <!-- Connection -->
        <div
            v-if="troubled"
            :class="
                cn(
                    'absolute top-2 right-2 flex items-center rounded-lg px-1.5 py-1 backdrop-blur-md',
                    showVideo ? 'bg-black/45 text-white' : 'bg-background/70',
                )
            "
        >
            <QualityBars :level="participant.quality" size="sm" />
        </div>

        <!-- Pin -->
        <button
            v-if="pinnable"
            type="button"
            :aria-label="
                pinned ? `Unpin ${participant.name}` : `Pin ${participant.name}`
            "
            :aria-pressed="pinned"
            :class="
                cn(
                    'absolute top-2 left-2 flex size-7 items-center justify-center rounded-lg backdrop-blur-md transition-opacity outline-none focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring',
                    showVideo
                        ? 'bg-black/45 text-white hover:bg-black/60'
                        : 'bg-background/70 hover:bg-background',
                    pinned
                        ? 'opacity-100'
                        : 'opacity-0 group-hover/tile:opacity-100',
                )
            "
            @click="emit('pin')"
        >
            <PinOff v-if="pinned" class="size-3.5" />
            <Pin v-else class="size-3.5" />
        </button>
    </div>
</template>
