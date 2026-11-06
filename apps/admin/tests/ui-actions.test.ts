import { flushPromises, mount } from '@vue/test-utils';
import { Notify, QInput, Quasar } from 'quasar';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CopyButton from '../src/components/CopyButton.vue';
import ConfirmDialog from '../src/components/ui/ConfirmDialog.vue';
import SecretInput from '../src/components/ui/SecretInput.vue';
import ProviderSetupDialog from '../src/features/connections/ProviderSetupDialog.vue';
import { resetConfigForTests } from '../src/config';
import { createAppI18n } from '../src/i18n';

const global = { plugins: [[Quasar, { plugins: { Notify } }], createAppI18n('en_US')] as never[] };

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  Reflect.deleteProperty(document, 'execCommand');
  Reflect.deleteProperty(navigator, 'clipboard');
  document.body.innerHTML = '';
});

describe('clipboard and secret controls', () => {
  it('copies through the clipboard API and reports success', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
    const wrapper = mount(CopyButton, {
      props: { text: 'private value', label: 'Copy key' },
      global,
    });
    const notify = vi.spyOn(Notify, 'create').mockImplementation(() => () => undefined);
    await wrapper.get('button').trigger('click');
    await flushPromises();
    expect(writeText).toHaveBeenCalledWith('private value');
    expect(notify).toHaveBeenCalledWith(expect.objectContaining({ type: 'positive' }));
    wrapper.unmount();
  });
  it('uses the legacy fallback without leaving secret text in the document', async () => {
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
    const exec = vi.fn().mockReturnValue(true);
    Object.defineProperty(document, 'execCommand', { configurable: true, value: exec });
    const wrapper = mount(CopyButton, { props: { text: 'secret', label: 'Copy' }, global });
    vi.spyOn(Notify, 'create').mockImplementation(() => () => undefined);
    await wrapper.get('button').trigger('click');
    await flushPromises();
    expect(exec).toHaveBeenCalledWith('copy');
    expect(document.querySelector('textarea')).toBeNull();
    wrapper.unmount();
  });
  it('warns when clipboard permission is denied', async () => {
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText: vi.fn().mockRejectedValue(new Error('Denied')) },
    });
    const wrapper = mount(CopyButton, { props: { text: 'text', label: 'Copy' }, global });
    const notify = vi.spyOn(Notify, 'create').mockImplementation(() => () => undefined);
    await wrapper.get('button').trigger('click');
    await flushPromises();
    expect(notify).toHaveBeenCalledWith(expect.objectContaining({ type: 'warning' }));
    wrapper.unmount();
  });
  it('leaves stored secrets blank and validates only a required replacement', async () => {
    const wrapper = mount(SecretInput, {
      props: { modelValue: '', label: 'Secret', isSet: true, required: true },
      global,
    });
    expect(wrapper.get('input').attributes('type')).toBe('password');
    expect(wrapper.get('input').element.value).toBe('');
    expect(await wrapper.getComponent(QInput).vm.validate()).toBe(true);
    await wrapper.setProps({ isSet: false });
    expect(await wrapper.getComponent(QInput).vm.validate()).toBe(false);
    await wrapper.get('input').setValue('replacement');
    expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual(['replacement']);
    wrapper.unmount();
  });
});

it('requires both acknowledgement and typed confirmation for destructive actions', async () => {
  const wrapper = mount(ConfirmDialog, {
    props: {
      modelValue: true,
      title: 'Delete',
      confirmLabel: 'Delete now',
      requireText: 'Calendar',
      checkboxLabel: 'I understand',
    },
    global,
    attachTo: document.body,
  });
  await flushPromises();
  const confirm = () =>
    [...document.body.querySelectorAll('button')].find((button) =>
      button.textContent?.includes('Delete now'),
    )!;
  expect(confirm().disabled).toBe(true);
  const input = document.body.querySelector('input')!;
  input.value = 'Calendar';
  input.dispatchEvent(new Event('input'));
  await flushPromises();
  expect(confirm().disabled).toBe(true);
  (document.body.querySelector('[role="checkbox"]') as HTMLElement).click();
  await flushPromises();
  expect(confirm().disabled).toBe(false);
  confirm().click();
  await flushPromises();
  expect(wrapper.emitted('confirm')).toHaveLength(1);
  wrapper.unmount();
});

it.each(['google', 'microsoft'] as const)(
  'shows site-specific secure setup links for %s',
  async (provider) => {
    window.swcloudcalendarConfig = {
      restRoot: 'https://site.test/wp-json/',
      nonce: 'n',
      locale: 'en_US',
      version: '1',
      capabilities: [],
      adminUrl: '',
      siteUrl: 'https://site.test',
      pluginUrl: '',
    };
    resetConfigForTests();
    const wrapper = mount(ProviderSetupDialog, {
      props: {
        modelValue: true,
        provider,
        redirectUri: 'https://site.test/oauth',
        attachmentAccess: true,
      },
      global,
      attachTo: document.body,
    });
    await flushPromises();
    expect(document.body.textContent).toContain('https://site.test/oauth');
    const links = [...document.body.querySelectorAll<HTMLAnchorElement>('.swc-guide__links a')];
    expect(links.length).toBeGreaterThan(0);
    expect(links.every((link) => link.protocol === 'https:' && link.rel.includes('noopener'))).toBe(
      true,
    );
    wrapper.unmount();
  },
);
