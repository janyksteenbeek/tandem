import type {
    CallStatus,
    ExportStatus,
    RecordingStatus,
    TranscriptDeliveryStatus,
} from '@/types';

/** "1 h 5 min", "12 min", "45 s": how long a meeting or recording lasted. */
export function formatDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined || seconds < 0) {
        return '';
    }

    const whole = Math.round(seconds);
    const hours = Math.floor(whole / 3600);
    const minutes = Math.floor((whole % 3600) / 60);

    if (hours > 0) {
        return minutes > 0 ? `${hours} h ${minutes} min` : `${hours} h`;
    }

    if (minutes > 0) {
        return `${minutes} min`;
    }

    return `${whole} s`;
}

/** A player clock: 0:07, 12:45, 1:02:03. */
export function formatClock(milliseconds: number): string {
    const total = Math.max(0, Math.floor(milliseconds / 1000));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;
    const pad = (value: number) => String(value).padStart(2, '0');

    return hours > 0
        ? `${hours}:${pad(minutes)}:${pad(seconds)}`
        : `${minutes}:${pad(seconds)}`;
}

/** File sizes up to gigabytes; recordings outgrow the megabyte-only helper in lib/time. */
export function formatSize(bytes: number | null | undefined): string {
    const size = bytes ?? 0;

    if (size < 1024) {
        return `${size} B`;
    }

    if (size < 1024 ** 2) {
        return `${(size / 1024).toFixed(0)} KB`;
    }

    if (size < 1024 ** 3) {
        return `${(size / 1024 ** 2).toFixed(1)} MB`;
    }

    return `${(size / 1024 ** 3).toFixed(2)} GB`;
}

export function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}

type Tone = 'neutral' | 'live' | 'progress' | 'good' | 'warn' | 'bad';

export type StatusMeta = { label: string; tone: Tone };

/** Classes for a status pill, per tone, readable in both colour schemes. */
export const TONE_CLASS: Record<Tone, string> = {
    neutral: 'bg-muted text-muted-foreground',
    live: 'bg-rose-500/12 text-rose-600 dark:bg-rose-400/15 dark:text-rose-300',
    progress: 'bg-brand/10 text-brand dark:bg-brand/20',
    good: 'bg-emerald-500/12 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-300',
    warn: 'bg-amber-500/15 text-amber-700 dark:bg-amber-400/15 dark:text-amber-300',
    bad: 'bg-destructive/10 text-destructive dark:bg-destructive/20',
};

export const TONE_DOT: Record<Tone, string> = {
    neutral: 'bg-muted-foreground/60',
    live: 'bg-rose-500',
    progress: 'bg-brand',
    good: 'bg-emerald-500',
    warn: 'bg-amber-500',
    bad: 'bg-destructive',
};

export const CALL_STATUS: Record<CallStatus, StatusMeta> = {
    pending: { label: 'Not started', tone: 'neutral' },
    live: { label: 'Live', tone: 'live' },
    ended: { label: 'Ended', tone: 'neutral' },
};

export const RECORDING_STATUS: Record<RecordingStatus, StatusMeta> = {
    starting: { label: 'Starting', tone: 'progress' },
    recording: { label: 'Recording', tone: 'live' },
    processing: { label: 'Processing', tone: 'progress' },
    ready: { label: 'Ready', tone: 'good' },
    partial: { label: 'Partly captured', tone: 'warn' },
    failed: { label: 'Failed', tone: 'bad' },
};

export const EXPORT_STATUS: Record<ExportStatus, StatusMeta> = {
    queued: { label: 'Queued', tone: 'progress' },
    processing: { label: 'Building', tone: 'progress' },
    ready: { label: 'Ready', tone: 'good' },
    failed: { label: 'Failed', tone: 'bad' },
};

export const DELIVERY_STATUS: Record<TranscriptDeliveryStatus, StatusMeta> = {
    queued: { label: 'Queued', tone: 'progress' },
    sent: { label: 'Sent', tone: 'good' },
    failed: { label: 'Failed', tone: 'bad' },
};

/** A meeting code out of whatever was pasted: the bare code or a full /meet link. */
export function extractMeetingCode(input: string): string | null {
    const match = input.toLowerCase().match(/[a-z]{3}-[a-z]{4}-[a-z]{3}/);

    return match ? match[0] : null;
}

const EMAIL = /^[^\s@<>(),;:"]+@[^\s@<>(),;:"]+\.[^\s@<>(),;:"]{2,}$/;

export function isEmail(value: string): boolean {
    return value.length <= 254 && EMAIL.test(value);
}

/**
 * Addresses out of free typing or a pasted list ("Ada <ada@x.io>, bo@y.io; …"). Returns the
 * valid ones, lower-cased and de-duplicated, and whatever could not be read as an address.
 */
export function parseEmails(input: string): {
    emails: string[];
    invalid: string[];
} {
    const emails: string[] = [];
    const invalid: string[] = [];

    input
        .split(/[\s,;]+/)
        .map((part) => part.replace(/^[<("']+|[>)"']+$/g, '').trim())
        .filter(Boolean)
        .forEach((part) => {
            const email = part.toLowerCase();

            if (isEmail(email)) {
                if (!emails.includes(email)) {
                    emails.push(email);
                }
            } else if (part.includes('@')) {
                invalid.push(part);
            }
        });

    return { emails, invalid };
}
