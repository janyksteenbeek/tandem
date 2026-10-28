import type { ConversationPriority, EmailAddress } from '@/types';

export const CHIP_COLORS = [
    'violet',
    'teal',
    'amber',
    'rose',
    'sky',
    'emerald',
    'slate',
    'orange',
    'pink',
    'lime',
    'plum',
] as const;

export function chipClass(color: string | null | undefined): string {
    const safe = CHIP_COLORS.includes((color ?? '') as (typeof CHIP_COLORS)[number])
        ? color
        : 'slate';

    return `chip-${safe}`;
}

export function displayName(address: EmailAddress | null | undefined): string {
    if (!address) {
        return 'Unknown';
    }

    return address.name?.trim() || address.email.split('@')[0];
}

export function initialsOf(value: string): string {
    return value
        .split(/[\s@._-]+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

const AVATAR_HUES = [330, 300, 265, 235, 190, 155, 125, 75, 50, 10];

export function avatarStyle(seed: string): Record<string, string> {
    let hash = 0;

    for (const char of seed) {
        hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    }

    const hue = AVATAR_HUES[hash % AVATAR_HUES.length];

    return {
        background: `oklch(0.92 0.06 ${hue})`,
        color: `oklch(0.38 0.14 ${hue})`,
    };
}

export const PRIORITY_META: Record<
    ConversationPriority,
    { label: string; short: string; className: string }
> = {
    none: { label: 'No priority', short: '', className: 'text-muted-foreground' },
    low: { label: 'Low', short: 'Low', className: 'text-muted-foreground' },
    normal: { label: 'Normal', short: 'Normal', className: 'text-info' },
    high: { label: 'High', short: 'High', className: 'text-warning-foreground' },
    urgent: { label: 'Urgent', short: 'Urgent', className: 'text-destructive' },
};

export const STATUS_META: Record<string, { label: string; dot: string }> = {
    open: { label: 'Open', dot: 'bg-success' },
    pending: { label: 'Pending', dot: 'bg-warning' },
    snoozed: { label: 'Snoozed', dot: 'bg-highlight' },
    closed: { label: 'Closed', dot: 'bg-muted-foreground' },
};

export function formatAddressList(list: EmailAddress[]): string {
    return list.map((a) => (a.name ? `${a.name} <${a.email}>` : a.email)).join(', ');
}

export const EMPTY_COPY: Record<string, { title: string; body: string }> = {
    inbox: { title: 'Inbox zero.', body: 'Go touch grass. We will ping you when something lands.' },
    open: { title: 'Nothing open.', body: 'Every conversation here is handled. Enjoy the quiet.' },
    assigned: { title: 'Nothing on your plate.', body: 'Pick something up from Unassigned, or enjoy the moment.' },
    unassigned: { title: 'Everything has an owner.', body: 'Teamwork, evidently.' },
    pending: { title: 'No one is waiting.', body: 'Pending conversations show up here while you wait on someone else.' },
    snoozed: { title: 'Nothing snoozed.', body: 'Everyone is wide awake.' },
    closed: { title: 'Nothing closed yet.', body: 'Closed conversations live here for when you need them.' },
    drafts: { title: 'No drafts.', body: 'Say it with your chest.' },
    sent: { title: 'Nothing sent yet.', body: 'Your outgoing mail will show up here.' },
    archive: { title: 'Archive is empty.', body: 'Archive what you are done with, find it here later.' },
    spam: { title: 'No spam.', body: 'Suspicious mail lands here so it never clutters your inbox.' },
    trash: { title: 'Trash is empty.', body: 'Deleted conversations wait here before they are gone for good.' },
    starred: { title: 'No stars yet.', body: 'Star the conversations that matter and they will gather here.' },
    all: { title: 'Nothing here yet.', body: 'Connect an account and mail will start flowing in.' },
    search: { title: 'No matches.', body: 'Try fewer words, or search by a name or email address.' },
};
