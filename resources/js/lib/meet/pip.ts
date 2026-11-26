/**
 * Picture-in-picture for a meeting: a small always-on-top window that keeps the people (or
 * the shared screen) in view while the meeting's tab is in the background.
 *
 * Two browser features can do this. A document picture-in-picture window (Chromium) holds
 * real page content, so it shows several people and has its own microphone, camera and
 * leave buttons. Where that does not exist, the classic video picture-in-picture floats one
 * video and nothing else.
 */

export type PipSupport = 'document' | 'video' | null;

/** The part of the Document Picture-in-Picture API this uses; not in TypeScript's DOM yet. */
export type DocumentPictureInPicture = {
    readonly window: Window | null;
    requestWindow(options?: {
        width?: number;
        height?: number;
    }): Promise<Window>;
};

/** How many people fit in a window this small before faces become stamps. */
export const PIP_MAX_PEOPLE = 4;

export const PIP_WINDOW_SIZE = { width: 420, height: 300 };

export function documentPictureInPicture(): DocumentPictureInPicture | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const api = (
        window as Window & {
            documentPictureInPicture?: DocumentPictureInPicture;
        }
    ).documentPictureInPicture;

    return typeof api?.requestWindow === 'function' ? api : null;
}

/** The best picture-in-picture this browser offers, or none (Firefox has only its own). */
export function pipSupport(): PipSupport {
    if (typeof document === 'undefined') {
        return null;
    }

    if (documentPictureInPicture()) {
        return 'document';
    }

    return document.pictureInPictureEnabled &&
        typeof HTMLVideoElement !== 'undefined' &&
        'requestPictureInPicture' in HTMLVideoElement.prototype
        ? 'video'
        : null;
}

/**
 * Make a fresh document look like the page it came from: the same stylesheets, theme
 * classes and language. Rules are copied as text so they apply at once, without a load in
 * between during which the content would be unstyled (and measured wrongly); a stylesheet
 * whose rules cannot be read (another origin) is linked instead.
 */
export function copyDocumentLook(from: Document, to: Document): void {
    for (const sheet of Array.from(from.styleSheets)) {
        const media =
            (sheet.ownerNode as HTMLStyleElement | HTMLLinkElement | null)
                ?.media ?? '';

        try {
            const style = to.createElement('style');

            style.textContent = Array.from(sheet.cssRules)
                .map((rule) => rule.cssText)
                .join('\n');

            if (media) {
                style.media = media;
            }

            to.head.appendChild(style);
        } catch {
            if (sheet.href) {
                const link = to.createElement('link');

                link.rel = 'stylesheet';
                link.href = sheet.href;
                link.media = media;
                to.head.appendChild(link);
            }
        }
    }

    to.documentElement.className = from.documentElement.className;
    to.documentElement.lang = from.documentElement.lang;
    to.documentElement.style.colorScheme =
        from.documentElement.style.colorScheme;
    to.body.className = from.body.className;
}

/**
 * Who to show: the other people, in the order the room already has them. When there are
 * more than fit, whoever spoke most recently gets the places, without reshuffling them.
 * Alone in the meeting, you see yourself.
 */
export function pipParticipants<
    T extends { identity: string; isLocal: boolean },
>(
    participants: T[],
    lastSpokeAt: Record<string, number>,
    max: number = PIP_MAX_PEOPLE,
): T[] {
    const others = participants.filter((p) => !p.isLocal);

    if (others.length === 0) {
        return participants.slice(0, 1);
    }

    if (others.length <= max) {
        return others;
    }

    const chosen = new Set(
        others
            .map((p, index) => ({ p, index }))
            .sort(
                (a, b) =>
                    (lastSpokeAt[b.p.identity] ?? 0) -
                        (lastSpokeAt[a.p.identity] ?? 0) || a.index - b.index,
            )
            .slice(0, max)
            .map(({ p }) => p.identity),
    );

    return others.filter((p) => chosen.has(p.identity));
}

/**
 * The shared screen worth floating: the one on stage, otherwise any. Never your own, which
 * is the very thing you are looking at while the window floats over it.
 */
export function pipScreen<T extends { id: string; isLocal: boolean }>(
    screens: T[],
    selectedId: string | null,
): T | null {
    const others = screens.filter((screen) => !screen.isLocal);

    return (
        others.find((screen) => screen.id === selectedId) ?? others[0] ?? null
    );
}

type Attached = { attachedElements: HTMLMediaElement[] };

/**
 * For browsers that can only float a single video: the element already on the page that
 * shows the shared screen, or else the first person with their camera on.
 */
export function pipVideoElement(
    screen: { track: Attached } | null,
    people: { cameraTrack: Attached | null }[],
): HTMLVideoElement | null {
    const tracks = [
        screen?.track ?? null,
        ...people.map((person) => person.cameraTrack),
    ];

    for (const track of tracks) {
        const element = track?.attachedElements.find(
            (candidate): candidate is HTMLVideoElement =>
                candidate instanceof HTMLVideoElement && candidate.isConnected,
        );

        if (element) {
            return element;
        }
    }

    return null;
}
