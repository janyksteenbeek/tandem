import { beforeEach, describe, expect, it } from 'vitest';
import {
    homeFor,
    loadRememberedUrls,
    railItems,
    rememberVisit,
    rememberedUrls,
    sectionForPath,
} from '@/lib/navigation';

describe('sectionForPath', () => {
    it('puts every organization page that is not mail or settings under projects', () => {
        expect(sectionForPath('/acme/my-issues', 'acme')).toBe('projects');
        expect(sectionForPath('/acme/issues/FRONT-1?from=board', 'acme')).toBe(
            'projects',
        );
        expect(sectionForPath('/acme/teams/engineering/board', 'acme')).toBe(
            'projects',
        );
        expect(sectionForPath('/acme', 'acme')).toBe('projects');
    });

    it('puts the calls pages under calls and leaves the meeting room outside every section', () => {
        expect(sectionForPath('/acme/calls', 'acme')).toBe('calls');
        expect(sectionForPath('/acme/calls?tab=recordings', 'acme')).toBe(
            'calls',
        );
        expect(sectionForPath('/acme/calls/0b9c/recordings/7f1e', 'acme')).toBe(
            'calls',
        );
        expect(sectionForPath('/acme/callsheet', 'acme')).toBe('projects');
        expect(sectionForPath('/meet/abc-defg-hij', 'acme')).toBeNull();
    });

    it('recognises mail and settings inside and outside the organization', () => {
        expect(sectionForPath('/acme/mail/all/inbox', 'acme')).toBe('mail');
        expect(sectionForPath('/', null)).toBe('mail');
        expect(sectionForPath('/acme/settings/automations', 'acme')).toBe(
            'settings',
        );
        expect(sectionForPath('/settings/profile', 'acme')).toBe('settings');
        expect(sectionForPath('/organizations/new', null)).toBe('settings');
    });

    it('ignores pages of another organization', () => {
        expect(sectionForPath('/other/my-issues', 'acme')).toBeNull();
        expect(sectionForPath('/acme-labs/mail', 'acme')).toBeNull();
    });
});

describe('remembered urls', () => {
    beforeEach(() => {
        window.sessionStorage.clear();
        rememberedUrls.value = {};
    });

    it('sends each rail item home until a page in that area was visited', () => {
        const items = railItems('acme', { projects: 3 });

        expect(items.map((i) => [i.section, i.href])).toEqual([
            ['mail', '/acme/mail'],
            ['projects', '/acme/my-issues'],
            ['calls', '/acme/calls'],
            ['settings', '/settings/profile'],
        ]);
        expect(items[1].badge).toBe(3);
    });

    it('returns to the last visited page of an area, query included, hash dropped', () => {
        rememberVisit('/acme/teams/qa/board?display=board#x', 'acme');
        rememberVisit('/acme/mail/abc/open', 'acme');
        rememberVisit('/acme/calls?tab=recordings', 'acme');
        rememberVisit('/other/mail', 'acme');

        const items = railItems('acme');

        expect(items[0].href).toBe('/acme/mail/abc/open');
        expect(items[1].href).toBe('/acme/teams/qa/board?display=board');
        expect(items[2].href).toBe('/acme/calls?tab=recordings');

        rememberedUrls.value = {};
        loadRememberedUrls('acme');

        expect(rememberedUrls.value.projects).toBe(
            '/acme/teams/qa/board?display=board',
        );
    });

    it('falls back to mail and settings without an organization', () => {
        expect(railItems(null).map((i) => i.section)).toEqual([
            'mail',
            'settings',
        ]);
        expect(homeFor('projects', 'acme')).toBe('/acme/my-issues');
        expect(homeFor('calls', 'acme')).toBe('/acme/calls');
        expect(homeFor(null, 'acme')).toBe('/acme/mail');
    });
});
