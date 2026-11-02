import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { Notify, Quasar } from 'quasar';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';
import { ApiError } from '../src/api/client';
import { useAsyncData } from '../src/composables/useAsyncData';
import { useFieldErrors } from '../src/composables/useFieldErrors';
import { useFormError } from '../src/composables/useFormError';
import { useNotify } from '../src/composables/useNotify';
import { useThemeSync } from '../src/composables/useTheme';
import { createAppI18n } from '../src/i18n';
import { useAppStore } from '../src/stores/app';

function harness<T>(setup: () => T) {
  let value!: T;
  const wrapper = mount(
    defineComponent({
      setup() {
        value = setup();
        return () => h('div');
      },
    }),
    {
      global: {
        plugins: [
          createPinia(),
          [Quasar, { plugins: { Notify } }],
          createAppI18n('en_US'),
        ] as never[],
      },
    },
  );
  return { wrapper, value };
}

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  localStorage.clear();
  document.body.innerHTML = '';
});

describe('theme preferences and portal synchronization', () => {
  it('persists manual themes, follows system changes and cleans up its listener', async () => {
    let listener: (event: MediaQueryListEvent) => void = () => undefined;
    const remove = vi.fn();
    vi.stubGlobal('matchMedia', () => ({
      matches: false,
      addEventListener: (_name: string, fn: typeof listener) => {
        listener = fn;
      },
      removeEventListener: remove,
    }));
    localStorage.setItem('swc-theme', 'dark');
    const root = document.createElement('div');
    document.body.append(root);
    const { wrapper, value: app } = harness(() => {
      useThemeSync(() => root);
      return useAppStore();
    });
    await nextTick();
    expect(root.classList.contains('swc-dark')).toBe(true);
    const portal = document.createElement('div');
    portal.className = 'swc-root';
    document.body.append(portal);
    await flushPromises();
    expect(portal.classList.contains('swc-dark')).toBe(true);
    app.toggleTheme();
    await nextTick();
    expect(localStorage.getItem('swc-theme')).toBe('light');
    expect(portal.classList.contains('swc-dark')).toBe(false);
    app.setTheme('system');
    listener({ matches: true } as MediaQueryListEvent);
    await nextTick();
    expect(root.classList.contains('swc-dark')).toBe(true);
    expect(localStorage.getItem('swc-theme')).toBeNull();
    app.announce('Saved');
    await nextTick();
    expect(app.announcement).toBe('Saved');
    wrapper.unmount();
    expect(remove).toHaveBeenCalledWith('change', listener);
  });
  it('still changes theme when storage is unavailable', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    setActivePinia(createPinia());
    const app = useAppStore();
    expect(app.themePreference).toBe('system');
    app.setTheme('dark');
    expect(app.isDark).toBe(true);
  });
});

describe('request and error state', () => {
  it('keeps the newest response and ignores aborted requests', async () => {
    let resolveFirst!: (value: string) => void;
    const fetcher = vi
      .fn()
      .mockImplementationOnce(
        () =>
          new Promise<string>((resolve) => {
            resolveFirst = resolve;
          }),
      )
      .mockResolvedValueOnce('new')
      .mockRejectedValueOnce(new ApiError('swc_aborted', 'aborted', 0));
    const { wrapper, value: request } = harness(() =>
      useAsyncData<string>(fetcher, { immediate: false }),
    );
    const first = request.load();
    await request.load();
    resolveFirst('old');
    await first;
    expect(request.data.value).toBe('new');
    await request.load();
    expect(request.loading.value).toBe(false);
    wrapper.unmount();
  });
  it('shows validation inline and sends operational errors to notifications', () => {
    const { wrapper, value } = harness(() => {
      const form = useFormError();
      return { form, fields: useFieldErrors(form.error), notify: useNotify() };
    });
    const toast = vi.spyOn(Notify, 'create').mockImplementation(() => () => undefined);
    const validation = new ApiError('invalid', 'Invalid', 422, {
      'settings.url': 'required',
      custom: 'unknown-code',
    });
    value.form.handle(validation);
    expect(value.fields.field('settings')).toBe('Required');
    expect(value.fields.has('custom')).toBe(true);
    expect(value.fields.message('unknown-code')).toBe('unknown-code');
    value.form.clear();
    expect(value.form.error.value).toBeNull();
    value.form.handle(new Error('offline'));
    expect(toast).toHaveBeenLastCalledWith(
      expect.objectContaining({ type: 'negative', message: 'offline' }),
    );
    value.notify.info('Queued');
    value.notify.warning('Caution', 'Details');
    value.notify.success('Saved');
    expect(toast).toHaveBeenLastCalledWith(
      expect.objectContaining({ type: 'positive', message: 'Saved' }),
    );
    value.notify.error(new ApiError('rate', 'Slow down', 429));
    expect(toast).toHaveBeenLastCalledWith(
      expect.objectContaining({ type: 'warning', caption: 'Slow down' }),
    );
    value.notify.error(validation);
    expect(toast).toHaveBeenLastCalledWith(
      expect.objectContaining({ caption: 'settings.url: required · custom: unknown-code' }),
    );
    wrapper.unmount();
  });
  it('maps nested validation and clears missing field messages', () => {
    const error = ref<ApiError | null>(new ApiError('invalid', 'Bad', 400, { name: 'required' }));
    const { wrapper, value } = harness(() => useFieldErrors(error));
    expect(value.field('name')).toBe('Required');
    expect(value.field('absent')).toBeUndefined();
    wrapper.unmount();
  });
});
