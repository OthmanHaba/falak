<?php

namespace Kiln\SourceControl\Contracts\Data;

use SensitiveParameter;

/**
 * Everything a builder needs to clone a repository. Contains secrets: never log or persist it.
 */
final readonly class CheckoutCredentials
{
    /**
     * @param  string  $url  clone URL (SSH when a deploy key is used, HTTPS with token otherwise)
     * @param  ?string  $sshPrivateKey  OpenSSH private key for $url
     * @param  ?string  $httpsUsername  basic-auth user for HTTPS clones
     * @param  ?string  $httpsPassword  token / app password for HTTPS clones
     * @param  ?string  $knownHosts  known_hosts line(s) for the git host, when known
     */
    public function __construct(
        public string $url,
        #[SensitiveParameter] public ?string $sshPrivateKey = null,
        public ?string $httpsUsername = null,
        #[SensitiveParameter] public ?string $httpsPassword = null,
        public ?string $knownHosts = null,
    ) {}

    public function usesSsh(): bool
    {
        return $this->sshPrivateKey !== null;
    }
}
