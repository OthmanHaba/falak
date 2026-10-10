<?php

namespace Falak\Kernel\Security;

/**
 * Seals values under a data key (AES-256-GCM) into a versioned envelope:
 *
 *   fk1:<data key id>:<base64url(nonce || ciphertext || tag)>
 *
 * The AAD is "fk1:<data key id>:" followed by the caller's AAD, which binds the value to where it lives
 * (model casts: "<table>.<column>:<primary key>"; organization secrets: Sealer::aad('secret', org, id, version)),
 * so a value copied to another column, row or organization fails to open.
 */
class Sealer
{
    use RedactsKeyMaterial;

    public const PREFIX = 'fk1:';

    public function __construct(private readonly KeyRing $keys) {}

    /**
     * Seal under the platform data key, or the organization's when one is given.
     */
    public function seal(#[\SensitiveParameter] string $plaintext, string $aad, ?string $organizationId = null): string
    {
        $key = $organizationId === null ? $this->keys->platform() : $this->keys->organization($organizationId);

        return $this->sealWith($key, $plaintext, $aad);
    }

    public function sealWith(DataKey $key, #[\SensitiveParameter] string $plaintext, string $aad): string
    {
        $sealed = Aead::seal($this->keys->material($key), $plaintext, self::PREFIX.$key->id.':'.$aad);

        return self::PREFIX.$key->id.':'.rtrim(strtr(base64_encode($sealed), '+/', '-_'), '=');
    }

    /**
     * @param  string|null  $organizationId  when given, the value must be sealed under that organization's key
     *
     * @throws DecryptionFailed when the value was tampered with, belongs elsewhere (AAD), or its key is gone
     * @throws KeyUnavailable when the KEK can't be used
     */
    public function open(string $envelope, string $aad, ?string $organizationId = null): string
    {
        [$keyId, $sealed] = self::parse($envelope);

        if ($organizationId !== null) {
            $key = DataKey::query()->find($keyId);

            if ($key === null || $key->purpose !== DataKey::organization($organizationId)) {
                throw new DecryptionFailed('The value was not sealed under this organization\'s data key.');
            }
        }

        return Aead::open($this->keys->material($keyId), $sealed, self::PREFIX.$keyId.':'.$aad);
    }

    public static function isSealed(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /** The id of the data key a value is sealed under. */
    public static function keyId(string $envelope): string
    {
        return self::parse($envelope)[0];
    }

    /**
     * An unambiguous AAD from several parts (each length-prefixed, so ("ab", "c") never equals ("a", "bc")).
     */
    public static function aad(string ...$parts): string
    {
        return implode('', array_map(fn (string $part) => strlen($part).':'.$part.';', $parts));
    }

    /**
     * @return array{0: string, 1: string} data key id, raw sealed bytes
     */
    private static function parse(string $envelope): array
    {
        if (! self::isSealed($envelope)) {
            throw new DecryptionFailed('The value is not a sealed envelope (fk1).');
        }

        $parts = explode(':', substr($envelope, strlen(self::PREFIX)), 2);

        if (count($parts) !== 2 || $parts[0] === '' || ! preg_match('/^[0-9a-z]{26}$/', $parts[0])) {
            throw new DecryptionFailed('The sealed envelope is malformed.');
        }

        $raw = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if ($raw === false) {
            throw new DecryptionFailed('The sealed envelope is not valid base64.');
        }

        return [$parts[0], $raw];
    }
}
