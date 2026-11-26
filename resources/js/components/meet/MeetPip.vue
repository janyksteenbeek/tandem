<script setup lang="ts">
import { Mic, MicOff, PhoneOff, Video, VideoOff } from '@lucide/vue';
import { computed } from 'vue';
import ParticipantTile from '@/components/meet/ParticipantTile.vue';
import TrackVideo from '@/components/meet/TrackVideo.vue';
import type { RoomParticipant, ScreenShare } from '@/lib/meet/types';
import { cn } from '@/lib/utils';

/**
 * The meeting as it appears in the floating picture-in-picture window: someone's shared
 * screen or else a few people, and the three controls worth having without the tab.
 *
 * This renders inside another document, so nothing here may open a tooltip, menu or dialog:
 * those are placed in the main page and would appear there instead.
 */
const props = defineProps<{
    participants: RoomParticipant[];
    /** People in the meeting who did not fit. */
    hidden: number;
    screen: ScreenShare | null;
    micEnabled: boolean;
    micBusy: boolean;
    cameraEnabled: boolean;
    audioOnly: boolean;
}>();

const emit = defineEmits<{
    toggleMic: [];
    toggleCamera: [];
    leave: [];
}>();

/** Side by side while the window is wide, stacked once it is dragged tall. */
const grid = computed(() =>
    props.participants.length <= 1
        ? 'grid-cols-1'
        : props.participants.length === 2
          ? 'grid-cols-2 portrait:grid-cols-1'
          : 'grid-cols-2',
);

const button =
    'flex size-9 shrink-0 items-center justify-center rounded-xl outline-none transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/60 disabled:opacity-40';
const neutral =
    'bg-foreground/[0.06] text-foreground hover:bg-foreground/[0.11] dark:bg-white/[0.08] dark:hover:bg-white/[0.14]';
const off = 'bg-destructive text-white hover:bg-destructive/90';
</script>

<template>
    <div
        class="fixed inset-0 flex flex-col gap-1.5 bg-background p-1.5 text-foreground"
        data-testid="meet-pip"
    >
        <div class="relative min-h-0 flex-1">
            <div
                v-if="screen"
                class="relative size-full overflow-hidden rounded-xl bg-[oklch(0.16_0.008_275)]"
            >
                <TrackVideo
                    :track="screen.track"
                    fit="contain"
                    :label="`${screen.name}'s screen`"
                />
                <span
                    class="absolute bottom-1.5 left-1.5 max-w-[calc(100%-0.75rem)] truncate rounded-lg bg-black/45 px-2 py-1 text-[11px] font-medium text-white backdrop-blur-md"
                    >{{ screen.name }}'s screen</span
                >
            </div>

            <div
                v-else
                :class="cn('grid size-full auto-rows-fr gap-1.5', grid)"
            >
                <ParticipantTile
                    v-for="participant in participants"
                    :key="participant.identity"
                    :participant="participant"
                    size="sm"
                    :audio-only="audioOnly"
                    class="min-h-0 min-w-0 rounded-xl"
                />
            </div>

            <span
                v-if="hidden > 0 && !screen"
                class="absolute top-1.5 right-1.5 rounded-lg bg-black/45 px-1.5 py-0.5 text-[11px] font-medium text-white tabular-nums backdrop-blur-md"
                :aria-label="`${hidden} more in the meeting`"
                >+{{ hidden }}</span
            >
        </div>

        <div
            class="flex shrink-0 items-center justify-center gap-1.5"
            role="toolbar"
            aria-label="Meeting controls"
        >
            <button
                type="button"
                :aria-label="
                    micEnabled ? 'Mute microphone' : 'Unmute microphone'
                "
                :aria-pressed="!micEnabled"
                :disabled="micBusy"
                :class="cn(button, micEnabled ? neutral : off)"
                data-testid="pip-mic"
                @click="emit('toggleMic')"
            >
                <component :is="micEnabled ? Mic : MicOff" class="size-4" />
            </button>
            <button
                type="button"
                :aria-label="
                    cameraEnabled ? 'Turn camera off' : 'Turn camera on'
                "
                :aria-pressed="!cameraEnabled"
                :class="cn(button, cameraEnabled ? neutral : off)"
                data-testid="pip-camera"
                @click="emit('toggleCamera')"
            >
                <component
                    :is="cameraEnabled ? Video : VideoOff"
                    class="size-4"
                />
            </button>
            <button
                type="button"
                aria-label="Leave the meeting"
                :class="cn(button, off, 'w-12')"
                data-testid="pip-leave"
                @click="emit('leave')"
            >
                <PhoneOff class="size-4" />
            </button>
        </div>
    </div>
</template>
