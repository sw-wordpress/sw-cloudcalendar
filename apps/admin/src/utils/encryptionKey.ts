/**
 * Generates a random 32 byte key (base64) for SW_CALENDAR_ENCRYPTION_KEY in the browser. The key is only shown to the
 * administrator for copying into wp-config.php; it is never sent to the server.
 */
export function generateEncryptionKey(
  random: (bytes: Uint8Array) => Uint8Array = (b) => crypto.getRandomValues(b),
): string {
  const bytes = random(new Uint8Array(32));
  let binary = '';
  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte);
  });
  return btoa(binary);
}

export function encryptionKeySnippet(key: string): string {
  return `define( 'SW_CALENDAR_ENCRYPTION_KEY', '${key}' );`;
}
