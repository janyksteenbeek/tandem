import { describe, expect, it } from 'vitest';
import { realtimeOptions } from '@/lib/realtime';

describe('realtimeOptions', () => {
    it('connects to the shared Reverb address', () => {
        expect(
            realtimeOptions(
                {
                    key: 'app-key',
                    host: 'ws.example.test',
                    port: 443,
                    scheme: 'https',
                },
                'inbox.example.test',
            ),
        ).toEqual({
            broadcaster: 'reverb',
            key: 'app-key',
            wsHost: 'ws.example.test',
            wsPort: 443,
            wssPort: 443,
            forceTLS: true,
            enabledTransports: ['ws', 'wss'],
        });
    });

    it("uses the page's own hostname and plain websockets when told so", () => {
        expect(
            realtimeOptions(
                { key: 'app-key', host: null, port: 8080, scheme: 'http' },
                'localhost',
            ),
        ).toMatchObject({
            wsHost: 'localhost',
            wsPort: 8080,
            forceTLS: false,
        });
    });

    it('turns realtime off without a shared key', () => {
        expect(realtimeOptions(null, 'localhost')).toEqual({
            broadcaster: 'null',
        });
        expect(realtimeOptions(undefined, 'localhost')).toEqual({
            broadcaster: 'null',
        });
        expect(
            realtimeOptions(
                { key: '', host: null, port: 443, scheme: 'https' },
                'localhost',
            ),
        ).toEqual({ broadcaster: 'null' });
    });
});
