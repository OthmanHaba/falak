<?php

namespace Falak\Identity\Infrastructure;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Encryption\StringEncrypter;

/**
 * Fortify's encrypter for the two-factor secret and recovery codes (Fortify::encryptUsing). It does not encrypt:
 * those two User attributes have Sealed casts, which seal them under the key hierarchy bound to the user's row
 * (an encrypter can't know the row). Fortify only ever passes them through this, so it just (un)serializes, the
 * format Fortify's own encrypter used.
 */
final class CastSealedTwoFactorEncrypter implements Encrypter, StringEncrypter
{
    public function encrypt(#[\SensitiveParameter] $value, $serialize = true): string
    {
        return $serialize ? serialize($value) : (string) $value;
    }

    public function decrypt($payload, $unserialize = true): mixed
    {
        return $unserialize ? unserialize((string) $payload, ['allowed_classes' => false]) : (string) $payload;
    }

    public function encryptString(#[\SensitiveParameter] $value): string
    {
        return $this->encrypt($value, false);
    }

    public function decryptString($payload): string
    {
        return (string) $this->decrypt($payload, false);
    }

    public function getKey(): string
    {
        return '';
    }

    /** @return list<string> */
    public function getAllKeys(): array
    {
        return [];
    }

    /** @return list<string> */
    public function getPreviousKeys(): array
    {
        return [];
    }
}
