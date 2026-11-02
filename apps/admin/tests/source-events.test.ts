import { flushPromises, mount } from '@vue/test-utils';
import { QBtn, Quasar } from 'quasar';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SourceEvents from '../src/features/sources/SourceEvents.vue';
import { createAppI18n } from '../src/i18n';

const { events } = vi.hoisted(() => ({ events: vi.fn() }));
vi.mock('../src/api', () => ({ useApi: () => ({ sources: { events } }) }));

const data = {
  items: [
    {
      title: '<script>unsafe</script>',
      start: '2026-11-03T09:00:00Z',
      end: '2026-11-03T10:00:00Z',
      all_day: false,
      location: 'Hall',
    },
    {
      title: 'Holiday',
      start: '2026-11-04T00:00:00Z',
      end: '2026-11-05T00:00:00Z',
      all_day: true,
      location: '',
    },
  ],
  pages: 2,
  total: 21,
};
const global = { plugins: [Quasar, createAppI18n('de_DE')] as never[] };
beforeEach(() => {
  events.mockReset().mockResolvedValue(data);
});

describe('source event preview', () => {
  it('renders escaped titles, timed and floating all-day dates and paginates', async () => {
    const wrapper = mount(SourceEvents, { props: { sourceId: 3 }, global });
    await flushPromises();
    expect(events).toHaveBeenCalledWith(3, expect.objectContaining({ page: 1, per_page: 20 }));
    expect(wrapper.text()).toContain('<script>unsafe</script>');
    expect(wrapper.find('script').exists()).toBe(false);
    expect(wrapper.text()).toContain('Hall');
    const cells = wrapper.findAll('tbody tr').at(1)!.findAll('td');
    expect(cells[0]!.text()).toContain('Ganztägig');
    expect(cells[1]!.text()).toBe('4.11.2026');
    expect(cells[2]!.text()).toBe('4.11.2026');
    wrapper
      .findAllComponents(QBtn)
      .find((c) => c.props('icon') === 'r_chevron_right')!
      .vm.$emit('click');
    await flushPromises();
    expect(events).toHaveBeenLastCalledWith(3, expect.objectContaining({ page: 2 }));
    await wrapper.get('input[type="month"]').setValue('2026-12');
    await flushPromises();
    expect(events).toHaveBeenLastCalledWith(3, { month: '2026-12', page: 1, per_page: 20 });
    wrapper.unmount();
  });

  it('supports empty months, refresh, errors and retry without querying invalid months', async () => {
    events.mockResolvedValueOnce({ items: [], total: 0, pages: 1 });
    const wrapper = mount(SourceEvents, { props: { sourceId: 3 }, global });
    await flushPromises();
    expect(wrapper.text()).toContain('Keine Termine in diesem Monat');
    events.mockRejectedValueOnce(new Error('Adapter unavailable'));
    await wrapper
      .findAllComponents(QBtn)
      .find((c) => c.props('icon') === 'r_refresh')!
      .trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Adapter unavailable');
    // DataState exposes a retry button.
    const retry = wrapper
      .findAllComponents(QBtn)
      .find((c) => c.props('icon') === 'r_refresh' && c.props('label') !== 'Aktualisieren');
    if (retry) await retry.trigger('click');
    else
      await wrapper
        .findAllComponents(QBtn)
        .find((c) => c.props('icon') === 'r_refresh')!
        .trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Holiday');
    const count = events.mock.calls.length;
    await wrapper.get('input[type="month"]').setValue('');
    await flushPromises();
    expect(events).toHaveBeenCalledTimes(count);
    wrapper.unmount();
  });
});
