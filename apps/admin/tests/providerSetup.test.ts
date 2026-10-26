import { describe, expect, it } from 'vitest';
import { oauthScopes, setupLinks, setupValues } from '@/features/connections/providerSetup';

const redirect = 'https://example.org/wp-json/sw-cloudcalendar/v1/oauth/callback';

describe('provider setup values', () => {
  it('lists Google values incl. Drive only with attachment access', () => {
    const values = Object.fromEntries(
      setupValues('google', redirect, 'https://www.example.org/sub', false).map((v) => [
        v.key,
        v.value,
      ]),
    );
    expect(values.redirectUri).toBe(redirect);
    expect(values.jsOrigin).toBe('https://www.example.org');
    expect(values.authorizedDomain).toBe('example.org');
    expect(values.scopes).toBe('https://www.googleapis.com/auth/calendar.readonly');
    expect(oauthScopes('google', true)).toContain('https://www.googleapis.com/auth/drive.readonly');
  });

  it('lists the delegated Graph permissions the plugin requests', () => {
    const values = setupValues('microsoft', redirect, 'https://example.org', true);
    expect(values.find((v) => v.key === 'graphPermissions')?.value).toBe(
      'Calendars.Read, User.Read, offline_access, openid',
    );
    expect(values.find((v) => v.key === 'redirectUri')?.copy).toBe(true);
  });

  it('copes with a missing site URL', () => {
    const values = setupValues('google', redirect, '', false);
    expect(values.find((v) => v.key === 'jsOrigin')?.value).toBe('');
  });

  it('links the consoles over https only, Drive API only with attachment access', () => {
    const google = setupLinks('google', false).map((l) => l.href);
    expect(google[0]).toBe('https://console.cloud.google.com/');
    expect(google.some((h) => h.includes('drive.googleapis.com'))).toBe(false);
    expect(setupLinks('google', true).some((l) => l.href.includes('drive.googleapis.com'))).toBe(
      true,
    );
    const all = [...setupLinks('google', true), ...setupLinks('microsoft', false)];
    expect(all.every((l) => l.href.startsWith('https://'))).toBe(true);
    expect(setupLinks('microsoft', false)[0]?.href).toBe('https://entra.microsoft.com/');
  });
});
