import { describe, expect, it } from 'vitest';
import { ApiError } from '../src/api/client';
import { offsetFor, placeholderSnippet } from '../src/components/editor/placeholderCompletion';
import { healthIssues, overallHealth } from '../src/features/dashboard/health';
import {
  attachmentTotals,
  connectionIsPrivate,
  requiredConfirmations,
  writableSettings,
  defaultSourceSettings,
} from '../src/features/sources/sourceForm';
import { cleanColorRules, fromFilterForm, toFilterForm } from '../src/features/views/viewForm';
import { normaliseConfig } from '../src/config';
import type { Diagnostics } from '../src/types/api';
import { buildPreviewDocument, escapeStyleContent, flattenCss } from '../src/utils/previewDoc';

describe('preview document', () => {
  const doc = buildPreviewDocument({
    html: '<p>Hello</p>',
    css: { 'swc-view-1': '.a{color:red}', 'swc-view-2': '.b{}</style><script>x</script>' },
    pluginUrl: 'https://example.com/wp-content/plugins/sw-cloudcalendar/',
  });
  it('includes the frontend stylesheet, the css and a restrictive CSP', () => {
    expect(doc).toContain(
      '<link rel="stylesheet" href="https://example.com/wp-content/plugins/sw-cloudcalendar/assets/css/frontend.css">',
    );
    expect(doc).toContain('.a{color:red}');
    expect(doc).toContain("script-src 'none'");
    expect(doc).toContain('<body><p>Hello</p></body>');
  });
  it('cannot be broken out of the style element', () => {
    expect(doc).not.toContain('</style><script>');
    expect(escapeStyleContent('a</STYLE>b')).toBe('a<\\/STYLE>b');
  });
  it('flattens the css shapes of the API', () => {
    expect(flattenCss('x')).toBe('x');
    expect(flattenCss(['a', 'b'])).toBe('a\nb');
    expect(flattenCss(null)).toBe('');
  });
  it('drops non-http plugin urls', () => {
    const d = buildPreviewDocument({ html: '', pluginUrl: 'javascript:alert(1)//' });
    expect(d).not.toContain('<link');
    expect(d).toContain("style-src 'unsafe-inline' 'none'");
  });
});

describe('source form', () => {
  it('detects pure confirmation requests', () => {
    const both = new ApiError('swc_invalid', 'x', 400, {
      confirm_private_attachments: 'required',
      confirm_publication: 'required',
    });
    expect(requiredConfirmations(both)).toEqual([
      'confirm_publication',
      'confirm_private_attachments',
    ]);
    const mixed = new ApiError('swc_invalid', 'x', 400, {
      confirm_publication: 'required',
      name: 'invalid',
    });
    expect(requiredConfirmations(mixed)).toBeNull();
    expect(requiredConfirmations(new ApiError('swc_error', 'x', 500))).toBeNull();
  });
  it('never sends confirmation structures', () => {
    const settings = {
      ...defaultSourceSettings(null),
      publication_confirmation: { user_id: 1, confirmed_at: 'x', source_revision: 1 },
    };
    settings.attachments.publish_private_attachments_confirmed = true;
    const out = writableSettings(settings);
    expect(out).not.toHaveProperty('publication_confirmation');
    expect(out.attachments).not.toHaveProperty('publish_private_attachments_confirmed');
    expect(out.attachments.public_only).toBe(true);
  });
  it('treats OAuth and authenticated feeds as private at the provider', () => {
    expect(connectionIsPrivate({ auth_mode: 'oauth' })).toBe(true);
    expect(connectionIsPrivate({ auth_mode: 'http_header' })).toBe(true);
    expect(connectionIsPrivate({ auth_mode: 'api_key' })).toBe(false);
    expect(connectionIsPrivate({ auth_mode: 'none' })).toBe(false);
  });
  it('sums attachment counts', () => {
    expect(
      attachmentTotals({
        counts: [
          { sync_state: 'stored', publication_state: 'PUBLIC_VERIFIED', files: 3 },
          { sync_state: 'skipped', publication_state: 'PRIVATE', files: 2 },
        ],
        last_errors: [],
        bytes: 0,
      }),
    ).toEqual({ stored: 3, total: 5 });
  });
});

describe('view form', () => {
  it('round-trips default filters and drops unset values', () => {
    const form = toFilterForm({ limit: 10, past: 'true', sort: 'desc', show_images: false });
    expect(form.past).toBe('yes');
    expect(form.show_images).toBe('no');
    expect(form.show_attachments).toBe('default');
    expect(fromFilterForm(form)).toEqual({
      limit: 10,
      past: true,
      sort: 'desc',
      show_images: false,
    });
    expect(fromFilterForm(toFilterForm([]))).toEqual({});
  });
  it('keeps colour rules only for selected sources', () => {
    expect(cleanColorRules({ '1': '#AABBCC', '2': '#000000', '3': 'red' }, [1, 3])).toEqual({
      '1': '#aabbcc',
    });
  });
});

describe('editor helpers', () => {
  it('maps server line/column to offsets', () => {
    const lines = ['<div>', '  {{x}}', '</div>'];
    expect(offsetFor(lines, 2, 3)).toEqual({ from: 8, to: 9 });
    expect(offsetFor(lines, 99, 99).from).toBeLessThanOrEqual(lines.join('\n').length);
    expect(offsetFor(lines, 0, 0)).toEqual({ from: 0, to: 1 });
  });
  it('builds placeholder snippets', () => {
    expect(placeholderSnippet('event.title')).toBe('{{event.title}}');
    expect(placeholderSnippet('event.location', '#')).toBe(
      '{{#event.location}}{{/event.location}}',
    );
    expect(placeholderSnippet('event.location', '^')).toBe(
      '{{^event.location}}{{/event.location}}',
    );
  });
});

describe('dashboard health', () => {
  const base: Diagnostics = {
    generated_at: '2026-10-24T12:00:00Z',
    versions: { plugin: '1', db_schema: 1, wordpress: '6.8', php: '8.3' },
    environment: {
      sodium: true,
      fileinfo: true,
      gd: true,
      imagick: false,
      intl: true,
      curl: true,
      missing_tables: [],
    },
    security: { encryption_key: 'dedicated_key', capability_holders: ['administrator'] },
    storage: {
      configured: 'constant',
      safe: true,
      method: 'outside_webroot',
      reason: '',
      checked_at: null,
      bytes: 0,
      quota_mb: 100,
    },
    cron: {
      mode: 'wp',
      wp_cron_disabled: false,
      next_tick: null,
      next_daily: null,
      last_tick: '2026-10-24T11:59:00Z',
      last_maintenance: null,
      jobs: {},
    },
    cache: {
      html_cache_enabled: true,
      generation: 1,
      hits: 0,
      misses: 0,
      since: null,
      persistent_object_cache: false,
    },
    connections: [],
    sources: [],
    recent_errors: [],
  };
  const now = new Date('2026-10-24T12:00:00Z');
  it('is ok for a healthy installation', () => {
    expect(overallHealth(healthIssues(base, now))).toBe('ok');
  });
  it('reports storage, key fallback and cron problems', () => {
    const issues = healthIssues(
      {
        ...base,
        storage: { ...base.storage, safe: false },
        security: { ...base.security, encryption_key: 'wp_salts_fallback' },
        cron: { ...base.cron, wp_cron_disabled: true },
      },
      now,
    );
    expect(issues.map((i) => i.key)).toEqual([
      'storageUnsafe',
      'encryptionFallback',
      'wpCronDisabled',
    ]);
    expect(overallHealth(issues)).toBe('error');
  });
});

describe('config', () => {
  it('normalises the PHP config', () => {
    const cfg = normaliseConfig({
      restRoot: 'https://x/wp-json/sw-cloudcalendar/v1',
      nonce: 'n',
      pluginUrl: 'https://x/wp-content/plugins/sw-cloudcalendar',
      capabilities: ['manage_sw_calendar', 3 as unknown as string],
    });
    expect(cfg.restRoot).toBe('https://x/wp-json/sw-cloudcalendar/v1/');
    expect(cfg.pluginUrl).toBe('https://x/wp-content/plugins/sw-cloudcalendar/');
    expect(cfg.capabilities).toEqual(['manage_sw_calendar']);
    expect(() => normaliseConfig(undefined)).toThrow();
  });
});
