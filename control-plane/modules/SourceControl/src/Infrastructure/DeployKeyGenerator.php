<?php

namespace Falak\SourceControl\Infrastructure;

use phpseclib3\Crypt\EC;

/**
 * ed25519 deploy keys in OpenSSH formats.
 */
class DeployKeyGenerator
{
    /**
     * @return array{public_key: string, private_key: string, fingerprint: string}
     */
    public function generate(string $comment): array
    {
        $key = EC::createKey('Ed25519');
        $comment = preg_replace('/[^A-Za-z0-9@._:-]+/', '-', $comment) ?: 'falak';
        $public = trim($key->getPublicKey()->toString('OpenSSH', ['comment' => $comment]));

        return [
            'public_key' => $public,
            'private_key' => $key->toString('OpenSSH', ['comment' => $comment]),
            'fingerprint' => self::fingerprint($public),
        ];
    }

    /** SHA256:<base64> fingerprint of an OpenSSH public key line (as printed by ssh-keygen -l). */
    public static function fingerprint(string $publicKey): string
    {
        $blob = base64_decode(explode(' ', trim($publicKey))[1] ?? '', true) ?: '';

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }
}
