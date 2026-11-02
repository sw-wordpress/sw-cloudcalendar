import { describe, expect, it } from 'vitest';
import { connectionIsPrivate } from '../src/features/sources/sourceForm';
import { fromFilterForm, toFilterForm } from '../src/features/views/viewForm';

describe('local source presentation settings', () => {
  it('requires explicit publication for plugin calendars without OAuth', () => {
    expect(connectionIsPrivate({ provider: 'local', auth_mode: 'none' })).toBe(true);
    expect(connectionIsPrivate({ provider: 'ics', auth_mode: 'none' })).toBe(false);
  });
  it('preserves both calendar selections and shared presentation options', () => {
    const options = {
      color_titles: true,
      legend: true,
      columns: 2,
      day_limit: 3,
      navigation_group: 'mgwh',
      month: '2026-11',
    };
    expect(fromFilterForm(toFilterForm(options))).toEqual(options);
    expect(fromFilterForm(toFilterForm({ color_titles: false, legend: false }))).toEqual({
      color_titles: false,
      legend: false,
    });
    expect(fromFilterForm(toFilterForm(null))).toEqual({});
  });
});
