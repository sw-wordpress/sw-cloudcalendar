import { flushPromises, mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { Notify, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { RouterView } from 'vue-router';
import { createAppI18n } from '../src/i18n';
import { createAppRouter } from '../src/router';

/** Fixtures keyed by REST path (contract shapes). */
const connection = {
  id: 1,
  provider: 'google',
  name: 'Team Google',
  auth_mode: 'oauth',
  status: 'connected',
  account_ref: 'team',
  settings: { client_id: 'cid', attachment_access: true },
  secrets_set: ['client_secret'],
  authorized: true,
  capabilities: {
    list_calendars: true,
    incremental_sync: true,
    etag: false,
    recurrence: true,
    file_attachments: true,
    cloud_reference_downloads: true,
    public_attachment_verification: true,
  },
  source_count: 1,
  redirect_uri: 'https://x.test/wp-json/sw-cloudcalendar/v1/oauth/callback',
  updated_at: '2026-10-24T10:00:00Z',
};
const source = {
  id: 3,
  connection_id: 1,
  provider: 'google',
  external_id: 'primary',
  name: 'Vereinskalender',
  color: '#3366cc',
  timezone: 'Europe/Berlin',
  visibility: 'public',
  roles: [],
  status: 'active',
  revision: 2,
  settings: {
    sync_enabled: true,
    sync_interval_minutes: 15,
    past_days: 90,
    future_days: 365,
    exclude_private_events: false,
    excluded_event_types: ['focusTime'],
    attachments: {
      sync_attachments: true,
      public_only: true,
      max_file_size_mb: 20,
      allowed_mime_types: ['image/png'],
      generate_image_previews: true,
      show_images: true,
      show_file_list: true,
      quota_mb: 500,
      refresh_hours: 24,
      publish_private_attachments_confirmed: false,
      publish_private_attachments_confirmation: null,
    },
    publication_confirmation: {
      user_id: 1,
      confirmed_at: '2026-10-01T10:00:00Z',
      source_revision: 1,
    },
  },
  requires_confirmation: true,
  last_success_at: '2026-10-24T11:00:00Z',
  next_attempt_at: '2026-10-24T12:15:00Z',
  last_error_code: null,
  failures: 0,
  syncing: false,
  sync_queued: false,
  occurrence_count: 12,
  attachments: {
    counts: [{ sync_state: 'stored', publication_state: 'PUBLIC_VERIFIED', files: 2 }],
    last_errors: [],
    bytes: 2048,
  },
  storage_safe: true,
};
const run = {
  id: 9,
  source_id: 3,
  started_at: '2026-10-24 11:00:00',
  finished_at: '2026-10-24 11:00:04',
  status: 'success',
  mode: 'delta',
  created: 1,
  updated: 2,
  deleted: 0,
  attachments: 1,
  error_code: null,
  message: null,
  metrics: {},
};
const view = {
  id: 5,
  name: 'Startseite',
  enabled: true,
  revision: 1,
  source_ids: [3],
  default_filters: { limit: 10 },
  display_mode: 'list',
  allowed_modes: ['list', 'month'],
  container_template_id: null,
  event_template_id: 7,
  detail_template_id: null,
  allowed_template_ids: [],
  timezone: '',
  language: '',
  color_rules: [],
  html_cache: true,
  date_format: '',
  time_format: '',
  shortcode: '[sw_calendar id="5"]',
  dynamic: false,
};
const template = {
  id: 7,
  name: 'Karte',
  kind: 'event',
  editor_mode: 'html',
  revision: 1,
  preset_key: null,
  updated_at: '2026-10-24T10:00:00Z',
  used_by: [5],
  body: '<article>{{event.title}}</article>',
  css: '',
  visual_schema: null,
};
const diagnostics = {
  generated_at: '2026-10-24T12:00:00Z',
  versions: { plugin: '1.0.0', db_schema: 3, wordpress: '6.8', php: '8.3' },
  environment: {
    sodium: true,
    fileinfo: true,
    gd: true,
    imagick: false,
    intl: true,
    curl: true,
    missing_tables: [],
  },
  security: { encryption_key: 'wp_salts_fallback', capability_holders: ['administrator'] },
  storage: {
    configured: 'uploads_fallback',
    safe: false,
    method: 'http_probe',
    reason: 'reachable',
    checked_at: '2026-10-24T09:00:00Z',
    bytes: 2048,
    quota_mb: 2048,
  },
  cron: {
    mode: 'wp',
    wp_cron_disabled: false,
    next_tick: null,
    next_daily: null,
    last_tick: '2026-10-24T11:59:00Z',
    last_maintenance: null,
    jobs: { queued: 1, done: 4 },
  },
  cache: {
    html_cache_enabled: true,
    generation: 2,
    hits: 3,
    misses: 1,
    since: null,
    persistent_object_cache: false,
  },
  connections: [
    { id: 1, name: 'Team Google', provider: 'google', auth_mode: 'oauth', status: 'connected' },
  ],
  sources: [
    {
      id: 3,
      name: 'Vereinskalender',
      status: 'active',
      visibility: 'public',
      last_success_at: null,
      next_attempt_at: null,
      last_error_code: 'RATE_LIMITED',
      failures: 1,
      cursor: 'stored',
      occurrences: 12,
      last_run: run,
      attachments: source.attachments,
    },
  ],
  recent_errors: [
    {
      id: 1,
      created_at: '2026-10-24 10:00:00',
      level: 'error',
      code: 'fetch_failed',
      source_id: 3,
      message: 'Timeout',
      context: {},
    },
  ],
};
const meta = {
  roles: { administrator: 'Administrator', editor: 'Editor' },
  auth_modes: { google: ['oauth', 'api_key'], microsoft: ['oauth'], ics: ['none', 'http_header'] },
  supported_mime_types: ['image/png', 'application/pdf'],
  default_mime_types: ['image/png'],
  event_types: ['default', 'focusTime'],
  max_window_days: 1095,
  redirect_uri: connection.redirect_uri,
  storage_safe: true,
  site_timezone: 'Europe/Berlin',
  timezones: ['UTC', 'Europe/Berlin'],
  date_format: 'j. F Y',
  time_format: 'H:i',
  encryption_fallback: false,
};
const settings = {
  html_cache: true,
  delete_data_on_uninstall: false,
  cron_mode: 'system',
  log_level: 'info',
  log_retention_days: 30,
  run_retention_days: 90,
  global_quota_mb: 2048,
  revoked_file_grace_hours: 24,
  public_rate_limit: 120,
  default_view_timezone: '',
};
const page = <T>(items: T[]) => ({ items, total: items.length, pages: 1 });

const routes: Record<string, unknown> = {
  'admin/diagnostics': diagnostics,
  'admin/meta': meta,
  'admin/connections': page([connection]),
  'admin/sources': page([source]),
  'admin/sources/3': source,
  'admin/sources/3/runs': page([run]),
  'admin/views': page([view]),
  'admin/views/5': view,
  'views/5/render': { html: '<p>x</p>', css: { 'swc-view-5': '.x{}' }, total: 1 },
  'admin/templates': page([template]),
  'admin/templates/7': template,
  'admin/templates/presets': [],
  'admin/templates/reference': {
    container: [{ name: 'calendar.title', type: 'text', description: 'Title' }],
    event: [{ name: 'event.title', type: 'text', description: 'Title' }],
    detail: [],
  },
  'admin/templates/validate': { valid: true, errors: [], css_error: null },
  'admin/templates/preview': { html: '<p>p</p>', css: '', errors: [] },
  'admin/sync/runs': page([run]),
  'admin/settings': settings,
  'admin/logs': page(diagnostics.recent_errors),
  'admin/audit': page([
    {
      id: 1,
      created_at: '2026-10-24 10:00:00',
      user_id: 1,
      user_name: 'Admin',
      action: 'source_created',
      object_type: 'source',
      object_id: 3,
      context: { visibility: 'public' },
    },
  ]),
};

const PREFIX = 'https://x.test/wp-json/sw-cloudcalendar/v1/';
const fetchMock = vi.fn(async (input: string) => {
  const path = input.slice(PREFIX.length).split('?')[0] ?? '';
  const body = routes[path];
  return new Response(
    JSON.stringify(body ?? { code: 'swc_not_found', message: path, data: { status: 404 } }),
    {
      status: body === undefined ? 404 : 200,
      headers: { 'Content-Type': 'application/json' },
    },
  );
});

beforeAll(() => {
  window.swcloudcalendarConfig = {
    restRoot: PREFIX,
    nonce: 'n',
    locale: 'de_DE',
    version: '1.0.0',
    adminUrl: 'https://x.test/wp-admin/admin.php?page=sw-cloudcalendar',
    capabilities: ['manage_sw_calendar'],
    siteUrl: 'https://x.test',
    pluginUrl: 'https://x.test/wp-content/plugins/sw-cloudcalendar/',
  };
  vi.stubGlobal('fetch', fetchMock);
  // jsdom gaps used by CodeMirror and vue-router
  vi.stubGlobal(
    'ResizeObserver',
    class {
      observe(): void {}
      unobserve(): void {}
      disconnect(): void {}
    },
  );
  window.scrollTo = (() => undefined) as typeof window.scrollTo;
  window.matchMedia ??= (() => ({
    matches: false,
    addEventListener() {},
    removeEventListener() {},
  })) as never;
});
afterEach(() => {
  document.body.innerHTML = '';
});

const Host = defineComponent({ render: () => h(RouterView) });

async function render(path: string): Promise<string> {
  const errors: unknown[] = [];
  const router = createAppRouter();
  const wrapper = mount(Host, {
    attachTo: document.body,
    global: {
      plugins: [
        [Quasar, { iconSet, plugins: { Notify } }],
        createPinia(),
        router,
        createAppI18n('de_DE'),
      ] as never[],
      config: {
        errorHandler: (e: unknown) => {
          errors.push(e);
        },
      },
    },
  });
  await router.push(path);
  await router.isReady();
  for (let i = 0; i < 6; i += 1) {
    await flushPromises();
    await new Promise((r) => setTimeout(r, 20));
  }
  const text = wrapper.text();
  wrapper.unmount();
  expect(errors).toEqual([]);
  return text;
}

describe('screens render against the contract fixtures', () => {
  it('dashboard', async () => {
    const text = await render('/');
    expect(text).toContain('Vereinskalender');
    expect(text).toContain('Dateispeicher');
  });
  it('connections', async () => {
    expect(await render('/connections')).toContain('Team Google');
  });
  it('sources list and editor', async () => {
    expect(await render('/sources')).toContain('Vereinskalender');
    const editor = await render('/sources/3');
    expect(editor).toContain('Synchronisierungsverlauf');
    expect(editor).toContain('öffentliche Veröffentlichung');
  });
  it('views list and editor', async () => {
    expect(await render('/views')).toContain('[sw_calendar id="5"]');
    expect(await render('/views/5')).toContain('Live-Vorschau');
  });
  it('templates list and editors', async () => {
    expect(await render('/templates')).toContain('Karte');
    expect(await render('/templates/7')).toContain('Prüfung');
    expect(await render('/templates/new?kind=container&mode=visual')).toContain('Terminliste');
  });
  it('sync, settings, diagnostics', async () => {
    expect(await render('/sync')).toContain('Vereinskalender');
    const s = await render('/settings');
    expect(s).toContain('wp sw-cloudcalendar queue run');
    expect(s).toContain('Quelle angelegt');
    expect(await render('/diagnostics')).toContain('WordPress-Salts');
  });
});
