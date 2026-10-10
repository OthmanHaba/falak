<?php

namespace Falak\Kernel\Security;

use InvalidArgumentException;

/**
 * AES-256-GCM with a random 96-bit nonce. Output: nonce (12) || ciphertext || tag (16), raw bytes.
 * The AAD is authenticated, not encrypted: a ciphertext opened with any other AAD fails.
 */
final class Aead
{
    public const KEY_BYTES = 32;

    private const CIPHER = 'aes-256-gcm';

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    public static function seal(#[\SensitiveParameter] string $key, #[\SensitiveParameter] string $plaintext, string $aad): string
    {
        self::assertKey($key);

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG_BYTES);

        if ($ciphertext === false) {
            throw new DecryptionFailed('AES-256-GCM encryption failed.');
        }

        return $nonce.$ciphertext.$tag;
    }

    /**
     * @throws DecryptionFailed when the data was tampered with, or the key or AAD differ
     */
    public static function open(#[\SensitiveParameter] string $key, string $sealed, string $aad): string
    {
        self::assertKey($key);

        if (strlen($sealed) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new DecryptionFailed('The ciphertext is truncated.');
        }

        $nonce = substr($sealed, 0, self::NONCE_BYTES);
        $tag = substr($sealed, -self::TAG_BYTES);
        $ciphertext = substr($sealed, self::NONCE_BYTES, -self::TAG_BYTES);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);

        if ($plaintext === false) {
            throw new DecryptionFailed('The ciphertext could not be authenticated (wrong key or context, or tampered with).');
        }

        return $plaintext;
    }

    private static function assertKey(string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new InvalidArgumentException('AES-256-GCM needs a 32-byte key.');
        }
    }
}
