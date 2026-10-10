<?php

namespace Falak\Secrets\Application\Providers;

use Falak\Kernel\Security\DataKey;
use Falak\Kernel\Security\KeyRing;
use Falak\Kernel\Security\KeyUnavailable;

/**
 * How the watch tells a value changed without storing it: an HMAC-SHA256 under a key derived (HKDF) from the
 * organization's data key, stored as "<data key id>:<hex>". A plain hash would let anyone with the database
 * confirm a guessed value; this needs the key. After a data key rotation the old key still verifies the stored
 * fingerprint, so a rotation is not mistaken for a change.
 */
final class ValueFingerprint
{
    public function __construct(private readonly KeyRing $keys) {}

    public function of(string $organizationId, #[\SensitiveParameter] string $value): string
    {
        $key = $this->keys->organization($organizationId);

        return $key->id.':'.hash_hmac('sha256', $value, $this->hmacKey($key->id, $organizationId));
    }

    public function matches(string $organizationId, ?string $fingerprint, #[\SensitiveParameter] string $value): bool
    {
        [$keyId, $mac] = array_pad(explode(':', (string) $fingerprint, 2), 2, '');
        $key = $keyId !== '' ? DataKey::query()->find($keyId) : null;

        if ($key === null || $key->purpose !== DataKey::organization($organizationId)) {
            return false;
        }

        try {
            return hash_equals($mac, hash_hmac('sha256', $value, $this->hmacKey($keyId, $organizationId)));
        } catch (KeyUnavailable) {
            return false;
        }
    }

    private function hmacKey(string $keyId, string $organizationId): string
    {
        return hash_hkdf('sha256', $this->keys->material($keyId), 32, 'falak:secrets:linked-value:'.$organizationId);
    }
}
