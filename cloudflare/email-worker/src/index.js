import { SIGNATURE_HEADER, sign } from './signature.js';

const DEFAULT_MAX_BYTES = 25 * 1024 * 1024;
const MAX_ATTEMPTS = 3;
const HOP_HEADER = 'X-Tandem-Hops';

/**
 * Read the full raw MIME stream into a single Uint8Array, aborting early when it exceeds maxBytes.
 *
 * @param {ReadableStream<Uint8Array>} stream
 * @param {number} maxBytes
 * @returns {Promise<Uint8Array|null>} null when the message is too large
 */
async function readRaw(stream, maxBytes) {
    const reader = stream.getReader();
    const chunks = [];
    let total = 0;

    for (;;) {
        const { done, value } = await reader.read();

        if (done) {
            break;
        }

        total += value.byteLength;

        if (total > maxBytes) {
            await reader.cancel();

            return null;
        }

        chunks.push(value);
    }

    const bytes = new Uint8Array(total);
    let offset = 0;

    for (const chunk of chunks) {
        bytes.set(chunk, offset);
        offset += chunk.byteLength;
    }

    return bytes;
}

/**
 * Base64 encode without blowing the call stack on large messages.
 *
 * @param {Uint8Array} bytes
 * @returns {string}
 */
function toBase64(bytes) {
    let binary = '';
    const chunkSize = 0x8000;

    for (let i = 0; i < bytes.length; i += chunkSize) {
        binary += String.fromCharCode.apply(
            null,
            bytes.subarray(i, i + chunkSize),
        );
    }

    return btoa(binary);
}

/**
 * Count how many times a message already passed through Tandem's forwarding domain.
 * The upstream MTA keeps every previous X-Tandem-Hops header; one per hop.
 *
 * @param {Headers} headers
 * @returns {number}
 */
function countHops(headers) {
    const raw = headers.get(HOP_HEADER);

    if (!raw) {
        return 1;
    }

    const values = raw
        .split(',')
        .map((value) => value.trim())
        .filter(Boolean);
    const highest = values.reduce(
        (max, value) => Math.max(max, Number.parseInt(value, 10) || 0),
        0,
    );

    return Math.max(values.length, highest) + 1;
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * POST the signed payload to Tandem with retries. Resolves to the final Response or throws.
 *
 * @param {string} url
 * @param {string} body
 * @param {string} secret
 * @returns {Promise<Response>}
 */
async function deliver(url, body, secret) {
    let lastError = null;

    for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'User-Agent': 'tandem-email-worker/1.0',
                    [SIGNATURE_HEADER]: await sign(secret, body),
                },
                body,
            });

            // 2xx: accepted (queued / duplicate / unknown recipient). 4xx other than 408/429: do not retry.
            if (
                response.ok ||
                (response.status >= 400 &&
                    response.status < 500 &&
                    response.status !== 408 &&
                    response.status !== 429)
            ) {
                return response;
            }

            lastError = new Error(
                `Tandem responded with HTTP ${response.status}`,
            );
        } catch (error) {
            lastError = error;
        }

        if (attempt < MAX_ATTEMPTS) {
            await sleep(500 * 2 ** (attempt - 1));
        }
    }

    throw lastError ?? new Error('Delivery to Tandem failed');
}

export default {
    /**
     * Email Routing entry point.
     *
     * @param {ForwardableEmailMessage} message
     * @param {Record<string, string>} env
     * @param {ExecutionContext} ctx
     */
    async email(message, env, ctx) {
        const maxBytes =
            Number.parseInt(env.MAX_BYTES ?? '', 10) || DEFAULT_MAX_BYTES;

        if (!env.TANDEM_INGEST_URL || !env.TANDEM_WEBHOOK_SECRET) {
            console.error(
                'TANDEM_INGEST_URL or TANDEM_WEBHOOK_SECRET is not configured',
            );
            message.setReject('Tandem temporarily unavailable, please retry');

            return;
        }

        if (message.rawSize > maxBytes) {
            message.setReject('Message too large');

            return;
        }

        const bytes = await readRaw(message.raw, maxBytes);

        if (bytes === null) {
            message.setReject('Message too large');

            return;
        }

        const payload = {
            id: crypto.randomUUID(),
            to: message.to,
            from: message.from,
            size: bytes.byteLength,
            raw: toBase64(bytes),
            received_at: new Date().toISOString(),
            hops: countHops(message.headers),
            headers: {
                'message-id': message.headers.get('Message-ID'),
                subject: message.headers.get('Subject'),
                'auto-submitted': message.headers.get('Auto-Submitted'),
            },
        };

        const body = JSON.stringify(payload);

        try {
            const response = await deliver(
                env.TANDEM_INGEST_URL,
                body,
                env.TANDEM_WEBHOOK_SECRET,
            );

            if (response.status === 413) {
                message.setReject('Message too large');

                return;
            }

            if (!response.ok) {
                console.error(
                    `Tandem rejected message ${payload.id}: HTTP ${response.status} ${await response.text()}`,
                );
                message.setReject(
                    'Tandem temporarily unavailable, please retry',
                );

                return;
            }

            console.log(
                `Delivered ${payload.id} to=${message.to} from=${message.from} size=${payload.size} status=${response.status}`,
            );
        } catch (error) {
            console.error(
                `Delivery of ${payload.id} failed after ${MAX_ATTEMPTS} attempts: ${error.message}`,
            );
            message.setReject('Tandem temporarily unavailable, please retry');

            return;
        }

        if (env.FALLBACK_FORWARD_TO) {
            ctx.waitUntil(
                message.forward(env.FALLBACK_FORWARD_TO).catch((error) => {
                    console.error(
                        `Fallback forward to ${env.FALLBACK_FORWARD_TO} failed: ${error.message}`,
                    );
                }),
            );
        }
    },
};
