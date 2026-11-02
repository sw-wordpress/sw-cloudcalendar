import { flushPromises, mount } from '@vue/test-utils';
import { QInput, QSelect, QToggle, Quasar } from 'quasar';
import { describe, expect, it } from 'vitest';
import { createAppI18n } from '../src/i18n';
import BlockFields from '../src/features/templates/BlockFields.vue';
import BlockList from '../src/features/templates/BlockList.vue';
import { createBlock } from '../src/features/templates/visualBuilder';
import type { BlockType } from '../src/features/templates/visualBuilder';

const global = { plugins: [Quasar, createAppI18n('en_US')] };

describe('visual builder editing', () => {
  it.each<BlockType>([
    'title',
    'datetime',
    'description',
    'location',
    'link',
    'attachments',
    'container',
    'custom_html',
    'event_list',
  ])('writes %s fields and emits changes', async (type) => {
    const block = createBlock(type, type === 'event_list' ? 'container' : 'event');
    const wrapper = mount(BlockFields, {
      props: { block, kind: type === 'event_list' ? 'container' : 'event' },
      global,
    });
    const fields = wrapper.findAllComponents(QInput);
    const css = fields.at(-1)!;
    css.vm.$emit('update:modelValue', 'custom');
    await flushPromises();
    expect(block.cssClass).toBe('custom');
    const select = wrapper.findComponent(QSelect);
    if (select.exists()) {
      const options = select.props('options') as { value: string | number }[];
      select.vm.$emit('update:modelValue', options.at(-1)!.value);
    }
    for (const field of fields.slice(0, -1)) field.vm.$emit('update:modelValue', 'Changed');
    for (const toggle of wrapper.findAllComponents(QToggle))
      toggle.vm.$emit('update:modelValue', true);
    await flushPromises();
    expect(wrapper.emitted('change')!.length).toBeGreaterThan(0);
    if ('html' in block) expect(block.html).toBe('Changed');
    if ('label' in block) expect(block.label).toBe('Changed');
    if ('heading' in block) expect(block.heading).toBe('Changed');
    wrapper.unmount();
  });
  it('reorders, hides and removes blocks without losing their settings', async () => {
    const first = createBlock('title', 'event');
    const second = createBlock('location', 'event');
    const wrapper = mount(BlockList, {
      props: {
        modelValue: [first, second],
        kind: 'event',
        'onUpdate:modelValue': (value) => {
          void wrapper.setProps({ modelValue: value });
        },
      },
      global,
      attachTo: document.body,
    });
    await wrapper.get(`[data-move="${first.id}:1"]`).trigger('click');
    await flushPromises();
    expect(wrapper.props('modelValue').map((block) => block.id)).toEqual([second.id, first.id]);
    const visible = wrapper.findAll('[aria-pressed]')[0]!;
    await visible.trigger('click');
    expect(second.visible).toBe(false);
    await wrapper.get(`[data-block-head="${second.id}"]`).trigger('click');
    expect(wrapper.findComponent(BlockFields).props('block')).toEqual(second);
    const remove = wrapper
      .findAll('button')
      .find((button) => button.attributes('aria-label')?.startsWith('Remove'))!;
    await remove.trigger('click');
    await flushPromises();
    expect(wrapper.props('modelValue')).toHaveLength(1);
    expect(wrapper.props('modelValue')[0]!.id).toBe(first.id);
    wrapper.unmount();
  });
  it('adds blocks from the menu and prevents recursively nested containers', async () => {
    const wrapper = mount(BlockList, {
      props: {
        modelValue: [],
        kind: 'event',
        nested: true,
        'onUpdate:modelValue': (value) => {
          void wrapper.setProps({ modelValue: value });
        },
      },
      global,
      attachTo: document.body,
    });
    await wrapper.get('.swc-bl__add').trigger('click');
    await flushPromises();
    const menu = document.body.querySelector('.q-menu')!;
    expect(menu.textContent).not.toContain('Container');
    (menu.querySelector('.q-item') as HTMLElement).click();
    await flushPromises();
    expect(wrapper.props('modelValue')).toHaveLength(1);
    expect(wrapper.findComponent(BlockFields).exists()).toBe(true);
    wrapper.unmount();
  });
});
