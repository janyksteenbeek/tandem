/**
 * The short chime played when new mail lands or a notification arrives.
 * Browsers block audio until the user has interacted with the page, so a
 * refused play() is expected and ignored. Bursts (a sync delivering ten
 * messages at once) collapse into one chime.
 */
export const NOTIFICATION_SOUND_URL = '/tandem/notification.mp3';

const MIN_INTERVAL_MS = 1500;

let audio: HTMLAudioElement | null = null;
let lastPlayedAt = -Infinity;

export function playNotificationSound(now: number = Date.now()): boolean {
    if (typeof window === 'undefined' || typeof Audio === 'undefined') {
        return false;
    }

    if (now - lastPlayedAt < MIN_INTERVAL_MS) {
        return false;
    }

    lastPlayedAt = now;
    audio ??= new Audio(NOTIFICATION_SOUND_URL);
    audio.currentTime = 0;

    const result = audio.play();

    if (result && typeof result.catch === 'function') {
        result.catch(() => undefined);
    }

    return true;
}

/** Test hook: forget the cached element and the throttle window. */
export function resetNotificationSound(): void {
    audio = null;
    lastPlayedAt = -Infinity;
}
