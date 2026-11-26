import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import {
    provideParticipantVolumes,
    useParticipantVolumes,
} from '@/composables/meet/useParticipantVolumes';
import type { ParticipantVolumeControls } from '@/composables/meet/useParticipantVolumes';
import { loadVolumes, saveVolumes } from '@/lib/meet/volumes';

function mountRoom(callUuid = 'call-1') {
    let provided: ParticipantVolumeControls | undefined;
    let injected: ParticipantVolumeControls | undefined;

    const Tile = defineComponent({
        setup() {
            injected = useParticipantVolumes();

            return () => h('div', String(injected?.volumeOf('p_a')));
        },
    });

    const Room = defineComponent({
        setup() {
            provided = provideParticipantVolumes(callUuid);

            return () => h(Tile);
        },
    });

    const wrapper = mount(Room);

    return {
        wrapper,
        volumes: provided as ParticipantVolumeControls,
        injected: injected as ParticipantVolumeControls,
    };
}

describe('participant volumes', () => {
    beforeEach(() => window.sessionStorage.clear());

    it('has everyone at normal volume to begin with', () => {
        const { volumes } = mountRoom();

        expect(volumes.volumeOf('p_a')).toBe(1);
    });

    it('shares one set of levels with everything below the room', async () => {
        const { wrapper, volumes, injected } = mountRoom();

        injected.setVolume('p_a', 0.4);
        await wrapper.vm.$nextTick();

        expect(volumes.volumeOf('p_a')).toBe(0.4);
        expect(volumes.volumeOf('p_b')).toBe(1);
        expect(wrapper.text()).toBe('0.4');
    });

    it('keeps a level within what an audio element accepts', () => {
        const { volumes } = mountRoom();

        volumes.setVolume('p_a', 4);
        expect(volumes.volumeOf('p_a')).toBe(1);

        volumes.setVolume('p_a', -1);
        expect(volumes.volumeOf('p_a')).toBe(0);
    });

    it('silences someone and brings them back to where they were', () => {
        const { volumes } = mountRoom();

        volumes.setVolume('p_a', 0.6);
        volumes.toggleMuted('p_a');
        expect(volumes.volumeOf('p_a')).toBe(0);

        volumes.toggleMuted('p_a');
        expect(volumes.volumeOf('p_a')).toBe(0.6);
    });

    it('brings someone back to normal when the earlier level is unknown', () => {
        saveVolumes('call-1', { p_a: 0 });

        const { volumes } = mountRoom();

        expect(volumes.volumeOf('p_a')).toBe(0);

        volumes.toggleMuted('p_a');
        expect(volumes.volumeOf('p_a')).toBe(1);
    });

    it('remembers levels for this call only', () => {
        mountRoom('call-1').volumes.setVolume('p_a', 0.3);

        expect(loadVolumes('call-1')).toEqual({ p_a: 0.3 });
        expect(mountRoom('call-1').volumes.volumeOf('p_a')).toBe(0.3);
        expect(mountRoom('call-2').volumes.volumeOf('p_a')).toBe(1);
    });

    it('refuses to work outside a meeting room', () => {
        const Orphan = defineComponent({
            setup() {
                useParticipantVolumes();

                return () => h('div');
            },
        });

        expect(() =>
            mount(Orphan, { global: { config: { warnHandler: () => {} } } }),
        ).toThrow('Participant volumes were not provided.');
    });
});
