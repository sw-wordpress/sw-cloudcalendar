import { describe, expect, it } from 'vitest';
import de from '@/i18n/de';
import en from '@/i18n/en';
import { resolveUiLocale } from '@/config';

type Tree = { [key: string]: string | Tree };

function flatten(tree: Tree, prefix = ''): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [key, value] of Object.entries(tree)) {
    if (typeof value === 'string') out[prefix + key] = value;
    else Object.assign(out, flatten(value, `${prefix}${key}.`));
  }
  return out;
}

const placeholders = (text: string): string[] => (text.match(/\{[a-zA-Z0-9_]+\}/g) ?? []).sort();

describe('translation catalogs', () => {
  const reference = flatten(de as unknown as Tree);
  const english = flatten(en as unknown as Tree);

  it('en has exactly the keys of de, with the same placeholders and plural forms', () => {
    expect(Object.keys(english).sort()).toEqual(Object.keys(reference).sort());
    for (const [key, text] of Object.entries(english)) {
      expect(text.trim(), `en:${key} is empty`).not.toBe('');
      expect(placeholders(text), `en:${key} placeholders`).toEqual(
        placeholders(reference[key] ?? ''),
      );
      expect(text.split('|').length, `en:${key} plural forms`).toBe(
        (reference[key] ?? '').split('|').length,
      );
    }
  });

  it('never contains an unescaped @ (vue-i18n linked message syntax)', () => {
    for (const [key, text] of Object.entries({ ...reference, ...english })) {
      expect(text.replace(/\{'@'\}/g, ''), key).not.toContain('@');
    }
  });

  it('uses German only for de* locales', () => {
    expect(resolveUiLocale('de_DE')).toBe('de');
    expect(resolveUiLocale('de_CH_informal')).toBe('de');
    expect(resolveUiLocale('fr_FR')).toBe('en');
    expect(resolveUiLocale('en_US')).toBe('en');
    expect(resolveUiLocale('')).toBe('en');
  });
});
