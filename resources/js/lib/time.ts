import {
    differenceInCalendarDays,
    differenceInMinutes,
    format,
    formatDistanceToNowStrict,
    isThisYear,
    isToday,
    isYesterday,
} from 'date-fns';

export function listTime(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const minutes = differenceInMinutes(new Date(), date);

    if (minutes < 1) {
        return 'now';
    }

    if (minutes < 60) {
        return `${minutes}m`;
    }

    if (isToday(date)) {
        return `${Math.floor(minutes / 60)}h`;
    }

    if (differenceInCalendarDays(new Date(), date) < 7) {
        return format(date, 'EEE');
    }

    if (isThisYear(date)) {
        return format(date, 'd MMM');
    }

    return format(date, 'd MMM yyyy');
}

export function fullTime(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    if (isToday(date)) {
        return `Today at ${format(date, 'HH:mm')}`;
    }

    if (isYesterday(date)) {
        return `Yesterday at ${format(date, 'HH:mm')}`;
    }

    return format(
        date,
        isThisYear(date) ? 'EEE d MMM, HH:mm' : 'd MMM yyyy, HH:mm',
    );
}

export function relative(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    return formatDistanceToNowStrict(new Date(iso), { addSuffix: true });
}

export function snoozeLabel(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    if (isToday(date)) {
        return `today ${format(date, 'HH:mm')}`;
    }

    if (differenceInCalendarDays(date, new Date()) === 1) {
        return `tomorrow ${format(date, 'HH:mm')}`;
    }

    return format(date, 'EEE d MMM, HH:mm');
}

export function bytes(size: number): string {
    if (size < 1024) {
        return `${size} B`;
    }

    if (size < 1024 * 1024) {
        return `${(size / 1024).toFixed(0)} KB`;
    }

    return `${(size / 1024 / 1024).toFixed(1)} MB`;
}
