import { beforeEach, describe, expect, it } from 'vitest';
import { explainMediaError } from '@/lib/meet/mediaErrors';

const named = (name: string) => Object.assign(new Error(name), { name });

describe('explainMediaError', () => {
    beforeEach(() => {
        Object.defineProperty(window, 'isSecureContext', {
            value: true,
            configurable: true,
        });
    });

    it('tells a blocked permission apart from a missing or busy device', () => {
        expect(explainMediaError(named('NotAllowedError'), 'camera').kind).toBe(
            'blocked',
        );
        expect(explainMediaError(named('NotFoundError'), 'camera').kind).toBe(
            'missing',
        );
        expect(
            explainMediaError(named('NotReadableError'), 'microphone').kind,
        ).toBe('busy');
        expect(
            explainMediaError(named('OverconstrainedError'), 'camera').kind,
        ).toBe('missing');
    });

    it('names the device in the title and says what to do', () => {
        const problem = explainMediaError(
            named('NotAllowedError'),
            'microphone',
        );

        expect(problem.title).toContain('microphone');
        expect(problem.help).toMatch(/address bar/);
    });

    it('points at the operating system for a blocked screen share', () => {
        expect(
            explainMediaError(named('NotAllowedError'), 'screen').help,
        ).toMatch(/Screen Recording/);
    });

    it('explains an insecure page before anything else', () => {
        Object.defineProperty(window, 'isSecureContext', {
            value: false,
            configurable: true,
        });

        expect(explainMediaError(named('NotAllowedError'), 'camera').kind).toBe(
            'insecure',
        );
    });

    it('has an answer for errors it has never seen', () => {
        expect(explainMediaError('boom', 'camera').kind).toBe('unknown');
        expect(explainMediaError(null, 'camera').kind).toBe('unknown');
    });
});
