import type { CallRecording } from '@/types';

/** A Laravel length-aware paginator as Inertia serialises it. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    next_page_url: string | null;
    prev_page_url: string | null;
};

/** A row of the Recordings tab: the recording and the meeting it belongs to. */
export type RecordingListItem = CallRecording & {
    call: { uuid: string; title: string };
};

export type Recipient = { email: string; name: string | null };
