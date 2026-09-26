<?php

namespace Kiln\SourceControl\Contracts\Data;

use Kiln\SourceControl\Contracts\SourceControlGateway;

/**
 * A per-repository deploy key. The private key never leaves SourceControl except through
 * {@see SourceControlGateway::checkoutCredentials()}.
 */
final readonly class DeployKeyData
{
    /**
     * @param  bool  $installed  true when the public key was registered at the provider through its API;
     *                           false means the user must add it manually (custom git / missing API scope)
     */
    public function __construct(
        public string $id,
        public string $connectionId,
        public string $repository,
        public string $publicKey,
        public string $fingerprint,
        public bool $installed,
        public ?string $installError = null,
    ) {}
}
