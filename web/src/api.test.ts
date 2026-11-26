import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiFailure, apiWrite } from './api';

const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const csrfRefused = () => json(403, { error: { code: 'CSRF', message: 'Request refused.' } });

/** Answers each fetch in turn and records what was sent. */
function fakeFetch(...answers: Response[]) {
  const calls: { url: string; token: string | undefined }[] = [];
  vi.stubGlobal('fetch', (url: string, init: RequestInit = {}) => {
    calls.push({ url, token: (init.headers as Record<string, string> | undefined)?.['X-CSRF-Token'] });
    const next = answers.shift();
    if (!next) throw new Error(`unexpected fetch ${url}`);
    return Promise.resolve(next);
  });
  return calls;
}

afterEach(() => vi.unstubAllGlobals());

describe('apiWrite with an old CSRF token', () => {
  it('fetches the current token and tries once more', async () => {
    const calls = fakeFetch(csrfRefused(), json(200, { csrf_token: 'new' }), json(200, { ok: true }));

    await expect(apiWrite('POST', '/api/x', {}, 'old')).resolves.toEqual({ ok: true });
    expect(calls).toEqual([
      { url: '/api/x', token: 'old' },
      { url: '/api/me', token: undefined },
      { url: '/api/x', token: 'new' },
    ]);
  });

  it('tries only once', async () => {
    const calls = fakeFetch(csrfRefused(), json(200, { csrf_token: 'new' }), csrfRefused());

    await expect(apiWrite('POST', '/api/x', {}, 'old')).rejects.toMatchObject({ status: 403, code: 'CSRF' });
    expect(calls).toHaveLength(3);
  });

  it('does not retry when the token is already current or the person is signed out', async () => {
    fakeFetch(csrfRefused(), json(200, { csrf_token: 'old' }));
    await expect(apiWrite('POST', '/api/x', {}, 'old')).rejects.toBeInstanceOf(ApiFailure);

    fakeFetch(csrfRefused(), json(401, { error: { code: 'UNAUTHENTICATED' } }));
    await expect(apiWrite('POST', '/api/x', {}, 'old')).rejects.toMatchObject({ code: 'CSRF' });
  });

  it('does not retry other refusals', async () => {
    const calls = fakeFetch(json(403, { error: { code: 'FORBIDDEN' } }));

    await expect(apiWrite('POST', '/api/x', {}, 'old')).rejects.toMatchObject({ code: 'FORBIDDEN' });
    expect(calls).toHaveLength(1);
  });
});
