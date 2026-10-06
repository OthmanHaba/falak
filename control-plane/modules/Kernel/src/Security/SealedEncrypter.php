<?php

namespace Falak\Kernel\Security;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Encryption\StringEncrypter;

/**
 * Laravel's Encrypter contract on top of the Sealer, for packages that take an encrypter instead of a cast
 * (Fortify's two-factor secrets: Fortify::encryptUsing). Everything it seals shares one AAD.
 */
final class SealedEncrypter implements Encrypter, StringEncrypter
{
    public function __construct(private readonly string $aad) {}

    public function encrypt(#[\SensitiveParameter] $value, $serialize = true): string
    {
        return $this->sealer()->seal($serialize ? serialize($value) : (string) $value, $this->aad);
    }

    public function decrypt($payload, $unserialize = true): mixed
    {
        try {
            $plaintext = $this->sealer()->open((string) $payload, $this->aad);
        } catch (DecryptionFailed $e) {
            throw new DecryptException($e->getMessage(), previous: $e);
        }

        return $unserialize ? unserialize($plaintext, ['allowed_classes' => false]) : $plaintext;
    }

    public function encryptString(#[\SensitiveParameter] $value): string
    {
        return $this->encrypt($value, false);
    }

    public function decryptString($payload): string
    {
        return (string) $this->decrypt($payload, false);
    }

    /** No raw key to hand out: the data keys never leave the key ring. */
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

    private function sealer(): Sealer
    {
        // Resolved per call: the encrypter outlives requests (a static in Fortify) under the FrankenPHP worker.
        return app(Sealer::class);
    }
}
