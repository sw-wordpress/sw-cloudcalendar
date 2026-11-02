import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { Notify, QBtn, QForm, QInput, Quasar } from 'quasar';
import ConfirmDialog from '../src/components/ui/ConfirmDialog.vue';
import FormSheet from '../src/components/ui/FormSheet.vue';
import ConnectionSheet from '../src/features/connections/ConnectionSheet.vue';
import iconSet from 'quasar/icon-set/material-icons-round';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import App from '../src/App.vue';
import { resetConfigForTests } from '../src/config';
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
    sync_interval_hours: 6,
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
  'admin/connections/1': connection,
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
const fetchMock = vi.fn(async (input: string, init?: RequestInit) => {
  const path = input.slice(PREFIX.length).split('?')[0] ?? '';
  const method = init?.method ?? 'GET';
  let body = routes[path];
  if (method === 'DELETE') body = { deleted: true };
  if (method === 'POST' && path === 'admin/connections') body = connection;
  if (method === 'POST' && path === 'admin/templates') body = template;
  if (method === 'PUT' && path === 'admin/settings') body = settings;
  if (path === 'admin/connections/1/test')
    body = { ok: true, message: 'Connection works', error_code: null };
  if (path === 'admin/connections/1/disconnect') body = { ...connection, status: 'disconnected' };
  if (path === 'admin/sources/3/sync' || path === 'admin/sync/queue') body = { queued: true };
  if (path === 'admin/cache/clear') body = { cleared: true };
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
  fetchMock.mockClear();
  document.body.innerHTML = '';
  localStorage.clear();
});

async function render(
  path: string,
  interact?: (wrapper: VueWrapper) => Promise<void>,
): Promise<string> {
  resetConfigForTests();
  const errors: unknown[] = [];
  const router = createAppRouter();
  const wrapper = mount(App, {
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
    await settle();
    await new Promise((r) => setTimeout(r, 20));
  }
  if (interact) await interact(wrapper);
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

async function settle() {
  for (let i = 0; i < 3; i += 1) {
    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
}
async function clickIcon(wrapper: VueWrapper, icon: string) {
  const button = wrapper
    .findAllComponents(QBtn)
    .find((candidate) => candidate.props('icon') === icon);
  expect(button, `button ${icon}`).toBeDefined();
  await button!.trigger('click');
  await settle();
}
function written(path: string, method: string) {
  const request = fetchMock.mock.calls.find(
    ([url, init]) => url.endsWith(path) && init?.method === method,
  );
  expect(request, `${method} ${path}`).toBeDefined();
  return JSON.parse(String(request![1]!.body ?? '{}'));
}

describe('admin actions against REST contracts', () => {
  it('saves view filters and refreshes its preview', async () => {
    await render('/views/5', async (wrapper) => {
      wrapper.getComponent(QForm).vm.$emit('submit');
      await settle();
      expect(written('admin/views/5', 'PUT')).toMatchObject({
        name: 'Startseite',
        source_ids: [3],
        default_filters: { limit: 10 },
      });
    });
  });
  it('saves a source without resubmitting server confirmation records', async () => {
    await render('/sources/3', async (wrapper) => {
      wrapper.getComponent(QForm).vm.$emit('submit');
      await settle();
      const body = written('admin/sources/3', 'PUT');
      expect(body).toMatchObject({ name: 'Vereinskalender', visibility: 'public' });
      expect(body.settings).not.toHaveProperty('publication_confirmation');
      expect(body.settings.attachments).not.toHaveProperty(
        'publish_private_attachments_confirmation',
      );
      await clickIcon(wrapper, 'r_sync');
      expect(written('admin/sources/3/sync', 'POST')).toEqual({});
    });
  });
  it('requires explicit consent when saving public provider data', async () => {
    await render('/sources/3', async (wrapper) => {
      fetchMock.mockImplementationOnce(
        async () =>
          new Response(
            JSON.stringify({
              code: 'invalid',
              message: 'Consent required',
              data: { status: 400, params: { confirm_publication: 'required' } },
            }),
            { status: 400 },
          ),
      );
      wrapper.getComponent(QForm).vm.$emit('submit');
      await settle();
      const dialog = wrapper
        .findAllComponents(ConfirmDialog)
        .find((item) => item.props('modelValue'))!;
      expect(dialog).toBeDefined();
      expect(
        fetchMock.mock.calls.filter(
          ([url, init]) => url.endsWith('admin/sources/3') && init?.method === 'PUT',
        ),
      ).toHaveLength(1);
      dialog.vm.$emit('confirm');
      await settle();
      const requests = fetchMock.mock.calls.filter(
        ([url, init]) => url.endsWith('admin/sources/3') && init?.method === 'PUT',
      );
      expect(JSON.parse(String(requests.at(-1)![1]!.body))).toMatchObject({
        confirm_publication: true,
      });
    });
  });
  it('saves settings and explicitly clears the cache', async () => {
    await render('/settings', async (wrapper) => {
      wrapper.getComponent(QForm).vm.$emit('submit');
      await settle();
      expect(written('admin/settings', 'PUT')).toHaveProperty('cron_mode');
      await clickIcon(wrapper, 'r_cleaning_services');
      expect(written('admin/cache/clear', 'POST')).toEqual({ scope: 'all' });
    });
  });
  it('saves the template body and styling', async () => {
    await render('/templates/7', async (wrapper) => {
      await clickIcon(wrapper, 'r_save');
      expect(written('admin/templates/7', 'PUT')).toMatchObject({ body: template.body, css: '' });
    });
  });
  it('tests and disconnects a connection after confirmation', async () => {
    await render('/connections', async (wrapper) => {
      await clickIcon(wrapper, 'r_network_check');
      expect(wrapper.text()).toContain('Connection works');
      await clickIcon(wrapper, 'r_link_off');
      const dialog = wrapper
        .findAllComponents(ConfirmDialog)
        .find((item) => item.props('modelValue'))!;
      dialog.vm.$emit('confirm');
      await settle();
      written('admin/connections/1/disconnect', 'POST');
    });
  });
  it('edits a connection and keeps unchanged secrets write-only', async () => {
    await render('/connections', async (wrapper) => {
      await clickIcon(wrapper, 'r_edit');
      const sheet = wrapper.getComponent(ConnectionSheet);
      sheet.getComponent(FormSheet).vm.$emit('submit');
      await settle();
      expect(written('admin/connections/1', 'PUT')).toMatchObject({
        name: 'Team Google',
        secrets: {},
        settings: { client_id: 'cid' },
      });
      expect(sheet.props('modelValue')).toBe(false);
    });
  });
  it('creates an ICS connection with trimmed settings and no unrelated secrets', async () => {
    await render('/connections', async (wrapper) => {
      await clickIcon(wrapper, 'r_add');
      const sheet = wrapper.getComponent(ConnectionSheet);
      const inputs = sheet.findAllComponents(QInput);
      inputs
        .find((input) => input.props('label') === 'Name')!
        .vm.$emit('update:modelValue', '  New feed  ');
      inputs
        .find((input) => input.props('label') === 'Feed-URL')!
        .vm.$emit('update:modelValue', '  https://site.test/feed.ics  ');
      await settle();
      sheet.getComponent(FormSheet).vm.$emit('submit');
      await settle();
      expect(written('admin/connections', 'POST')).toMatchObject({
        name: 'New feed',
        provider: 'ics',
        auth_mode: 'none',
        secrets: {},
        settings: { url: 'https://site.test/feed.ics' },
      });
    });
  });
  it.each(['/views', '/templates', '/sources', '/connections'])(
    'deletes from %s only after confirmation',
    async (path) => {
      await render(path, async (wrapper) => {
        const before = fetchMock.mock.calls.filter(([, init]) => init?.method === 'DELETE').length;
        await clickIcon(wrapper, 'r_delete');
        expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'DELETE')).toHaveLength(
          before,
        );
        const dialog = wrapper
          .findAllComponents(ConfirmDialog)
          .find((item) => item.props('modelValue'))!;
        dialog.vm.$emit('confirm');
        await settle();
        expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'DELETE')).toHaveLength(
          before + 1,
        );
      });
    },
  );
  it('allows theme switching and keyboard navigation through the shell', async () => {
    await render('/views', async (wrapper) => {
      await clickIcon(wrapper, 'r_dark_mode');
      expect(localStorage.getItem('swc-theme')).toBe('dark');
      await clickIcon(wrapper, 'r_keyboard_double_arrow_left');
      expect(wrapper.get('.swc-shell').classes()).toContain('swc-shell--collapsed');
      await wrapper.get('.swc-skip').trigger('click');
      expect(document.activeElement?.id).toBe('swc-main');
    });
  });
  it('renders an access error without mounting admin data for a user without capabilities', async () => {
    window.swcloudcalendarConfig!.capabilities = [];
    try {
      expect(await render('/')).toContain('Zugriff');
    } finally {
      window.swcloudcalendarConfig!.capabilities = ['manage_sw_calendar'];
    }
  });
});
