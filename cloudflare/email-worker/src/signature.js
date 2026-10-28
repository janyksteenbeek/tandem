/**
 * Timestamped HMAC-SHA256 signature shared with Tandem
 * (app/Services/Mail/Providers/Forwarding/WebhookSignature.php).
 *
 * Header format: X-Tandem-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, `${t}.${body}`)>
 */

export const SIGNATURE_HEADER = 'X-Tandem-Signature';

const encoder = new TextEncoder();

function toHex(buffer) {
  return Array.from(new Uint8Array(buffer))
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('');
}

/**
 * Compute the lowercase hex HMAC-SHA256 digest of `${timestamp}.${body}`.
 *
 * @param {string} secret
 * @param {number} timestamp Unix seconds
 * @param {string} body Exact request body that will be sent
 * @returns {Promise<string>}
 */
export async function digest(secret, timestamp, body) {
  const key = await crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );

  const signature = await crypto.subtle.sign('HMAC', key, encoder.encode(`${timestamp}.${body}`));

  return toHex(signature);
}

/**
 * Build the full header value.
 *
 * @param {string} secret
 * @param {string} body
 * @param {number} [timestamp] Unix seconds, defaults to now
 * @returns {Promise<string>} e.g. "t=1761724800,v1=2d75..."
 */
export async function sign(secret, body, timestamp = Math.floor(Date.now() / 1000)) {
  return `t=${timestamp},v1=${await digest(secret, timestamp, body)}`;
}
