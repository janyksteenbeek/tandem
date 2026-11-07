import { beforeEach, describe, expect, it, vi } from 'vitest';
import { issueStore } from '@/lib/projects/issueStore';
import type { IssueListItem } from '@/types';

const item = (uuid: string, updated: string, title = 'A'): IssueListItem =>
    ({
        uuid,
        identifier: 'T-1',
        number: 1,
        title,
        updated_at: updated,
        status: {
            id: 1,
            uuid: 's',
            workflow_id: 1,
            name: 'Todo',
            color: 'sky',
            icon: null,
            category: 'unstarted',
            position: 0,
            is_default: true,
        },
        status_category: 'unstarted',
        priority: 'none',
        assignees: [],
        teams: [],
        labels: [],
        modules: [],
        counts: {
            sub_issues: 0,
            sub_completed: 0,
            comments: 0,
            attachments: 0,
            links: 0,
            workstreams: 0,
            blocked_by: 0,
            blocked_workstreams: 0,
        },
    }) as unknown as IssueListItem;

describe('issueStore', () => {
    beforeEach(() => issueStore.clear());

    it('ingests props and upserts newer payloads only', () => {
        issueStore.ingest([item('a', '2026-11-05T00:00:00Z', 'first')]);

        expect(
            issueStore.upsert(item('a', '2026-11-04T00:00:00Z', 'stale')),
        ).toBe(false);
        expect(issueStore.get('a')?.title).toBe('first');

        expect(
            issueStore.upsert(item('a', '2026-11-06T00:00:00Z', 'fresh')),
        ).toBe(true);
        expect(issueStore.get('a')?.title).toBe('fresh');

        expect(
            issueStore.upsert(item('a', '2026-11-01T00:00:00Z', 'forced'), {
                force: true,
            }),
        ).toBe(true);
        expect(issueStore.get('a')?.title).toBe('forced');
    });

    it('patches optimistically and rolls back', () => {
        issueStore.ingest([item('b', '2026-11-05T00:00:00Z', 'orig')]);
        const listener = vi.fn();
        const unsubscribe = issueStore.subscribe(listener);

        const rollback = issueStore.patch('b', { title: 'changed' });
        expect(issueStore.get('b')?.title).toBe('changed');
        expect(listener).toHaveBeenCalledTimes(1);

        rollback();
        expect(issueStore.get('b')?.title).toBe('orig');
        unsubscribe();
    });

    it('removes and notifies', () => {
        issueStore.ingest([item('c', '2026-11-05T00:00:00Z')]);
        const listener = vi.fn();
        issueStore.subscribe(listener);

        issueStore.remove('c');

        expect(issueStore.has('c')).toBe(false);
        expect(listener).toHaveBeenCalledWith({
            type: 'remove',
            uuid: 'c',
            issue: null,
        });
    });
});
