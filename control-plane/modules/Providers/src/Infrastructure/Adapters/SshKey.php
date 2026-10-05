<?php

namespace Falak\Providers\Infrastructure\Adapters;

use Falak\Providers\Contracts\Exceptions\ProviderException;

/**
 * OpenSSH public key helpers for de-duplicating uploaded keys.
 */
final class SshKey
{
    /**
     * "type base64" without the comment, used to compare keys.
     */
    public static function normalize(string $publicKey): string
    {
        $parts = preg_split('/\s+/', trim($publicKey)) ?: [];

        if (count($parts) < 2 || base64_decode($parts[1], true) === false) {
            throw new ProviderException('Invalid OpenSSH public key.');
        }

        return $parts[0].' '.$parts[1];
    }

    /**
     * Legacy MD5 fingerprint "aa:bb:..." (used by Hetzner and DigitalOcean).
     */
    public static function md5Fingerprint(string $publicKey): string
    {
        $blob = base64_decode(explode(' ', self::normalize($publicKey))[1], true);

        return implode(':', str_split(md5((string) $blob), 2));
    }

    public static function equals(string $a, string $b): bool
    {
        try {
            return self::normalize($a) === self::normalize($b);
        } catch (ProviderException) {
            return false;
        }
    }
}
