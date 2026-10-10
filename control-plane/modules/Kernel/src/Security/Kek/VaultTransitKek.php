<?php

namespace Falak\Kernel\Security\Kek;

use Falak\Kernel\Security\DecryptionFailed;
use Falak\Kernel\Security\KeyEncryptionKey;
use Falak\Kernel\Security\KeyUnavailable;
use Falak\Kernel\Security\RedactsKeyMaterial;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * KEK held by HashiCorp Vault or OpenBao (transit secrets engine): data keys are wrapped with
 * transit/encrypt and transit/decrypt, so the KEK never leaves Vault. Rotating the transit key in Vault
 * keeps old ciphertexts readable; `falak:keys:rotate-kek --all` re-wraps them under the newest version.
 *
 * The context is not sent: transit only takes one for derived keys. Each wrapped key is still bound to
 * its record by the data-key id in every value's envelope.
 *
 * @see https://developer.hashicorp.com/vault/api-docs/secret/transit
 */
final class VaultTransitKek implements KeyEncryptionKey
{
    use RedactsKeyMaterial;

    public const PROVIDER = 'vault-transit';

    private const PREFIX = 'vt1:';

    public function __construct(
        private readonly string $address,
        #[\SensitiveParameter] private readonly string $token,
        private readonly string $key,
        private readonly string $mount = 'transit',
        private readonly ?string $namespace = null,
    ) {
        if ($address === '' || $token === '' || $key === '') {
            throw new KeyUnavailable('The vault-transit KEK provider needs FALAK_KEK_VAULT_ADDR, FALAK_KEK_VAULT_TOKEN and FALAK_KEK_VAULT_KEY.');
        }
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function id(): string
    {
        return "{$this->mount}/{$this->key}";
    }

    public function wrap(#[\SensitiveParameter] string $dataKey, array $context): string
    {
        $ciphertext = $this->call('encrypt', ['plaintext' => base64_encode($dataKey)])['ciphertext'] ?? null;

        if (! is_string($ciphertext) || ! str_starts_with($ciphertext, 'vault:')) {
            throw new KeyUnavailable('Vault transit/encrypt returned no ciphertext.');
        }

        return self::PREFIX.$ciphertext;
    }

    public function unwrap(string $wrapped, array $context): string
    {
        if (! str_starts_with($wrapped, self::PREFIX)) {
            throw new DecryptionFailed('The data key was not wrapped by Vault transit.');
        }

        $plaintext = base64_decode((string) ($this->call('decrypt', ['ciphertext' => substr($wrapped, strlen(self::PREFIX))])['plaintext'] ?? ''), true);

        if ($plaintext === false || $plaintext === '') {
            throw new KeyUnavailable('Vault transit/decrypt returned no plaintext.');
        }

        return $plaintext;
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed> the response's `data`
     */
    private function call(string $operation, array $payload): array
    {
        $url = rtrim($this->address, '/').'/v1/'.trim($this->mount, '/')."/{$operation}/".rawurlencode($this->key);

        try {
            $response = $this->client()->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new KeyUnavailable("Vault ({$this->address}) is unreachable: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful()) {
            $errors = implode('; ', array_map('strval', (array) ($response->json('errors') ?? [])));

            // Vault answers 400 for a ciphertext it can't decrypt (wrong key, or tampered with).
            if ($operation === 'decrypt' && $response->status() === 400) {
                throw new DecryptionFailed("Vault transit/decrypt refused the wrapped data key: {$errors}");
            }

            throw new KeyUnavailable(trim("Vault transit/{$operation} failed (HTTP {$response->status()}) {$errors}"));
        }

        return (array) $response->json('data');
    }

    private function client(): PendingRequest
    {
        $headers = ['X-Vault-Token' => $this->token];

        if ($this->namespace !== null && $this->namespace !== '') {
            $headers['X-Vault-Namespace'] = $this->namespace;
        }

        return Http::timeout(15)->connectTimeout(5)->acceptJson()->asJson()->withHeaders($headers)
            ->retry(2, 200, fn ($e) => AwsKmsKek::transient($e), throw: false);
    }
}
