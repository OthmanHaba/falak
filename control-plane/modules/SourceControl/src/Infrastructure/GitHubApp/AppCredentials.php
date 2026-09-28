<?php

namespace Kiln\SourceControl\Infrastructure\GitHubApp;

use SensitiveParameter;

/**
 * Everything needed to act as one GitHub App: either the operator's env-configured app (`key` = "env") or an
 * app registered through the manifest flow (`key` = its source_control_github_apps id). Contains secrets.
 */
final readonly class AppCredentials
{
    public const ENV = 'env';

    public function __construct(
        public string $key,
        public string $appId,
        public string $slug,
        #[SensitiveParameter] public string $privateKey,
        #[SensitiveParameter] public ?string $webhookSecret = null,
        public ?string $name = null,
        public ?string $ownerLogin = null,
        public ?string $ownerType = null,
        public ?string $htmlUrl = null,
        public ?string $organizationId = null,
    ) {}

    public function fromEnv(): bool
    {
        return $this->key === self::ENV;
    }

    /** Whether the app can be installed through Kiln (installation URLs need the slug). */
    public function installable(): bool
    {
        return $this->slug !== '';
    }
}
