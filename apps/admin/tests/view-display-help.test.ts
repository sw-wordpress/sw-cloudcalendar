import { flushPromises, mount } from '@vue/test-utils';
import { QBtn, QTooltip, Quasar } from 'quasar';
import { afterEach, describe, expect, it } from 'vitest';
import ViewDisplayHelp from '../src/features/views/ViewDisplayHelp.vue';
import { createAppI18n } from '../src/i18n';

afterEach(() => {
  document.body.innerHTML = '';
});

describe('view display help', () => {
  it.each(['overview', 'mode', 'allowedModes', 'templates', 'modeTemplates'] as const)(
    'opens accessible help from the %s button without changing the form',
    async (topic) => {
      const wrapper = mount(ViewDisplayHelp, {
        props: { topic, viewId: 5 },
        global: { plugins: [Quasar, createAppI18n('de_DE')] as never[] },
        attachTo: document.body,
      });
      const trigger = wrapper.get('button');
      expect(trigger.attributes('aria-label')).toContain('Hilfe');
      expect(trigger.attributes('aria-haspopup')).toBe('dialog');
      expect(trigger.attributes('aria-expanded')).toBe('false');
      const tooltip = wrapper.getComponent(QTooltip);
      expect(tooltip.props('maxWidth')).toBe('320px');
      await trigger.trigger('pointerenter', { pointerType: 'mouse' });
      await new Promise((resolve) => setTimeout(resolve, 20));
      await flushPromises();
      expect(document.body.querySelector('[role="tooltip"]')?.textContent).toContain('Klicken');
      await trigger.trigger('click');
      await flushPromises();
      const dialog = document.body.querySelector('[role="dialog"]')!;
      expect(dialog).not.toBeNull();
      expect(trigger.attributes('aria-expanded')).toBe('true');
      const title = document.getElementById(dialog.getAttribute('aria-labelledby')!);
      expect(title?.textContent).toBe('So entsteht die Kalenderansicht');
      expect(dialog.textContent).toContain('Kalenderraster');
      expect(dialog.textContent).toContain('Ein für die ganze Ansicht gewähltes Template');
      expect(dialog.textContent).toContain('Ohne eigene Wahl gilt das oben eingestellte Template');
      expect(dialog.textContent).toContain('Die Freigaben allein erzeugen keine sichtbare Auswahl');
      expect(dialog.querySelector('code')?.textContent).toBe(
        '[sw_calendar id="5" view="cards" template="7"]',
      );
      wrapper
        .findAllComponents(QBtn)
        .find((c) => c.props('icon') === 'r_close')!
        .vm.$emit('click');
      await flushPromises();
      expect(trigger.attributes('aria-expanded')).toBe('false');
      wrapper.unmount();
    },
  );

  it('uses an example id for an unsaved view and supports the English help', async () => {
    const wrapper = mount(ViewDisplayHelp, {
      global: { plugins: [Quasar, createAppI18n('en_US')] as never[] },
      attachTo: document.body,
    });
    await wrapper.get('button').trigger('click');
    await flushPromises();
    expect(document.body.textContent).toContain('How the calendar view is built');
    expect(document.body.textContent).toContain('id="12"');
    wrapper
      .findAllComponents(QBtn)
      .find((c) => c.props('label') === 'Close')!
      .vm.$emit('click');
    await flushPromises();
    expect(wrapper.get('button').attributes('aria-expanded')).toBe('false');
    wrapper.unmount();
  });
});
