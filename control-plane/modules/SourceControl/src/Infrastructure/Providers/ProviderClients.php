<?php

namespace Falak\SourceControl\Infrastructure\Providers;

use Illuminate\Contracts\Container\Container;
use Falak\SourceControl\Contracts\ProviderType;

class ProviderClients
{
    public function __construct(private readonly Container $container) {}

    public function for(ProviderType $provider): ProviderClient
    {
        return $this->container->make(match ($provider) {
            ProviderType::GitHub => GitHubClient::class,
            ProviderType::GitLab => GitLabClient::class,
            ProviderType::Bitbucket => BitbucketClient::class,
            ProviderType::Custom => CustomGitClient::class,
        });
    }
}
