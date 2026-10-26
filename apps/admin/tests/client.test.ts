import { describe, expect, it, vi } from 'vitest';
import { ApiClient, ApiError, normaliseError } from '../src/api/client';

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  return new Response(body === null ? null : JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
    ...init,
  });
}

function makeClient(fetchImpl: typeof fetch): ApiClient {
  return new ApiClient({
    restRoot: 'https://x.test/wp-json/sw-cloudcalendar/v1/',
    nonce: 'abc',
    fetchImpl,
  });
}

describe('ApiClient', () => {
  it('sends nonce, same-origin credentials and JSON body', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ id: 1 }));
    const client = makeClient(fetchMock as unknown as typeof fetch);
    const result = await client.post<{ id: number }>('admin/views', { name: 'A' });
    expect(result).toEqual({ id: 1 });
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('https://x.test/wp-json/sw-cloudcalendar/v1/admin/views');
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('same-origin');
    expect((init.headers as Record<string, string>)['X-WP-Nonce']).toBe('abc');
    expect((init.headers as Record<string, string>)['Content-Type']).toBe('application/json');
    expect(init.body).toBe('{"name":"A"}');
  });

  it('builds query strings, skipping empty values', () => {
    const client = makeClient(vi.fn() as unknown as typeof fetch);
    expect(
      client.url('/admin/logs', { page: 2, level: '', source_id: undefined, per_page: 25 }),
    ).toBe('https://x.test/wp-json/sw-cloudcalendar/v1/admin/logs?page=2&per_page=25');
  });

  it('appends to a root that already has a query (plain permalinks)', () => {
    const client = new ApiClient({
      restRoot: 'https://x.test/?rest_route=/sw-cloudcalendar/v1/',
      nonce: 'n',
    });
    expect(client.url('admin/sync/runs', { page: 1 })).toBe(
      'https://x.test/?rest_route=/sw-cloudcalendar/v1/admin/sync/runs&page=1',
    );
  });

  it('keeps template validation details of swc_invalid_template errors', async () => {
    const body = {
      code: 'swc_invalid_template',
      message: 'The template is invalid.',
      data: {
        status: 400,
        params: { body: 'invalid_template' },
        validation: {
          valid: false,
          errors: [{ code: 'unknown_placeholder', message: 'x', line: 2, column: 5 }],
          css_error: null,
        },
      },
    };
    const client = makeClient((async () =>
      jsonResponse(body, { status: 400 })) as unknown as typeof fetch);
    const error = (await client.post('admin/templates', {}).catch((e: unknown) => e)) as ApiError;
    expect(error.code).toBe('swc_invalid_template');
    expect(error.params).toEqual({ body: 'invalid_template' });
    expect(error.extra.validation).toMatchObject({
      valid: false,
      errors: [{ line: 2, column: 5 }],
    });
  });

  it('exposes the current (possibly refreshed) nonce', async () => {
    const client = makeClient((async () =>
      jsonResponse(
        {},
        {
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': 'renewed' },
        },
      )) as unknown as typeof fetch);
    expect(client.currentNonce).toBe('abc');
    await client.get('admin/meta');
    expect(client.currentNonce).toBe('renewed');
  });

  it('adopts a refreshed nonce even from error responses', async () => {
    let call = 0;
    const fetchMock = vi.fn(async () => {
      call += 1;
      return call === 1
        ? jsonResponse(
            { code: 'swc_invalid', message: 'bad', data: { status: 400 } },
            { status: 400, headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': 'n2' } },
          )
        : jsonResponse({});
    });
    const client = makeClient(fetchMock as unknown as typeof fetch);
    await client.put('admin/settings', {}).catch(() => undefined);
    await client.get('admin/settings');
    const second = fetchMock.mock.calls[1] as unknown as [string, RequestInit];
    expect((second[1].headers as Record<string, string>)['X-WP-Nonce']).toBe('n2');
  });

  it('normalises WP_Error bodies into ApiError', async () => {
    const body = {
      code: 'swc_invalid_param',
      message: 'Invalid',
      data: { status: 400, params: { name: 'Required' } },
    };
    const client = makeClient((async () =>
      jsonResponse(body, { status: 400 })) as unknown as typeof fetch);
    const error = await client.get('admin/sources').catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    const api = error as ApiError;
    expect(api.code).toBe('swc_invalid_param');
    expect(api.status).toBe(400);
    expect(api.params).toEqual({ name: 'Required' });
    expect(api.message).toBe('Invalid');
  });

  it('handles non-JSON error responses', async () => {
    const client = makeClient(
      (async () => new Response('<html>boom</html>', { status: 502 })) as unknown as typeof fetch,
    );
    const error = (await client.get('admin/meta').catch((e: unknown) => e)) as ApiError;
    expect(error.code).toBe('swc_http_502');
    expect(error.status).toBe(502);
  });

  it('maps network failures and aborts', async () => {
    const down = makeClient((async () => {
      throw new TypeError('failed');
    }) as unknown as typeof fetch);
    expect(((await down.get('admin/meta').catch((e: unknown) => e)) as ApiError).code).toBe(
      'swc_network',
    );

    const aborted = makeClient((async () => {
      throw new DOMException('x', 'AbortError');
    }) as unknown as typeof fetch);
    const err = (await aborted.get('admin/meta').catch((e: unknown) => e)) as ApiError;
    expect(err.isAborted).toBe(true);
  });

  it('adopts a refreshed nonce from the response header', async () => {
    let call = 0;
    const fetchMock = vi.fn(async () => {
      call += 1;
      return jsonResponse(
        {},
        call === 1
          ? { headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': 'fresh' } }
          : {},
      );
    });
    const client = makeClient(fetchMock as unknown as typeof fetch);
    await client.get('admin/meta');
    await client.get('admin/meta');
    const second = fetchMock.mock.calls[1] as unknown as [string, RequestInit];
    expect((second[1].headers as Record<string, string>)['X-WP-Nonce']).toBe('fresh');
  });

  it('returns null for empty 204 bodies', async () => {
    const client = makeClient(
      (async () => new Response(null, { status: 204 })) as unknown as typeof fetch,
    );
    expect(await client.delete('admin/views/1')).toBeNull();
  });
});

describe('normaliseError', () => {
  it('falls back to the HTTP status for unknown bodies', () => {
    const e = normaliseError(500, { unexpected: true });
    expect(e.code).toBe('swc_http_500');
  });
});
