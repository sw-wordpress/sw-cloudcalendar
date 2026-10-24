<?php

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Crypto;

use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\ErrorCode;

/**
 * Authenticated encryption of secrets (XChaCha20-Poly1305, random nonce, context bound as associated data).
 *
 * Key sources (SPECIFICATIONS.md §16): `SW_CALENDAR_ENCRYPTION_KEY` (preferred, wp-config.php or environment), the
 * optional `SW_CALENDAR_ENCRYPTION_KEY_PREVIOUS` for rotation, and a documented fallback derived from the WordPress
 * salts. The fallback only protects against database-only leaks; it does NOT protect against a compromise of the
 * server that also exposes wp-config.php. Diagnostics warn about it.
 *
 * Format: `swc1:<key fingerprint>:<base64(nonce|ciphertext)>`.
 */
final class SecretVault
{
    private const PREFIX = 'swc1';

    /** @var array<string, string> Fingerprint => raw 32 byte key; the first entry encrypts. */
    private array $keys = [];

    public readonly bool $usesFallback;

    public function __construct(?string $primaryKey, ?string $previousKey, string $saltMaterial)
    {
        foreach ([$primaryKey, $previousKey] as $material) {
            if ($material !== null && $material !== '') {
                $key = self::deriveKey($material, 'explicit');
                $this->keys[self::fingerprint($key)] = $key;
            }
        }
        $fallback = self::deriveKey($saltMaterial, 'wp-salts');
        $this->keys[self::fingerprint($fallback)] = $fallback;
        $this->usesFallback = $primaryKey === null || $primaryKey === '';
    }

    public function encrypt(string $plaintext, string $context): string
    {
        $fingerprint = array_key_first($this->keys);
        $key = $this->keys[$fingerprint];
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $key);
        return self::PREFIX . ':' . $fingerprint . ':' . base64_encode($nonce . $cipher);
    }

    /**
     * @throws CalendarException AUTH_REQUIRED if the ciphertext cannot be decrypted (unknown key, tampering).
     */
    public function decrypt(string $ciphertext, string $context): string
    {
        $parts = explode(':', $ciphertext, 3);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || !isset($this->keys[$parts[1]])) {
            throw new CalendarException(ErrorCode::AuthRequired, 'Stored credentials cannot be decrypted with the configured key.');
        }
        $raw = base64_decode($parts[2], true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($raw === false || strlen($raw) <= $nonceLength) {
            throw new CalendarException(ErrorCode::AuthRequired, 'Stored credentials are corrupt.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $nonceLength), $context, substr($raw, 0, $nonceLength), $this->keys[$parts[1]]);
        if ($plain === false) {
            throw new CalendarException(ErrorCode::AuthRequired, 'Stored credentials failed the integrity check.');
        }
        return $plain;
    }

    /** Whether the value was encrypted with an older key and should be re-encrypted. */
    public function needsRotation(string $ciphertext): bool
    {
        $parts = explode(':', $ciphertext, 3);
        return count($parts) === 3 && $parts[1] !== array_key_first($this->keys);
    }

    public function keyVersion(): int
    {
        return $this->usesFallback ? 0 : 1;
    }

    private static function deriveKey(string $material, string $purpose): string
    {
        $decoded = base64_decode($material, true);
        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES && $purpose === 'explicit') {
            return $decoded;
        }
        return sodium_crypto_generichash('sw-cloudcalendar|' . $purpose . '|' . $material, '', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    }

    private static function fingerprint(string $key): string
    {
        return substr(bin2hex(sodium_crypto_generichash('fingerprint|' . $key, '', 16)), 0, 12);
    }
}
