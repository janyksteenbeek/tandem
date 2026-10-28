import { describe, expect, it } from 'vitest';
import { digest, sign } from '../src/signature.js';

// Same vector as tests/Feature/Mail/Forwarding/WebhookSignatureTest.php in the Laravel app.
const SECRET = 'tandem-shared-secret';
const TIMESTAMP = 1761724800;
const BODY = '{"id":"evt_1","to":"x7k2m9qp4a@forward.tandem.test"}';
const EXPECTED = '2d757bd4a08d7bf921460f82dcb645d4208ad1ca492485ec0c294b55c7bd550d';

describe('signature', () => {
  it('matches the PHP test vector', async () => {
    expect(await digest(SECRET, TIMESTAMP, BODY)).toBe(EXPECTED);
  });

  it('formats the header as t=<timestamp>,v1=<hex>', async () => {
    expect(await sign(SECRET, BODY, TIMESTAMP)).toBe(`t=${TIMESTAMP},v1=${EXPECTED}`);
  });

  it('changes when the body is tampered with', async () => {
    expect(await digest(SECRET, TIMESTAMP, BODY.replace('evt_1', 'evt_2'))).not.toBe(EXPECTED);
  });
});
