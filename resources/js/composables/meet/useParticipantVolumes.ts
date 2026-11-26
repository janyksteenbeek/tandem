import type { InjectionKey, Ref } from 'vue';
import { inject, onMounted, provide, ref } from 'vue';
import {
    FULL_VOLUME,
    canAdjustVolume,
    clampVolume,
    loadVolumes,
    saveVolumes,
    withVolume,
} from '@/lib/meet/volumes';
import type { ParticipantVolumes } from '@/lib/meet/volumes';

export type ParticipantVolumeControls = {
    /** False where the browser only allows muting (iOS), so no slider should be offered. */
    adjustable: Ref<boolean>;
    volumeOf: (identity: string) => number;
    setVolume: (identity: string, volume: number) => void;
    /** Silence someone for this listener, or bring them back to where they were. */
    toggleMuted: (identity: string) => void;
};

const key: InjectionKey<ParticipantVolumeControls> = Symbol(
    'meet-participant-volumes',
);

/**
 * How loud this listener wants each other person. Created once by the meeting room and
 * shared with the tiles and the people panel; remembered for this call in this tab.
 */
export function provideParticipantVolumes(
    callUuid: string,
): ParticipantVolumeControls {
    const volumes = ref<ParticipantVolumes>({});
    const adjustable = ref(true);
    /** The level to return to when someone is un-silenced. Not worth remembering longer. */
    const beforeMute: ParticipantVolumes = {};

    const volumeOf = (identity: string): number =>
        volumes.value[identity] ?? FULL_VOLUME;

    const setVolume = (identity: string, volume: number): void => {
        volumes.value = withVolume(volumes.value, identity, volume);
        saveVolumes(callUuid, volumes.value);
    };

    const toggleMuted = (identity: string): void => {
        const current = volumeOf(identity);

        if (current > 0) {
            beforeMute[identity] = current;
            setVolume(identity, 0);
        } else {
            setVolume(identity, clampVolume(beforeMute[identity]));
        }
    };

    onMounted(() => {
        volumes.value = loadVolumes(callUuid);
        adjustable.value = canAdjustVolume();
    });

    const controls = { adjustable, volumeOf, setVolume, toggleMuted };

    provide(key, controls);

    return controls;
}

export function useParticipantVolumes(): ParticipantVolumeControls {
    const controls = inject(key, null);

    if (!controls) {
        throw new Error('Participant volumes were not provided.');
    }

    return controls;
}
