import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import {
    SIDEBAR_STORAGE_KEY,
    provideSidebarState,
    useSidebarState,
} from '@/composables/useSidebarState';

const Child = defineComponent({
    setup() {
        const state = useSidebarState();

        return () =>
            h(
                'button',
                { onClick: state.toggle },
                state.collapsed.value ? 'collapsed' : 'open',
            );
    },
});

const Shell = defineComponent({
    setup() {
        provideSidebarState();

        return () => h(Child);
    },
});

describe('useSidebarState', () => {
    beforeEach(() => window.localStorage.clear());

    it('toggles and persists the collapsed state', async () => {
        const wrapper = mount(Shell);
        await nextTick();

        expect(wrapper.text()).toBe('open');

        await wrapper.find('button').trigger('click');

        expect(wrapper.text()).toBe('collapsed');
        expect(window.localStorage.getItem(SIDEBAR_STORAGE_KEY)).toBe('true');
    });

    it('restores a stored preference after mount', async () => {
        window.localStorage.setItem(SIDEBAR_STORAGE_KEY, 'true');

        const wrapper = mount(Shell);
        await nextTick();

        expect(wrapper.text()).toBe('collapsed');
    });

    it('is always open outside the shell', () => {
        const wrapper = mount(Child);

        expect(wrapper.text()).toBe('open');
    });
});
