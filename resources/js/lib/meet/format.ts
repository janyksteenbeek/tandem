export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value >= 10 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`;
}

/** 0:07, 12:34, 1:02:03 */
export function formatDuration(totalSeconds: number): string {
    const seconds = Math.max(0, Math.floor(totalSeconds));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    const pad = (value: number) => String(value).padStart(2, '0');

    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

export function formatClock(iso: string | null): string {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * The path of a URL on this application, without its origin. File links arrive both in HTTP
 * responses and in broadcasts (which are built from the configured app URL); using only the
 * path keeps downloads on the origin the person is actually signed in on.
 */
export function localPath(url: string): string {
    try {
        const parsed = new URL(url, 'http://local.invalid');

        return parsed.pathname + parsed.search;
    } catch {
        return url;
    }
}
