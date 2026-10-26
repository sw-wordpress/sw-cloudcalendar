import { describe, expect, it } from 'vitest';
import {
  formatBytes,
  formatDuration,
  formatPercent,
  formatRelative,
  parseServerDate,
  secondsBetween,
} from '../src/utils/format';

describe('formatBytes', () => {
  it('formats with locale-specific separators', () => {
    expect(formatBytes(0, 'en')).toBe('0 B');
    expect(formatBytes(1536, 'en')).toBe('1.5 KB');
    expect(formatBytes(1536, 'de')).toBe('1,5 KB');
    expect(formatBytes(734_003_200, 'en')).toBe('700 MB');
  });
  it('handles invalid input', () => {
    expect(formatBytes(null, 'en')).toBe('–');
    expect(formatBytes(-1, 'en')).toBe('–');
  });
});

describe('formatDuration', () => {
  it('uses m:ss and h:mm:ss', () => {
    expect(formatDuration(65)).toBe('1:05');
    expect(formatDuration(3725)).toBe('1:02:05');
    expect(formatDuration(null)).toBe('–');
  });
});

describe('formatPercent', () => {
  it('accepts fractions and percent values', () => {
    expect(formatPercent(0.38, 'en')).toBe('38%');
    expect(formatPercent(42.5, 'en', true)).toBe('43%');
  });
});

describe('formatRelative', () => {
  const now = new Date('2026-10-24T12:00:00Z');
  it('describes past and future', () => {
    expect(formatRelative('2026-10-24T10:00:00Z', 'en', now)).toBe('2 hours ago');
    expect(formatRelative('2026-10-25T12:00:00Z', 'en', now)).toBe('tomorrow');
    expect(formatRelative('2026-10-24T10:00:00Z', 'de', now)).toBe('vor 2 Stunden');
  });
  it('returns a dash for missing values', () => {
    expect(formatRelative(null, 'en', now)).toBe('–');
    expect(formatRelative('nonsense', 'en', now)).toBe('–');
  });
});

describe('parseServerDate', () => {
  it('reads MySQL datetimes of database rows as UTC', () => {
    expect(parseServerDate('2026-10-24 17:20:00')?.toISOString()).toBe('2026-10-24T17:20:00.000Z');
    expect(parseServerDate('2026-10-24T17:20:00Z')?.toISOString()).toBe('2026-10-24T17:20:00.000Z');
    expect(parseServerDate(1792862400)?.toISOString()).toBe('2026-10-24T17:20:00.000Z');
    expect(parseServerDate(null)).toBeNull();
    expect(parseServerDate('nope')).toBeNull();
  });
  it('computes run durations', () => {
    expect(secondsBetween('2026-10-24 17:20:00', '2026-10-24 17:21:05')).toBe(65);
    expect(secondsBetween('2026-10-24 17:20:00', null)).toBeNull();
  });
});
