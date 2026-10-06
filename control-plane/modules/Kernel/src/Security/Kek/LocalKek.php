<?php

namespace Falak\Kernel\Security\Kek;

use Falak\Kernel\Security\Aead;
use Falak\Kernel\Security\DecryptionFailed;
use Falak\Kernel\Security\KeyEncryptionKey;
use Falak\Kernel\Security\KeyUnavailable;

/**
 * KEK from a file of 32 random bytes (default /opt/falak/secrets/kek), readable by the app user only.
 * Data keys are wrapped with AES-256-GCM; the context (key id, purpose) is the AAD.
 */
final class LocalKek implements KeyEncryptionKey
{
    public const PROVIDER = 'local';

    private const PREFIX = 'lk1:';

    private ?string $key = null;

    public function __construct(private readonly string $path) {}

    /** The id of a KEK, from its bytes: what falak-ctl prints, and what data keys record. */
    public static function fingerprint(#[\SensitiveParameter] string $key): string
    {
        return substr(hash('sha256', 'falak-kek-id:'.$key), 0, 16);
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function id(): string
    {
        return self::fingerprint($this->key());
    }

    public function wrap(#[\SensitiveParameter] string $dataKey, array $context): string
    {
        return self::PREFIX.base64_encode(Aead::seal($this->key(), $dataKey, self::aad($context)));
    }

    public function unwrap(string $wrapped, array $context): string
    {
        if (! str_starts_with($wrapped, self::PREFIX)) {
            throw new DecryptionFailed('The data key was not wrapped by a local KEK.');
        }

        $raw = base64_decode(substr($wrapped, strlen(self::PREFIX)), true);

        if ($raw === false) {
            throw new DecryptionFailed('The wrapped data key is not valid base64.');
        }

        return Aead::open($this->key(), $raw, self::aad($context));
    }

    /**
     * Read and check the file once. Loose permissions are refused: anyone who can read the KEK can read
     * every secret, given a database dump.
     */
    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $hint = 'Falak cannot read or write secrets without it (docs/INSTALL.md, "Encryption keys").';
        clearstatcache(true, $this->path);

        if (! is_file($this->path)) {
            throw new KeyUnavailable("The key-encryption key {$this->path} does not exist (FALAK_KEK_PATH). {$hint}");
        }

        if (! is_readable($this->path)) {
            throw new KeyUnavailable("The key-encryption key {$this->path} is not readable by this user. {$hint}");
        }

        $mode = fileperms($this->path) & 0777;

        if (($mode & 0077) !== 0) {
            throw new KeyUnavailable(sprintf('The key-encryption key %s has mode %04o: it must be readable by its owner only (0400 or 0600).', $this->path, $mode));
        }

        $key = (string) file_get_contents($this->path);

        if (strlen($key) !== Aead::KEY_BYTES) {
            throw new KeyUnavailable(sprintf('The key-encryption key %s must be exactly %d random bytes (it has %d).', $this->path, Aead::KEY_BYTES, strlen($key)));
        }

        return $this->key = $key;
    }

    /**
     * @param  array<string, string>  $context
     */
    private static function aad(array $context): string
    {
        ksort($context, SORT_STRING);

        return 'falak-kek:'.json_encode($context, JSON_UNESCAPED_SLASHES);
    }
}
