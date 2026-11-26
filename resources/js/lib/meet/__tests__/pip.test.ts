import { afterEach, describe, expect, it } from 'vitest';
import {
    copyDocumentLook,
    documentPictureInPicture,
    pipParticipants,
    pipScreen,
    pipSupport,
    pipVideoElement,
} from '@/lib/meet/pip';

const person = (identity: string, isLocal = false) => ({ identity, isLocal });

describe('pipParticipants', () => {
    const me = person('me', true);

    it('shows the other people, not yourself', () => {
        const people = [me, person('a'), person('b')];

        expect(pipParticipants(people, {}).map((p) => p.identity)).toEqual([
            'a',
            'b',
        ]);
    });

    it('shows yourself while nobody else is there', () => {
        expect(pipParticipants([me], {})).toEqual([me]);
        expect(pipParticipants([], {})).toEqual([]);
    });

    it('gives the places to whoever spoke last, in the order they already had', () => {
        const people = [me, ...['a', 'b', 'c', 'd', 'e'].map((id) => person(id))];
        const shown = pipParticipants(people, { e: 300, c: 200, a: 100 }, 3);

        expect(shown.map((p) => p.identity)).toEqual(['a', 'c', 'e']);
    });

    it('keeps the first people when nobody has spoken yet', () => {
        const people = ['a', 'b', 'c'].map((id) => person(id));

        expect(pipParticipants(people, {}, 2).map((p) => p.identity)).toEqual([
            'a',
            'b',
        ]);
    });
});

describe('pipScreen', () => {
    const mine = { id: 'screen:me', isLocal: true };
    const anna = { id: 'screen:anna', isLocal: false };
    const bram = { id: 'screen:bram', isLocal: false };

    it('prefers the screen that is on stage', () => {
        expect(pipScreen([anna, bram], 'screen:bram')).toBe(bram);
    });

    it('falls back to the first screen someone else shares', () => {
        expect(pipScreen([mine, anna, bram], null)).toBe(anna);
        expect(pipScreen([anna], 'screen:gone')).toBe(anna);
    });

    it('never floats your own screen', () => {
        expect(pipScreen([mine], 'screen:me')).toBeNull();
        expect(pipScreen([], null)).toBeNull();
    });
});

describe('pipVideoElement', () => {
    afterEach(() => document.body.replaceChildren());

    const onPage = () => {
        const video = document.createElement('video');

        document.body.appendChild(video);

        return video;
    };

    it('takes the video that shows the shared screen', () => {
        const screen = onPage();
        const camera = onPage();

        expect(
            pipVideoElement({ track: { attachedElements: [screen] } }, [
                { cameraTrack: { attachedElements: [camera] } },
            ]),
        ).toBe(screen);
    });

    it('otherwise takes the first person whose camera is on the page', () => {
        const camera = onPage();

        expect(
            pipVideoElement(null, [
                { cameraTrack: null },
                { cameraTrack: { attachedElements: [] } },
                { cameraTrack: { attachedElements: [camera] } },
            ]),
        ).toBe(camera);
    });

    it('skips elements that are not a video on the page', () => {
        const detached = document.createElement('video');
        const audio = document.createElement('audio');

        document.body.appendChild(audio);

        expect(
            pipVideoElement(null, [
                { cameraTrack: { attachedElements: [audio, detached] } },
            ]),
        ).toBeNull();
    });
});

describe('copyDocumentLook', () => {
    afterEach(() => {
        document.head.querySelectorAll('style').forEach((el) => el.remove());
        document.documentElement.className = '';
        document.body.className = '';
    });

    it('carries the styles, theme and language over to a new document', () => {
        const style = document.createElement('style');

        style.textContent = '.tile { color: red; }';
        document.head.appendChild(style);
        document.documentElement.className = 'dark';
        document.documentElement.lang = 'en';
        document.body.className = 'font-sans antialiased';

        const target = document.implementation.createHTMLDocument('pip');

        copyDocumentLook(document, target);

        expect(target.head.querySelector('style')?.textContent).toContain(
            '.tile',
        );
        expect(target.documentElement.className).toBe('dark');
        expect(target.documentElement.lang).toBe('en');
        expect(target.body.className).toBe('font-sans antialiased');
    });
});

describe('pipSupport', () => {
    afterEach(() => {
        delete (window as { documentPictureInPicture?: unknown })
            .documentPictureInPicture;
    });

    it('offers nothing where the browser has no picture-in-picture', () => {
        expect(documentPictureInPicture()).toBeNull();
        expect(pipSupport()).toBeNull();
    });

    it('prefers a whole window where the browser can float one', () => {
        (
            window as { documentPictureInPicture?: unknown }
        ).documentPictureInPicture = {
            window: null,
            requestWindow: () => Promise.resolve(window),
        };

        expect(pipSupport()).toBe('document');
    });
});
