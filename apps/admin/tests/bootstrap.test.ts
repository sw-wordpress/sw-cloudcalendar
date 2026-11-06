import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';

vi.mock('../src/App.vue', () => ({ default: { template: '<p>Calendar application</p>' } }));
vi.mock('../src/router', () => ({ createAppRouter: () => ({ install() {} }) }));

afterEach(() => {
  vi.restoreAllMocks();
  document.body.innerHTML = '';
  delete window.swcloudcalendarConfig;
  vi.resetModules();
});

describe('WordPress bootstrap', () => {
  it('reports a missing mount node', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    await import('../src/main');
    expect(error).toHaveBeenCalledWith(expect.stringContaining('mount node'));
  });
  it('shows missing configuration as a safe accessible alert', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    document.body.innerHTML = '<div id="sw-cloudcalendar-app"><p>Loading</p></div>';
    await import('../src/main');
    expect(document.querySelector('[role="alert"]')?.textContent).toContain(
      'configuration missing',
    );
    expect(document.body.textContent).not.toContain('Loading');
  });
  it.each(['en_US', 'de_DE'])('mounts the configured application for %s', async (locale) => {
    window.swcloudcalendarConfig = {
      restRoot: 'https://site.test/wp-json/sw-cloudcalendar/v1/',
      nonce: 'n',
      locale,
      version: '1',
      adminUrl: '',
      siteUrl: 'https://site.test',
      pluginUrl: 'https://site.test/plugin/',
      capabilities: ['manage_sw_calendar'],
    };
    document.body.innerHTML = '<div id="sw-cloudcalendar-app"></div>';
    await import('../src/main');
    await flushPromises();
    const root = document.getElementById('sw-cloudcalendar-app')! as HTMLElement & {
      __vue_app__: App;
    };
    expect(root.classList.contains('swc-root')).toBe(true);
    expect(document.body.classList.contains('swc-admin-page')).toBe(true);
    expect(root.textContent).toContain('Calendar application');
    root.__vue_app__.unmount();
  });
});
