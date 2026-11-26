/**
 * How loud each other person is for this listener, as a fraction of their normal volume.
 * Purely local: nothing here reaches the server, the other participants or a recording.
 *
 * Keyed by media identity, which is stable for a participant for the whole call, so a level
 * survives reconnects and (through sessionStorage) a reload of the tab.
 */
export type ParticipantVolumes = Record<string, number>;

export const FULL_VOLUME = 1;

const STORAGE_PREFIX = 'tandem:meet:volumes:';

const storage = (): Storage | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
};

/** A usable volume from anything: between silent and normal, full when it makes no sense. */
export function clampVolume(value: unknown): number {
    if (typeof value !== 'number' || !Number.isFinite(value)) {
        return FULL_VOLUME;
    }

    return Math.min(FULL_VOLUME, Math.max(0, value));
}

/** Read what was remembered, keeping only real levels that differ from normal. */
export function parseVolumes(raw: string | null): ParticipantVolumes {
    if (!raw) {
        return {};
    }

    try {
        const saved: unknown = JSON.parse(raw);

        if (!saved || typeof saved !== 'object' || Array.isArray(saved)) {
            return {};
        }

        const volumes: ParticipantVolumes = {};

        for (const [identity, value] of Object.entries(saved)) {
            if (
                typeof value === 'number' &&
                Number.isFinite(value) &&
                clampVolume(value) < FULL_VOLUME
            ) {
                volumes[identity] = clampVolume(value);
            }
        }

        return volumes;
    } catch {
        return {};
    }
}

/** The same levels with one person changed; people at normal volume are not stored. */
export function withVolume(
    volumes: ParticipantVolumes,
    identity: string,
    volume: number,
): ParticipantVolumes {
    const next = { ...volumes };
    const level = clampVolume(volume);

    if (level >= FULL_VOLUME) {
        delete next[identity];
    } else {
        next[identity] = level;
    }

    return next;
}

export function loadVolumes(callUuid: string): ParticipantVolumes {
    return parseVolumes(storage()?.getItem(STORAGE_PREFIX + callUuid) ?? null);
}

export function saveVolumes(
    callUuid: string,
    volumes: ParticipantVolumes,
): void {
    try {
        if (Object.keys(volumes).length === 0) {
            storage()?.removeItem(STORAGE_PREFIX + callUuid);
        } else {
            storage()?.setItem(
                STORAGE_PREFIX + callUuid,
                JSON.stringify(volumes),
            );
        }
    } catch {
        // Private mode or a full disk: the levels simply last until the tab reloads.
    }
}

/**
 * Whether this browser lets a page set the volume of a media element. iOS leaves that to
 * the hardware buttons and ignores the property, so there only muting someone works.
 */
export function canAdjustVolume(): boolean {
    if (typeof document === 'undefined') {
        return true;
    }

    try {
        const probe = document.createElement('audio');

        probe.volume = 0.5;

        return probe.volume === 0.5;
    } catch {
        return false;
    }
}
