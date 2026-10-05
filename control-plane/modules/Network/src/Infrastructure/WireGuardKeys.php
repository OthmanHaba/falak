<?php

namespace Falak\Network\Infrastructure;

use InvalidArgumentException;

/**
 * X25519 key pairs in WireGuard's format (base64 of 32 raw bytes, like `wg genkey | wg pubkey`), via libsodium.
 */
final class WireGuardKeys
{
    /**
     * @return array{private: string, public: string} base64
     */
    public function generate(): array
    {
        $private = random_bytes(SODIUM_CRYPTO_SCALARMULT_SCALARBYTES);

        // Curve25519 clamping, as `wg genkey` does.
        $private[0] = chr(ord($private[0]) & 248);
        $private[31] = chr((ord($private[31]) & 127) | 64);

        $public = sodium_crypto_scalarmult_base($private);
        $keys = ['private' => base64_encode($private), 'public' => base64_encode($public)];

        sodium_memzero($private);

        return $keys;
    }

    public function publicKeyFor(string $privateKeyBase64): string
    {
        $private = base64_decode($privateKeyBase64, true);

        if ($private === false || strlen($private) !== SODIUM_CRYPTO_SCALARMULT_SCALARBYTES) {
            throw new InvalidArgumentException('A WireGuard private key is 32 bytes, base64 encoded.');
        }

        return base64_encode(sodium_crypto_scalarmult_base($private));
    }

    public static function isPublicKey(string $key): bool
    {
        return preg_match('#^[A-Za-z0-9+/]{42,43}=$#', $key) === 1 && strlen((string) base64_decode($key, true)) === 32;
    }
}
