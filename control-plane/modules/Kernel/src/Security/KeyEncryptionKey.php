<?php

namespace Falak\Kernel\Security;

/**
 * The root of the key hierarchy. It only wraps and unwraps data keys; values are never encrypted with it
 * directly. It is not APP_KEY: a stolen .env plus database dump reveals no secrets without it.
 */
interface KeyEncryptionKey
{
    /** 'local' | 'aws-kms' | 'vault-transit', stored with every data key it wraps. */
    public function provider(): string;

    /** Identifies this KEK (fingerprint, KMS key id or Vault key name); stored with every data key it wraps. */
    public function id(): string;

    /**
     * @param  array<string, string>  $context  bound to the wrapped key where the provider supports it
     *
     * @throws KeyUnavailable
     */
    public function wrap(#[\SensitiveParameter] string $dataKey, array $context): string;

    /**
     * @param  array<string, string>  $context  the context given to wrap()
     *
     * @throws KeyUnavailable|DecryptionFailed
     */
    public function unwrap(string $wrapped, array $context): string;
}
