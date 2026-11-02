import type { ReverbConnection } from '@/types';

export type RealtimeOptions =
    | {
          broadcaster: 'reverb';
          key: string;
          wsHost: string;
          wsPort: number;
          wssPort: number;
          forceTLS: boolean;
          enabledTransports: ['ws', 'wss'];
      }
    | { broadcaster: 'null' };

/**
 * Echo options for the Reverb connection the server shared with the page.
 * Without a key (BROADCAST_CONNECTION is not reverb, or REVERB_APP_KEY is
 * empty on the web pods) realtime is off and everything else keeps working.
 * An empty host means the page's own hostname.
 */
export function realtimeOptions(
    reverb: ReverbConnection | null | undefined,
    pageHostname: string,
): RealtimeOptions {
    if (!reverb?.key) {
        return { broadcaster: 'null' };
    }

    return {
        broadcaster: 'reverb',
        key: reverb.key,
        wsHost: reverb.host || pageHostname,
        wsPort: reverb.port,
        wssPort: reverb.port,
        forceTLS: reverb.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    };
}
