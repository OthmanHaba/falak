<?php

namespace Falak\Secrets\Application;

use Falak\Kernel\Security\DecryptionFailed;
use Falak\Kernel\Security\KeyUnavailable;
use Falak\Kernel\Security\Sealer;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretVersion;

/**
 * Seals secret versions under the organization's data key, bound to (organization, secret, version): a
 * ciphertext copied to another version, secret or organization does not open.
 */
final class SecretCipher
{
    public function __construct(private readonly Sealer $sealer) {}

    public function seal(Secret $secret, int $version, #[\SensitiveParameter] string $plaintext): string
    {
        return $this->sealer->seal($plaintext, self::aad($secret->organization_id, $secret->id, $version), $secret->organization_id);
    }

    /**
     * @throws DecryptionFailed|KeyUnavailable
     */
    public function open(Secret $secret, SecretVersion $version): string
    {
        return $this->sealer->open($version->ciphertext, self::aad($secret->organization_id, $secret->id, $version->version), $secret->organization_id);
    }

    public static function aad(string $organizationId, string $secretId, int $version): string
    {
        return Sealer::aad('secret', $organizationId, $secretId, (string) $version);
    }
}
