<script setup lang="ts">
import { useElementSize, useFullscreen } from '@vueuse/core';
import { Maximize, Minimize, MonitorUp, MonitorX } from '@lucide/vue';
import { computed, ref } from 'vue';
import ParticipantTile from '@/components/meet/ParticipantTile.vue';
import TrackVideo from '@/components/meet/TrackVideo.vue';
import { Button } from '@/components/ui/button';
import { computeGrid, pickStageScreen, stageMode } from '@/lib/meet/layout';
import type { RoomParticipant, ScreenShare } from '@/lib/meet/types';
import { cn } from '@/lib/utils';

/**
 * The picture area of the meeting. A shared screen (or a pinned person) takes the stage
 * with everyone else in a strip; otherwise people are laid out in the largest grid that
 * fits.
 */
const props = defineProps<{
    participants: RoomParticipant[];
    screens: ScreenShare[];
    selectedScreenId: string | null;
    pinnedIdentity: string | null;
    audioOnly: boolean;
}>();

const emit = defineEmits<{
    'update:selectedScreenId': [id: string | null];
    'update:pinnedIdentity': [identity: string | null];
    stopSharing: [];
}>();

const container = ref<HTMLElement | null>(null);
const stage = ref<HTMLElement | null>(null);
const { width, height } = useElementSize(container);
const {
    isFullscreen,
    toggle: toggleFullscreen,
    isSupported: canFullscreen,
} = useFullscreen(stage);

const stageScreen = computed(() =>
    pickStageScreen(props.screens, props.selectedScreenId),
);
const pinned = computed(
    () =>
        props.participants.find((p) => p.identity === props.pinnedIdentity) ??
        null,
);
const mode = computed(() =>
    stageMode(
        props.participants.length,
        stageScreen.value !== null,
        pinned.value !== null,
    ),
);

const local = computed(() => props.participants.find((p) => p.isLocal) ?? null);
const others = computed(() => props.participants.filter((p) => !p.isLocal));

/** The person whose screen is on stage keeps a small camera picture over it. */
const presenter = computed(() => {
    const screen = stageScreen.value;

    if (!screen) {
        return null;
    }

    const person = props.participants.find(
        (p) => p.identity === screen.identity,
    );

    return person?.cameraTrack && !person.isLocal ? person : null;
});

const strip = computed(() =>
    props.participants.filter(
        (p) =>
            p.identity !== presenter.value?.identity &&
            !(
                stageScreen.value === null &&
                p.identity === pinned.value?.identity
            ),
    ),
);

const GAP = 12;
const grid = computed(() =>
    computeGrid(props.participants.length, width.value, height.value, GAP),
);

const tileSize = computed(() =>
    grid.value.tileWidth > 520
        ? 'lg'
        : grid.value.tileWidth > 240
          ? 'md'
          : 'sm',
);

const togglePin = (identity: string) =>
    emit(
        'update:pinnedIdentity',
        props.pinnedIdentity === identity ? null : identity,
    );

defineExpose({ toggleFullscreen });
</script>

<template>
    <div
        ref="container"
        class="relative min-h-0 min-w-0 flex-1"
        data-testid="stage"
        :data-mode="mode"
    >
        <!-- Focus: a screen or a pinned person on stage ------------------------------- -->
        <div
            v-if="mode === 'focus'"
            class="flex size-full flex-col gap-3 lg:flex-row"
        >
            <div
                ref="stage"
                class="group/stage relative min-h-0 flex-1 overflow-hidden rounded-2xl bg-[oklch(0.16_0.008_275)] shadow-[0_0_0_1px_oklch(0_0_0/0.08)] dark:shadow-[0_0_0_1px_oklch(1_0_0/0.07)]"
            >
                <template v-if="stageScreen">
                    <!-- Looking at your own shared screen is a hall of mirrors: show a
                         small preview and a way to stop instead. -->
                    <div
                        v-if="stageScreen.isLocal"
                        class="flex size-full flex-col items-center justify-center gap-5 px-6 text-center text-white"
                        data-testid="sharing-notice"
                    >
                        <div
                            class="aspect-video w-full max-w-sm overflow-hidden rounded-xl ring-1 ring-white/15"
                        >
                            <TrackVideo
                                :track="stageScreen.track"
                                fit="contain"
                                label="Your shared screen"
                            />
                        </div>
                        <div class="space-y-1">
                            <p
                                class="flex items-center justify-center gap-2 text-base font-semibold"
                            >
                                <MonitorUp class="size-4 text-brand" /> You are
                                sharing your screen
                            </p>
                            <p class="text-sm text-white/60">
                                Everyone in the meeting can see it.
                            </p>
                        </div>
                        <Button
                            variant="destructive"
                            class="rounded-xl"
                            @click="emit('stopSharing')"
                        >
                            <MonitorX /> Stop sharing
                        </Button>
                    </div>
                    <TrackVideo
                        v-else
                        :track="stageScreen.track"
                        fit="contain"
                        :label="`${stageScreen.name}'s screen`"
                        data-testid="screen-video"
                    />

                    <!-- Whose screen, and switching between several -->
                    <div
                        class="absolute top-3 left-3 flex max-w-[calc(100%-5rem)] flex-wrap items-center gap-1.5"
                    >
                        <template v-if="screens.length > 1">
                            <button
                                v-for="screen in screens"
                                :key="screen.id"
                                type="button"
                                :aria-pressed="screen.id === stageScreen.id"
                                :class="
                                    cn(
                                        'flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium backdrop-blur-md transition-colors outline-none focus-visible:ring-2 focus-visible:ring-white/70',
                                        screen.id === stageScreen.id
                                            ? 'bg-brand text-brand-foreground'
                                            : 'bg-black/50 text-white hover:bg-black/65',
                                    )
                                "
                                @click="
                                    emit('update:selectedScreenId', screen.id)
                                "
                            >
                                <MonitorUp class="size-3.5" />
                                {{
                                    screen.isLocal
                                        ? 'Your screen'
                                        : `${screen.name}'s screen`
                                }}
                            </button>
                        </template>
                        <span
                            v-else-if="!stageScreen.isLocal"
                            class="flex items-center gap-1.5 rounded-lg bg-black/50 px-2.5 py-1.5 text-xs font-medium text-white backdrop-blur-md"
                        >
                            <MonitorUp class="size-3.5" />
                            {{ stageScreen.name }}'s screen
                        </span>
                    </div>

                    <ParticipantTile
                        v-if="presenter"
                        :participant="presenter"
                        size="sm"
                        class="absolute right-3 bottom-3 aspect-video w-[18%] max-w-56 min-w-32 shadow-float"
                    />
                </template>

                <ParticipantTile
                    v-else-if="pinned"
                    :participant="pinned"
                    size="lg"
                    pinnable
                    pinned
                    :audio-only="audioOnly"
                    class="size-full rounded-none"
                    @pin="togglePin(pinned.identity)"
                />

                <button
                    v-if="canFullscreen"
                    type="button"
                    :aria-label="
                        isFullscreen ? 'Exit full screen' : 'Full screen'
                    "
                    class="absolute top-3 right-3 flex size-8 items-center justify-center rounded-lg bg-black/50 text-white opacity-0 backdrop-blur-md transition-opacity outline-none group-hover/stage:opacity-100 hover:bg-black/65 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-white/70"
                    @click="toggleFullscreen"
                >
                    <Minimize v-if="isFullscreen" class="size-4" />
                    <Maximize v-else class="size-4" />
                </button>
            </div>

            <div
                v-if="strip.length > 0"
                class="flex h-24 shrink-0 gap-2 overflow-x-auto sm:h-28 lg:h-auto lg:w-52 lg:flex-col lg:overflow-x-hidden lg:overflow-y-auto xl:w-60"
                aria-label="Participants"
            >
                <ParticipantTile
                    v-for="participant in strip"
                    :key="participant.identity"
                    :participant="participant"
                    size="sm"
                    :pinnable="stageScreen === null"
                    :audio-only="audioOnly"
                    class="aspect-video h-full shrink-0 lg:h-auto lg:w-full"
                    @pin="togglePin(participant.identity)"
                />
            </div>
        </div>

        <!-- Duo: the other person fills the stage, you are a small picture ----------- -->
        <div
            v-else-if="mode === 'duo' && others[0] && local"
            class="relative size-full"
        >
            <ParticipantTile
                :participant="others[0]"
                size="lg"
                :audio-only="audioOnly"
                class="size-full"
            />
            <ParticipantTile
                :participant="local"
                size="sm"
                class="absolute right-3 bottom-3 aspect-video w-[24%] max-w-72 min-w-32 shadow-float ring-2 ring-background/60"
            />
        </div>

        <!-- Grid (and solo, which is a grid of one) ------------------------------------ -->
        <div
            v-else
            class="flex size-full flex-wrap content-center items-center justify-center"
            :style="{ gap: `${GAP}px` }"
        >
            <ParticipantTile
                v-for="participant in participants"
                :key="participant.identity"
                :participant="participant"
                :size="tileSize"
                :pinnable="participants.length > 2"
                :audio-only="audioOnly"
                :style="{
                    width: `${grid.tileWidth}px`,
                    height: `${grid.tileHeight}px`,
                }"
                class="transition-[width,height] duration-300 ease-out"
                @pin="togglePin(participant.identity)"
            />
        </div>
    </div>
</template>
