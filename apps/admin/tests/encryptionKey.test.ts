import { describe, expect, it } from 'vitest';
import { encryptionKeySnippet, generateEncryptionKey } from '@/utils/encryptionKey';

describe('encryption key snippet', () => {
  it('creates a 32 byte base64 key', () => {
    const key = generateEncryptionKey();
    expect(atob(key)).toHaveLength(32);
    expect(key).toMatch(/^[A-Za-z0-9+/]{43}=$/);
  });

  it('creates different keys and a ready to paste define()', () => {
    expect(generateEncryptionKey()).not.toBe(generateEncryptionKey());
    const fixed = generateEncryptionKey((b) => b.fill(255));
    expect(encryptionKeySnippet(fixed)).toBe(`define( 'SW_CALENDAR_ENCRYPTION_KEY', '${fixed}' );`);
  });
});
