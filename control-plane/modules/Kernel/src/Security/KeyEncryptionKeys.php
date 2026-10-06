<?php

namespace Falak\Kernel\Security;

use Falak\Kernel\Security\Kek\AwsKmsKek;
use Falak\Kernel\Security\Kek\LocalKek;
use Falak\Kernel\Security\Kek\VaultTransitKek;
use Illuminate\Contracts\Config\Repository;

/**
 * Builds the configured KEK (FALAK_KEK_PROVIDER), and finds the KEK that wrapped a given data key: the
 * current one, or after a rotation the previous local file / another KMS or Vault key.
 */
class KeyEncryptionKeys
{
    use RedactsKeyMaterial;

    private ?KeyEncryptionKey $current = null;

    /** @var array<string, KeyEncryptionKey> */
    private array $others = [];

    public function __construct(private readonly Repository $config) {}

    public function current(): KeyEncryptionKey
    {
        return $this->current ??= $this->build((string) $this->config->get('kernel.keys.provider', LocalKek::PROVIDER));
    }

    /**
     * The KEK recorded on a data key.
     *
     * @throws KeyUnavailable
     */
    public function for(string $provider, string $kekId): KeyEncryptionKey
    {
        $current = $this->current();

        if ($current->provider() === $provider && $current->id() === $kekId) {
            return $current;
        }

        return $this->others["{$provider}|{$kekId}"] ??= match ($provider) {
            LocalKek::PROVIDER => $this->previousLocal($kekId),
            AwsKmsKek::PROVIDER => $this->awsKms($kekId),
            // Vault ids are "<mount>/<key>".
            VaultTransitKek::PROVIDER => str_contains($kekId, '/')
                ? $this->vault(substr($kekId, strrpos($kekId, '/') + 1), substr($kekId, 0, strrpos($kekId, '/')))
                : $this->vault($kekId),
            default => throw new KeyUnavailable("Unknown KEK provider '{$provider}'."),
        };
    }

    private function build(string $provider): KeyEncryptionKey
    {
        return match ($provider) {
            LocalKek::PROVIDER => new LocalKek($this->path((string) $this->config->get('kernel.keys.local.path'))),
            AwsKmsKek::PROVIDER => $this->awsKms((string) $this->config->get('kernel.keys.aws_kms.key_id')),
            VaultTransitKek::PROVIDER => $this->vault((string) $this->config->get('kernel.keys.vault.key')),
            default => throw new KeyUnavailable("Unknown FALAK_KEK_PROVIDER '{$provider}' (local, aws-kms or vault-transit)."),
        };
    }

    private function previousLocal(string $kekId): LocalKek
    {
        $path = (string) $this->config->get('kernel.keys.local.previous_path', '');

        if ($path !== '' && is_file($this->path($path))) {
            $previous = new LocalKek($this->path($path));

            if ($previous->id() === $kekId) {
                return $previous;
            }
        }

        throw new KeyUnavailable("A data key is wrapped by local KEK {$kekId}, which is neither FALAK_KEK_PATH nor FALAK_KEK_PREVIOUS_PATH. Put that KEK back (emergency kit) to read the secrets it protects.");
    }

    private function awsKms(string $keyId): AwsKmsKek
    {
        $c = (array) $this->config->get('kernel.keys.aws_kms');

        return new AwsKmsKek(
            $keyId,
            (string) ($c['region'] ?? ''),
            (string) ($c['access_key_id'] ?? ''),
            (string) ($c['secret_access_key'] ?? ''),
            ($c['session_token'] ?? null) ?: null,
            ($c['endpoint'] ?? null) ?: null,
        );
    }

    private function vault(string $key, ?string $mount = null): VaultTransitKek
    {
        $c = (array) $this->config->get('kernel.keys.vault');

        return new VaultTransitKek(
            (string) ($c['address'] ?? ''),
            (string) ($c['token'] ?? ''),
            $key,
            $mount ?? (string) ($c['mount'] ?? 'transit'),
            ($c['namespace'] ?? null) ?: null,
        );
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
