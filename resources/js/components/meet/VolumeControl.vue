<script setup lang="ts">
import { Volume1, Volume2, VolumeX } from '@lucide/vue';
import { computed } from 'vue';
import { useParticipantVolumes } from '@/composables/meet/useParticipantVolumes';
import { cn } from '@/lib/utils';

/**
 * How loud one other person is for this listener: a button that silences them and a slider
 * for everything in between. Changes nothing for anyone else.
 */
const props = withDefaults(
    defineProps<{
        identity: string;
        name: string;
        /**
         * `overlay` sits on a tile: the slider opens on hover, and only where the
         * surrounding `@container` is wide enough for it. `inline` is always open.
         */
        variant?: 'overlay' | 'inline';
    }>(),
    { variant: 'inline' },
);

const { adjustable, volumeOf, setVolume, toggleMuted } =
    useParticipantVolumes();

const volume = computed(() => volumeOf(props.identity));
const percent = computed(() => Math.round(volume.value * 100));
const silenced = computed(() => volume.value <= 0);
const icon = computed(() =>
    silenced.value ? VolumeX : volume.value < 0.5 ? Volume1 : Volume2,
);

const onInput = (event: Event) =>
    setVolume(
        props.identity,
        Number((event.target as HTMLInputElement).value) / 100,
    );
</script>

<template>
    <div
        :class="
            cn(
                'group/vol flex items-center',
                variant === 'overlay'
                    ? 'h-7 rounded-lg backdrop-blur-md'
                    : 'gap-2.5',
            )
        "
        data-testid="participant-volume"
    >
        <button
            type="button"
            :aria-label="
                silenced
                    ? `Unmute ${name} for yourself`
                    : `Mute ${name} for yourself`
            "
            :aria-pressed="silenced"
            :class="
                cn(
                    'flex size-7 shrink-0 items-center justify-center rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    variant === 'inline'
                        ? 'text-muted-foreground hover:bg-accent hover:text-foreground'
                        : 'hover:bg-current/15',
                    silenced && 'text-destructive hover:text-destructive',
                )
            "
            data-testid="participant-volume-mute"
            @click="toggleMuted(identity)"
        >
            <component :is="icon" class="size-3.5" />
        </button>

        <input
            v-if="adjustable"
            type="range"
            min="0"
            max="100"
            step="5"
            :value="percent"
            :aria-label="`Volume of ${name}`"
            :aria-valuetext="`${percent}%`"
            :class="
                cn(
                    'h-1 cursor-pointer appearance-none rounded-full',
                    variant === 'overlay'
                        ? 'hidden w-0 bg-current/30 accent-current opacity-0 transition-all group-focus-within/vol:mr-2.5 group-focus-within/vol:w-16 group-focus-within/vol:opacity-100 group-hover/vol:mr-2.5 group-hover/vol:w-16 group-hover/vol:opacity-100 @[10rem]:block'
                        : 'min-w-0 flex-1 bg-foreground/15 accent-brand',
                )
            "
            data-testid="participant-volume-slider"
            @input="onInput"
        />

        <span
            v-if="adjustable && variant === 'inline'"
            class="w-9 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
            >{{ percent }}%</span
        >
        <span
            v-else-if="variant === 'inline'"
            class="text-xs text-muted-foreground"
            >{{ silenced ? 'Muted for you' : 'Mute for you' }}</span
        >
    </div>
</template>
