import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import {
    PIP_WINDOW_SIZE,
    copyDocumentLook,
    documentPictureInPicture,
    pipSupport,
} from '@/lib/meet/pip';
import type { PipSupport } from '@/lib/meet/pip';

type Options = {
    /** The title of the floating window. */
    title: () => string;
    /** The video to float where only single-video picture-in-picture exists. */
    video: () => HTMLVideoElement | null;
    /** Told why nothing opened, in words for the person who asked. */
    onUnavailable: (message: string) => void;
};

/**
 * The meeting in a floating window. Where the browser can float a whole document, `target`
 * is the element inside that window to render into (with a `<Teleport>`); otherwise one
 * video from the page floats on its own and `target` stays empty.
 *
 * Opening needs a click or a key press: browsers refuse it from anything else.
 */
export function usePictureInPicture(options: Options) {
    const support = ref<PipSupport>(null);
    const pipWindow = shallowRef<Window | null>(null);
    const floatingVideo = shallowRef<HTMLVideoElement | null>(null);

    const target = computed(() => pipWindow.value?.document.body ?? null);
    const active = computed(
        () => pipWindow.value !== null || floatingVideo.value !== null,
    );

    onMounted(() => {
        support.value = pipSupport();
    });

    const openWindow = async (): Promise<void> => {
        let opened: Window;

        try {
            const api = documentPictureInPicture();

            if (!api) {
                return;
            }

            opened = await api.requestWindow(PIP_WINDOW_SIZE);
        } catch {
            options.onUnavailable(
                'The browser did not allow a picture-in-picture window.',
            );

            return;
        }

        copyDocumentLook(document, opened.document);
        opened.document.title = options.title();

        // Closed with its own close button, or by the browser when the tab goes away.
        opened.addEventListener(
            'pagehide',
            () => {
                if (pipWindow.value === opened) {
                    pipWindow.value = null;
                }
            },
            { once: true },
        );

        pipWindow.value = opened;
    };

    const floatVideo = async (): Promise<void> => {
        const video = options.video();

        if (!video) {
            options.onUnavailable(
                'There is no camera or shared screen to show right now.',
            );

            return;
        }

        try {
            await video.requestPictureInPicture();
        } catch {
            options.onUnavailable(
                'The browser did not allow picture-in-picture.',
            );

            return;
        }

        floatingVideo.value = video;
        video.addEventListener(
            'leavepictureinpicture',
            () => {
                if (floatingVideo.value === video) {
                    floatingVideo.value = null;
                }
            },
            { once: true },
        );
    };

    const open = async (): Promise<void> => {
        if (active.value) {
            return;
        }

        if (support.value === 'document') {
            await openWindow();
        } else if (support.value === 'video') {
            await floatVideo();
        }
    };

    const close = (): void => {
        pipWindow.value?.close();
        pipWindow.value = null;

        if (
            floatingVideo.value &&
            document.pictureInPictureElement === floatingVideo.value
        ) {
            void document.exitPictureInPicture().catch(() => {});
        }

        floatingVideo.value = null;
    };

    const toggle = (): Promise<void> =>
        active.value ? Promise.resolve(close()) : open();

    onBeforeUnmount(close);

    return { support, active, target, open, close, toggle };
}
