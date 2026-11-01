import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';
import { provideAppShell, useShellColumn } from '@/composables/useAppShell';

const Column = defineComponent({ render: () => h('aside') });
const OtherColumn = defineComponent({ render: () => h('nav') });

const Page = defineComponent({
    props: { view: { type: String, required: true } },
    setup(props) {
        useShellColumn(() => ({
            component: Column,
            props: { activeView: props.view },
        }));

        return () => h('div');
    },
});

const OtherPage = defineComponent({
    setup() {
        useShellColumn(() => ({ component: OtherColumn }));

        return () => h('div');
    },
});

function mountShell() {
    const view = ref('inbox');
    const showPage = ref(true);
    const page = ref<'page' | 'other'>('page');
    let column: ReturnType<typeof provideAppShell> | undefined;

    const Shell = defineComponent({
        setup() {
            column = provideAppShell();

            return () =>
                showPage.value
                    ? page.value === 'page'
                        ? h(Page, { view: view.value })
                        : h(OtherPage)
                    : h('div');
        },
    });

    mount(Shell);

    return { view, showPage, page, column: column! };
}

describe('useAppShell', () => {
    it('exposes the column the mounted page registered', () => {
        const { column } = mountShell();

        expect(column.value?.component).toBe(Column);
        expect(column.value?.props).toEqual({ activeView: 'inbox' });
    });

    it('keeps the column in sync with the page props', async () => {
        const { column, view } = mountShell();

        view.value = 'sent';
        await nextTick();

        expect(column.value?.props).toEqual({ activeView: 'sent' });
    });

    it('clears the column when the page unmounts', async () => {
        const { column, showPage } = mountShell();

        showPage.value = false;
        await nextTick();

        expect(column.value).toBeNull();
    });

    it('swaps the column when a different page mounts', async () => {
        const { column, page } = mountShell();

        page.value = 'other';
        await nextTick();

        expect(column.value?.component).toBe(OtherColumn);
    });

    it('is a no-op outside the shell', () => {
        expect(() => mount(Page, { props: { view: 'inbox' } })).not.toThrow();
    });
});
