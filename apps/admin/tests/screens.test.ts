import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { Notify, QBtn, QForm, QInput, QSelect, QToggle, Quasar } from 'quasar';
import ConfirmDialog from '../src/components/ui/ConfirmDialog.vue';
import FormSheet from '../src/components/ui/FormSheet.vue';
import SegmentedControl from '../src/components/ui/SegmentedControl.vue';
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
  local_sources: [{ id: 'churchtools-suite', name: 'ChurchTools Integration Suite' }],
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
  it.each([true, false])(
    'opens events on demand in the source editor (local: %s)',
    async (local) => {
      const original = routes['admin/sources/3'];
      routes['admin/sources/3'] = { ...source, provider: local ? 'local' : 'google', local };
      routes['admin/sources/3/events'] = page([
        {
          title: 'Local meeting',
          start: '2026-11-03T09:00:00Z',
          end: '2026-11-03T10:00:00Z',
          all_day: false,
          location: 'Office',
        },
      ]);
      try {
        await render('/sources/3', async (wrapper) => {
          expect(wrapper.text()).not.toContain('Verfügbare Termine');
          if (local) {
            expect(wrapper.text()).not.toContain('Letzte Synchronisierung:');
            expect(wrapper.text()).not.toContain('Nächster Lauf:');
            expect(wrapper.text()).not.toContain('Synchronisation manuell durchführen');
          } else {
            expect(wrapper.text()).toContain('Letzte Synchronisierung:');
            expect(wrapper.text()).toContain('Nächster Lauf:');
          }
          const initialRequests = fetchMock.mock.calls.filter(([url]) =>
            url.includes('admin/sources/3/events'),
          );
          expect(initialRequests).toHaveLength(local ? 1 : 0);
          if (local) {
            expect(initialRequests[0]![0]).toContain('per_page=1');
            expect(wrapper.text()).toContain('Termine im aktuellen Monat: 1');
          } else expect(wrapper.text()).toContain(`Termine: ${source.occurrence_count}`);
          const toggle = () =>
            wrapper.findAllComponents(QBtn).find((c) => c.props('label') === 'Termine')!;
          const counter = wrapper
            .findAllComponents(QBtn)
            .find((c) =>
              String(c.props('label')).startsWith(
                local ? 'Termine im aktuellen Monat:' : 'Termine:',
              ),
            )!;
          await counter.trigger('click');
          await settle();
          expect(wrapper.text()).toContain('Verfügbare Termine');
          expect(wrapper.text()).toContain('Local meeting');
          expect(toggle().attributes('aria-expanded')).toBe('true');
          await toggle().trigger('click');
          await settle();
          expect(wrapper.text()).not.toContain('Verfügbare Termine');
        });
      } finally {
        routes['admin/sources/3'] = original;
        delete routes['admin/sources/3/events'];
      }
    },
  );
  it('does not report zero when the local count is unavailable', async () => {
    const original = routes['admin/sources/3'];
    routes['admin/sources/3'] = { ...source, provider: 'local', local: true };
    try {
      await render('/sources/3', async (wrapper) => {
        expect(wrapper.text()).toContain('Termine im aktuellen Monat: –');
        expect(wrapper.text()).not.toContain('Termine im aktuellen Monat: 0');
      });
    } finally {
      routes['admin/sources/3'] = original;
    }
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
  it('offers inline explanations for all three display settings and an overview', async () => {
    await render('/views/5', async (wrapper) => {
      const labels = [
        'Hilfe zum Aufbau der Kalenderansicht',
        'Hilfe zur Standarddarstellung',
        'Hilfe zu erlaubten Darstellungen',
        'Hilfe zu alternativen Termin-Templates',
      ];
      for (const label of labels)
        expect(
          wrapper.findAllComponents(QBtn).some((c) => c.attributes('aria-label') === label),
        ).toBe(true);
      const help = wrapper
        .findAllComponents(QBtn)
        .find((c) => c.attributes('aria-label') === labels[0])!;
      await help.trigger('click');
      await settle();
      expect(document.body.textContent).toContain('So entsteht die Kalenderansicht');
      expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PUT')).toBe(false);
    });
  });
  it('saves visitor tabs and the search visibility setting', async () => {
    await render('/views/5', async (wrapper) => {
      const select = (label: string) =>
        wrapper.findAllComponents(QSelect).find((c) => c.props('label') === label)!;
      select('Darstellungs-Tabs auf der Website').vm.$emit('update:modelValue', 'yes');
      select('Terminsuche anzeigen').vm.$emit('update:modelValue', 'no');
      await settle();
      wrapper.getComponent(QForm).vm.$emit('submit');
      await settle();
      expect(written('admin/views/5', 'PUT')).toMatchObject({
        default_filters: { mode_switcher: true, show_search: false },
      });
    });
  });
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
  it('creates local calendars together and retries only unfinished drafts', async () => {
    const original = routes['admin/connections'];
    const originalCalendars = routes['admin/connections/1/calendars'];
    routes['admin/connections'] = {
      items: [{ ...connection, provider: 'local', auth_mode: 'none' }],
    };
    routes['admin/connections/1/calendars'] = {
      items: [
        {
          external_id: 'a',
          name: 'Alpha',
          color: '#AABBCC',
          timezone: 'Europe/Berlin',
          public_at_provider: false,
        },
        { external_id: 'b', name: 'Beta', color: null, timezone: null, public_at_provider: false },
      ],
    };
    const implementation = fetchMock.getMockImplementation()!;
    let fail = true;
    fetchMock.mockImplementation(async (url, init) => {
      if (url.endsWith('admin/sources') && init?.method === 'POST') {
        const body = JSON.parse(String(init.body));
        if (body.external_id === 'b' && fail) {
          fail = false;
          return new Response(
            JSON.stringify({ code: 'failed', message: 'Retry', data: { status: 500 } }),
            { status: 500 },
          );
        }
        return new Response(JSON.stringify({ ...source, ...body }));
      }
      return implementation(url, init);
    });
    try {
      await render('/sources/new', async (wrapper) => {
        wrapper
          .findAllComponents(QToggle)
          .find((c) => c.props('label') === 'Mehrere Kalender hinzufügen')!
          .vm.$emit('update:modelValue', true);
        await settle();
        const button = (label: string) =>
          wrapper.findAllComponents(QBtn).find((c) => c.props('label') === label)!;
        await button('Alle auswählen').trigger('click');
        await settle();
        expect(
          wrapper
            .findAll('input[type="checkbox"]')
            .filter((c) => (c.element as HTMLInputElement).checked),
        ).toHaveLength(2);
        await button('Auswahl aufheben').trigger('click');
        await settle();
        await wrapper.get('input[value="a"]').setValue(true);
        await wrapper.get('input[value="b"]').setValue(true);
        await button('Ausgewählte als Entwürfe anlegen').trigger('click');
        await settle();
        expect(wrapper.get('input[value="a"]').element).toHaveProperty('checked', false);
        expect(wrapper.get('input[value="b"]').element).toHaveProperty('checked', true);
        expect(written('admin/sources', 'POST')).toMatchObject({
          external_id: 'a',
          name: 'Alpha',
          color: '#aabbcc',
          timezone: 'Europe/Berlin',
          visibility: 'authenticated',
          roles: [],
        });
        await button('Ausgewählte als Entwürfe anlegen').trigger('click');
        await settle();
        const bodies = fetchMock.mock.calls
          .filter(([url, init]) => url.endsWith('admin/sources') && init?.method === 'POST')
          .map(([, init]) => JSON.parse(String(init!.body)));
        expect(bodies.map((b) => b.external_id)).toEqual(['a', 'b', 'b']);
        expect(bodies[2]).toMatchObject({ name: 'Beta', color: '#3366cc' });
      });
    } finally {
      routes['admin/connections'] = original;
      routes['admin/connections/1/calendars'] = originalCalendars;
      fetchMock.mockImplementation(implementation);
    }
  });
  it.each([
    ['active', { status: 'active' }],
    ['paused', { status: 'paused' }],
    ['public', { visibility: 'public', roles: [] }],
    ['authenticated', { visibility: 'authenticated', roles: [] }],
  ])('applies bulk %s only to checked sources', async (action, patch) => {
    const original = routes['admin/sources'];
    routes['admin/sources'] = page([source, { ...source, id: 4, name: 'Second' }]);
    try {
      await render('/sources', async (wrapper) => {
        const apply = () =>
          wrapper.findAllComponents(QBtn).find((c) => c.props('label') === 'Anwenden')!;
        expect(apply().props('disable')).toBe(true);
        expect(wrapper.get('optgroup').attributes('label')).toBe('Sichtbarkeit');
        await wrapper.get('thead input[type="checkbox"]').setValue(true);
        await wrapper.get('input[value="4"]').setValue(false);
        expect(wrapper.get('thead input').element).toHaveProperty('indeterminate', true);
        await wrapper.get('select').setValue(action);
        await apply().trigger('click');
        await settle();
        expect(written('admin/sources/3', 'PUT')).toEqual(patch);
        expect(
          fetchMock.mock.calls.some(
            ([url, init]) => url.endsWith('admin/sources/4') && init?.method === 'PUT',
          ),
        ).toBe(false);
        expect(wrapper.get('input[value="3"]').element).toHaveProperty('checked', false);
      });
    } finally {
      routes['admin/sources'] = original;
    }
  });

  it('confirms publication and private files separately for the remaining bulk selection', async () => {
    const implementation = fetchMock.getMockImplementation()!;
    fetchMock.mockImplementation(async (url, init) => {
      if (url.endsWith('admin/sources/3') && init?.method === 'PUT') {
        const body = JSON.parse(String(init.body));
        const params: Record<string, string> = {};
        if (!body.confirm_publication) params.confirm_publication = 'required';
        if (!body.confirm_private_attachments) params.confirm_private_attachments = 'required';
        if (Object.keys(params).length)
          return new Response(
            JSON.stringify({
              code: 'invalid',
              message: 'Consent required',
              data: { status: 400, params },
            }),
            { status: 400 },
          );
      }
      return implementation(url, init);
    });
    try {
      await render('/sources', async (wrapper) => {
        await wrapper.get('input[value="3"]').setValue(true);
        await wrapper.get('select').setValue('public');
        await wrapper
          .findAllComponents(QBtn)
          .find((c) => c.props('label') === 'Anwenden')!
          .trigger('click');
        await settle();
        const dialog = () =>
          wrapper.findAllComponents(ConfirmDialog).find((c) => c.props('modelValue'))!;
        expect(dialog().props('target')).toBe(source.name);
        expect(dialog().props('checkboxLabel')).toBeTruthy();
        // Cancelling must never send a confirmation flag.
        dialog().vm.$emit('update:modelValue', false);
        await settle();
        expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'PUT')).toHaveLength(1);
        await wrapper
          .findAllComponents(QBtn)
          .find((c) => c.props('label') === 'Anwenden')!
          .trigger('click');
        await settle();
        dialog().vm.$emit('confirm');
        await settle();
        expect(dialog().props('checkboxLabel')).toBeTruthy();
        dialog().vm.$emit('confirm');
        await settle();
        const calls = fetchMock.mock.calls.filter(([, init]) => init?.method === 'PUT');
        expect(JSON.parse(String(calls.at(-1)![1]!.body))).toEqual({
          visibility: 'public',
          roles: [],
          confirm_publication: true,
          confirm_private_attachments: true,
        });
        expect(wrapper.get('input[value="3"]').element).toHaveProperty('checked', false);
      });
    } finally {
      fetchMock.mockImplementation(implementation);
    }
  });

  it('retains only unfinished sources after a bulk failure', async () => {
    const original = routes['admin/sources'];
    routes['admin/sources'] = page([source, { ...source, id: 4, name: 'Second' }]);
    const implementation = fetchMock.getMockImplementation()!;
    let fail = true;
    fetchMock.mockImplementation(async (url, init) => {
      if (url.endsWith('admin/sources/4') && init?.method === 'PUT') {
        if (fail) {
          fail = false;
          return new Response(
            JSON.stringify({ code: 'failed', message: 'Try again', data: { status: 500 } }),
            { status: 500 },
          );
        }
        return new Response(JSON.stringify({ ...source, id: 4 }));
      }
      return implementation(url, init);
    });
    try {
      await render('/sources', async (wrapper) => {
        await wrapper.get('thead input').setValue(true);
        await wrapper.get('select').setValue('paused');
        const apply = () =>
          wrapper.findAllComponents(QBtn).find((c) => c.props('label') === 'Anwenden')!;
        await apply().trigger('click');
        await settle();
        expect(wrapper.text()).toContain('Second: Try again');
        expect(wrapper.get('input[value="3"]').element).toHaveProperty('checked', false);
        expect(wrapper.get('input[value="4"]').element).toHaveProperty('checked', true);
        await apply().trigger('click');
        await settle();
        const ids = fetchMock.mock.calls
          .filter(([, init]) => init?.method === 'PUT')
          .map(([url]) => url.split('/').at(-1));
        expect(ids).toEqual(['3', '4', '4']);
      });
    } finally {
      routes['admin/sources'] = original;
      fetchMock.mockImplementation(implementation);
    }
  });
  it('runs a manual sync directly and refreshes the source and history', async () => {
    routes['admin/sources/3/sync-now'] = source;
    try {
      await render('/sources/3', async (wrapper) => {
        const button = wrapper
          .findAllComponents(QBtn)
          .find((c) => c.props('label') === 'Synchronisation manuell durchführen')!;
        expect(button.props('disable')).toBe(false);
        await button.trigger('click');
        await settle();
        expect(written('admin/sources/3/sync-now', 'POST')).toEqual({});
        expect(
          fetchMock.mock.calls.some(
            ([url, init]) => url.endsWith('admin/sources/3/sync') && init?.method === 'POST',
          ),
        ).toBe(false);
        expect(
          fetchMock.mock.calls.filter(([url]) => url.endsWith('admin/sources/3')).length,
        ).toBeGreaterThan(1);
      });
    } finally {
      delete routes['admin/sources/3/sync-now'];
    }
  });

  it('reports manual sync errors and releases the button for another attempt', async () => {
    await render('/sources/3', async (wrapper) => {
      const notify = vi.spyOn(Notify, 'create').mockImplementation(() => () => undefined);
      try {
        fetchMock.mockImplementationOnce(
          async () =>
            new Response(
              JSON.stringify({
                code: 'swc_sync_failed',
                message: 'Synchronization failed',
                data: { status: 502 },
              }),
              { status: 502 },
            ),
        );
        const button = () =>
          wrapper
            .findAllComponents(QBtn)
            .find((c) => c.props('label') === 'Synchronisation manuell durchführen')!;
        await button().trigger('click');
        await settle();
        expect(notify).toHaveBeenCalledWith(
          expect.objectContaining({ type: 'negative', message: 'Synchronization failed' }),
        );
        expect(notify).not.toHaveBeenCalledWith(expect.objectContaining({ type: 'positive' }));
        expect(button().props('loading')).toBe(false);
      } finally {
        notify.mockRestore();
      }
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

it('creates an external plugin connection without requesting credentials', async () => {
  await render('/connections', async (wrapper) => {
    await clickIcon(wrapper, 'r_add');
    const sheet = wrapper.getComponent(ConnectionSheet);
    sheet.getComponent(SegmentedControl).vm.$emit('update:modelValue', 'local');
    await settle();
    const name = sheet.findAllComponents(QInput).find((input) => input.props('label') === 'Name')!;
    name.vm.$emit('update:modelValue', ' ChurchTools ');
    const select = sheet.getComponent(QSelect);
    expect(select.props('options')).toContainEqual({
      label: 'ChurchTools Integration Suite',
      value: 'churchtools-suite',
    });
    select.vm.$emit('update:modelValue', 'churchtools-suite');
    await settle();
    sheet.getComponent(FormSheet).vm.$emit('submit');
    await settle();
    expect(written('admin/connections', 'POST')).toMatchObject({
      name: 'ChurchTools',
      provider: 'local',
      auth_mode: 'none',
      settings: { adapter: 'churchtools-suite' },
      secrets: {},
    });
  });
});
