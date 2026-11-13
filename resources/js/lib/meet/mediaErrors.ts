/**
 * What went wrong when the browser refused a camera or microphone, in words that tell the
 * person what to do next.
 */

export type MediaProblemKind =
    | 'blocked'
    | 'missing'
    | 'busy'
    | 'insecure'
    | 'unsupported'
    | 'unknown';

export type MediaProblem = {
    kind: MediaProblemKind;
    title: string;
    help: string;
};

type Device = 'microphone' | 'camera' | 'camera and microphone' | 'screen';

export function explainMediaError(
    error: unknown,
    device: Device,
): MediaProblem {
    const raw =
        typeof error === 'object' && error !== null
            ? (error as { name?: unknown }).name
            : null;
    const name = typeof raw === 'string' ? raw : '';

    if (typeof window !== 'undefined' && !window.isSecureContext) {
        return {
            kind: 'insecure',
            title: `Your ${device} needs a secure connection`,
            help: 'Browsers only allow cameras and microphones on HTTPS pages. Open this meeting through its https:// link.',
        };
    }

    switch (name) {
        case 'NotAllowedError':
        case 'PermissionDeniedError':
        case 'SecurityError':
            return {
                kind: 'blocked',
                title: `Access to your ${device} is blocked`,
                help:
                    device === 'screen'
                        ? 'Screen sharing was cancelled or is not allowed. On macOS, allow your browser under System Settings → Privacy & Security → Screen Recording.'
                        : `Click the icon at the left of the address bar, allow the ${device} for this site, then try again. If it is already allowed there, check your system privacy settings.`,
            };
        case 'NotFoundError':
        case 'DevicesNotFoundError':
            return {
                kind: 'missing',
                title: `No ${device} was found`,
                help: `Plug one in or switch it on. You can still join and ${device === 'camera' ? 'be heard' : 'listen'} without it.`,
            };
        case 'NotReadableError':
        case 'TrackStartError':
        case 'AbortError':
            return {
                kind: 'busy',
                title: `Your ${device} could not be started`,
                help: 'Another app may be using it. Close other video or recording apps, then try again.',
            };
        case 'OverconstrainedError':
        case 'ConstraintNotSatisfiedError':
            return {
                kind: 'missing',
                title: `The selected ${device} is not available`,
                help: 'It may have been unplugged. Pick another device.',
            };
        case 'TypeError':
            return {
                kind: 'unsupported',
                title: `This browser cannot use your ${device}`,
                help: 'Try a current version of Chrome, Edge, Firefox or Safari.',
            };
        default:
            return {
                kind: 'unknown',
                title: `Your ${device} could not be started`,
                help: 'Check that it is connected and not in use by another app, then try again.',
            };
    }
}
