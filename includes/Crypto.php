<?php
/**
 * Crypto — symmetric encryption for sensitive fields stored at rest (e.g. the
 * invoice note). AES-256-CBC with a random IV per value; the IV is prepended to
 * the ciphertext and the whole thing base64-encoded. Values are tagged with an
 * "enc:v1:" prefix so encrypt()/decrypt() are safe to call repeatedly and on
 * legacy plaintext (decrypt() returns plaintext unchanged if it isn't tagged).
 *
 * The key is derived from APP_KEY in .env, falling back to the FIRS API secret
 * so there is always a stable per-deployment key.
 */
require_once __DIR__ . '/../config/env.php';

class Crypto
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-cbc';

    private static function key(): string
    {
        $material = (string) (env('APP_KEY', '') ?: env('FIRS_API_SECRET', 'fallback-dev-key'));
        return hash('sha256', $material, true); // 32-byte key
    }

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }
        if (strpos($plain, self::PREFIX) === 0) {
            return $plain; // already encrypted
        }
        $iv  = random_bytes(16);
        $ct  = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
        return self::PREFIX . base64_encode($iv . $ct);
    }

    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || strpos($value, self::PREFIX) !== 0) {
            return $value; // plaintext / legacy — return as-is
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= 16) {
            return null;
        }
        $iv = substr($raw, 0, 16);
        $ct = substr($raw, 16);
        $pt = openssl_decrypt($ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
        return $pt === false ? null : $pt;
    }
}
